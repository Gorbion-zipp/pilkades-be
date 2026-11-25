<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionPeriod;
use App\Models\ElectionRound;
use App\Models\Region;
use App\Models\Vote;
use App\Models\VoterBallot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ElectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Election::query()->orderBy('created_at', 'desc');

        $periodId = $request->query('period_id');
        if ($periodId && $periodId !== 'all') {
            $query->where('period_id', $periodId);
        } elseif (! $periodId) {
            $active = ElectionPeriod::activePeriod();
            if ($active) {
                $query->where('period_id', (string) $active->_id);
            }
        }

        if ($positionType = $request->query('position_type')) {
            $query->where('position_type', $positionType);
        }

        if ($regionId = $request->query('region_id')) {
            $query->where('region_id', $regionId);
        }

        $elections = $query->get();
        $regionMap = Region::all()->keyBy(fn (Region $r) => (string) $r->_id)->all();

        $electionIds = $elections->map(fn (Election $e) => (string) $e->_id)->all();
        $roundsByElection = [];
        if ($electionIds !== []) {
            foreach (ElectionRound::whereIn('election_id', $electionIds)->orderBy('round_number')->get() as $round) {
                $roundsByElection[(string) $round->election_id][] = $round->toApi();
            }
        }

        $candidateCounts = [];
        if ($electionIds !== []) {
            foreach (Candidate::whereIn('election_id', $electionIds)->get(['election_id']) as $cand) {
                $eid = (string) $cand->election_id;
                $candidateCounts[$eid] = ($candidateCounts[$eid] ?? 0) + 1;
            }
        }

        return response()->json([
            'elections' => $elections->map(function (Election $e) use ($regionMap, $roundsByElection, $candidateCounts) {
                $id = (string) $e->_id;

                return $e->toApi(
                    $regionMap,
                    $roundsByElection[$id] ?? [],
                    $candidateCounts[$id] ?? 0
                );
            })->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $election = Election::find($id);

        if (! $election) {
            return response()->json(['message' => 'Data pemilihan tidak ditemukan.'], 404);
        }

        $regionMap = Region::all()->keyBy(fn (Region $r) => (string) $r->_id)->all();
        $rounds = ElectionRound::where('election_id', (string) $election->_id)
            ->orderBy('round_number')
            ->get();

        $roundsData = $rounds->map(function (ElectionRound $r) {
            $voteCount = Vote::where('election_round_id', (string) $r->_id)->count();

            return $r->toApi($voteCount);
        })->all();

        $candidates = Candidate::where('election_id', (string) $election->_id)
            ->orderBy('candidate_number')
            ->get();

        $latestRound = $rounds->last();
        $candidatesData = $candidates->map(function (Candidate $c) use ($latestRound) {
            $voteQuery = Vote::where('candidate_id', (string) $c->_id);
            if ($latestRound) {
                $voteQuery->where('election_round_id', (string) $latestRound->_id);
            }

            return $c->toApi($voteQuery->count());
        })->all();

        return response()->json([
            'election' => $election->toApi($regionMap, $roundsData, count($candidatesData)),
            'candidates' => $candidatesData,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_id' => 'nullable|string',
            'name' => 'required|string|max:255',
            'position_type' => ['required', Rule::in(Election::POSITIONS)],
            'region_id' => 'required|string',
            'win_threshold_percent' => 'nullable|numeric|min:0|max:100',
            'seat_count' => 'nullable|integer|min:1|max:100',
            'dapil_name' => 'nullable|string|max:255',
            'dapil_region_ids' => 'nullable|array',
            'dapil_region_ids.*' => 'string',
            'start_datetime' => 'nullable|date',
            'end_datetime' => 'nullable|date|after_or_equal:start_datetime',
        ]);

        // Resolve period
        $period = ! empty($data['period_id'])
            ? ElectionPeriod::find($data['period_id'])
            : ElectionPeriod::activePeriod();

        if (! $period) {
            return response()->json([
                'message' => 'Periode pemilihan tidak ditemukan atau belum ada periode aktif.',
            ], 422);
        }

        // Validasi wilayah sesuai tingkat jabatan
        $expectedLevel = Election::expectedLevelForPosition($data['position_type']);
        $region = Region::find($data['region_id']);

        if (! $region || $region->level !== $expectedLevel) {
            $label = Region::levelLabel((string) $expectedLevel);

            return response()->json([
                'message' => "Jabatan ini harus memilih wilayah tingkat {$label}.",
            ], 422);
        }

        $mode = Election::defaultModeForPosition($data['position_type']);

        // Validasi khusus DPRD Kab/Kota (multi_seat + Dapil)
        $dapilIds = [];
        $seatCount = null;
        $threshold = null;

        if ($mode === Election::MODE_MULTI_SEAT) {
            $seatCount = (int) ($data['seat_count'] ?? 0);
            if ($seatCount < 1) {
                return response()->json([
                    'message' => 'Pemilihan DPRD Kab/Kota wajib menentukan jumlah kursi minimal 1.',
                ], 422);
            }

            $dapilIds = array_values(array_unique($data['dapil_region_ids'] ?? []));
            if ($dapilIds === []) {
                return response()->json([
                    'message' => 'Pemilihan DPRD Kab/Kota wajib memilih minimal 1 Kecamatan sebagai Dapil.',
                ], 422);
            }

            $validKecamatanCount = Region::whereIn('_id', $dapilIds)
                ->where('level', Region::LEVEL_KECAMATAN)
                ->where('kabupaten_id', (string) $region->_id)
                ->count();

            if ($validKecamatanCount !== count($dapilIds)) {
                return response()->json([
                    'message' => 'Seluruh wilayah Dapil harus berupa Kecamatan yang berada di bawah Kabupaten/Kota terpilih.',
                ], 422);
            }
        } else {
            $threshold = isset($data['win_threshold_percent'])
                ? (float) $data['win_threshold_percent']
                : 50.0;
        }

        $election = Election::create([
            'period_id' => (string) $period->_id,
            'name' => trim($data['name']),
            'position_type' => $data['position_type'],
            'level' => $expectedLevel,
            'region_id' => (string) $region->_id,
            'election_mode' => $mode,
            'win_threshold_percent' => $threshold,
            'seat_count' => $seatCount,
            'dapil_name' => $mode === Election::MODE_MULTI_SEAT ? ($data['dapil_name'] ?? null) : null,
            'dapil_region_ids' => $dapilIds,
            'status' => Election::STATUS_DRAFT,
        ]);

        // Otomatis buat Putaran 1 dalam status draft
        $round1 = ElectionRound::create([
            'election_id' => (string) $election->_id,
            'round_number' => 1,
            'status' => ElectionRound::STATUS_DRAFT,
            'start_datetime' => $data['start_datetime'] ?? null,
            'end_datetime' => $data['end_datetime'] ?? null,
            'advancing_candidate_ids' => [],
        ]);

        $regionMap = Region::all()->keyBy(fn (Region $r) => (string) $r->_id)->all();

        return response()->json([
            'message' => 'Pemilihan berhasil dibuat beserta Putaran 1.',
            'election' => $election->toApi($regionMap, [$round1->toApi(0)], 0),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $election = Election::find($id);

        if (! $election) {
            return response()->json(['message' => 'Data pemilihan tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'win_threshold_percent' => 'nullable|numeric|min:0|max:100',
            'seat_count' => 'nullable|integer|min:1|max:100',
            'dapil_name' => 'nullable|string|max:255',
            'dapil_region_ids' => 'nullable|array',
            'dapil_region_ids.*' => 'string',
            'status' => ['nullable', Rule::in(Election::STATUSES)],
        ]);

        $updates = [
            'name' => trim($data['name']),
            'status' => $data['status'] ?? $election->status,
        ];

        if ($election->election_mode === Election::MODE_MULTI_SEAT) {
            if (isset($data['seat_count'])) {
                $updates['seat_count'] = (int) $data['seat_count'];
            }
            if (array_key_exists('dapil_name', $data)) {
                $updates['dapil_name'] = $data['dapil_name'];
            }
            if (isset($data['dapil_region_ids'])) {
                $dapilIds = array_values(array_unique($data['dapil_region_ids']));
                if ($dapilIds === []) {
                    return response()->json(['message' => 'Dapil wajib memilih minimal 1 Kecamatan.'], 422);
                }
                $validCount = Region::whereIn('_id', $dapilIds)
                    ->where('level', Region::LEVEL_KECAMATAN)
                    ->where('kabupaten_id', (string) $election->region_id)
                    ->count();
                if ($validCount !== count($dapilIds)) {
                    return response()->json(['message' => 'Kecamatan Dapil tidak sesuai Kabupaten induk.'], 422);
                }
                $updates['dapil_region_ids'] = $dapilIds;
            }
        } elseif (array_key_exists('win_threshold_percent', $data) && $data['win_threshold_percent'] !== null) {
            $updates['win_threshold_percent'] = (float) $data['win_threshold_percent'];
        }

        $election->update($updates);

        $regionMap = Region::all()->keyBy(fn (Region $r) => (string) $r->_id)->all();
        $rounds = ElectionRound::where('election_id', (string) $election->_id)
            ->orderBy('round_number')
            ->get()
            ->map(fn (ElectionRound $r) => $r->toApi())
            ->all();
        $candCount = Candidate::where('election_id', (string) $election->_id)->count();

        return response()->json([
            'message' => 'Data pemilihan berhasil diperbarui.',
            'election' => $election->fresh()->toApi($regionMap, $rounds, $candCount),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $election = Election::find($id);

        if (! $election) {
            return response()->json(['message' => 'Data pemilihan tidak ditemukan.'], 404);
        }

        if (Vote::where('election_id', (string) $election->_id)->exists()) {
            return response()->json([
                'message' => 'Pemilihan tidak dapat dihapus karena sudah memiliki suara masuk.',
            ], 422);
        }

        VoterBallot::where('election_id', (string) $election->_id)->delete();
        ElectionRound::where('election_id', (string) $election->_id)->delete();
        Candidate::where('election_id', (string) $election->_id)->delete();
        $election->delete();

        return response()->json(['message' => 'Data pemilihan berhasil dihapus.']);
    }
}
