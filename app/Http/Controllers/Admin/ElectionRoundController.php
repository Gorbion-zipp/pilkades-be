<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\ElectionResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ElectionRoundController extends Controller
{
    public function __construct(private readonly ElectionResultService $resultService) {}
    public function store(Request $request, string $electionId): JsonResponse
    {
        $election = Election::find($electionId);

        if (! $election) {
            return response()->json(['message' => 'Data pemilihan tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'start_datetime' => 'nullable|date',
            'end_datetime' => 'nullable|date|after_or_equal:start_datetime',
            'advancing_candidate_ids' => 'nullable|array',
            'advancing_candidate_ids.*' => 'string',
        ]);

        $lastRound = ElectionRound::where('election_id', (string) $election->_id)
            ->orderBy('round_number', 'desc')
            ->first();

        $nextNumber = $lastRound ? ((int) $lastRound->round_number + 1) : 1;

        $advancingIds = array_values(array_unique($data['advancing_candidate_ids'] ?? []));
        if ($nextNumber > 1 && count($advancingIds) < 2) {
            return response()->json([
                'message' => 'Putaran lanjutan minimal harus memilih 2 paslon yang lolos.',
            ], 422);
        }

        if ($advancingIds !== []) {
            $validCount = Candidate::where('election_id', (string) $election->_id)
                ->whereIn('_id', $advancingIds)
                ->count();
            if ($validCount !== count($advancingIds)) {
                return response()->json([
                    'message' => 'Terdapat paslon yang tidak terdaftar pada pemilihan ini.',
                ], 422);
            }
        }

        $round = ElectionRound::create([
            'election_id' => (string) $election->_id,
            'round_number' => $nextNumber,
            'status' => ElectionRound::STATUS_DRAFT,
            'start_datetime' => $data['start_datetime'] ?? null,
            'end_datetime' => $data['end_datetime'] ?? null,
            'advancing_candidate_ids' => $advancingIds,
        ]);

        return response()->json([
            'message' => "Putaran {$nextNumber} berhasil dibuat.",
            'round' => $round->toApi(0),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $round = ElectionRound::find($id);

        if (! $round) {
            return response()->json(['message' => 'Data putaran tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'start_datetime' => 'nullable|date',
            'end_datetime' => 'nullable|date|after_or_equal:start_datetime',
            'advancing_candidate_ids' => 'nullable|array',
            'advancing_candidate_ids.*' => 'string',
            'status' => ['nullable', Rule::in(ElectionRound::STATUSES)],
        ]);

        $updates = [];
        if (array_key_exists('start_datetime', $data)) {
            $updates['start_datetime'] = $data['start_datetime'];
        }
        if (array_key_exists('end_datetime', $data)) {
            $updates['end_datetime'] = $data['end_datetime'];
        }
        if (isset($data['advancing_candidate_ids'])) {
            $advancingIds = array_values(array_unique($data['advancing_candidate_ids']));
            if ($advancingIds !== []) {
                $validCount = Candidate::where('election_id', (string) $round->election_id)
                    ->whereIn('_id', $advancingIds)
                    ->count();
                if ($validCount !== count($advancingIds)) {
                    return response()->json([
                        'message' => 'Terdapat paslon yang tidak terdaftar pada pemilihan ini.',
                    ], 422);
                }
            }
            $updates['advancing_candidate_ids'] = $advancingIds;
        }
        if (isset($data['status'])) {
            $updates['status'] = $data['status'];
        }

        $round->update($updates);

        return response()->json([
            'message' => 'Data putaran berhasil diperbarui.',
            'round' => $round->fresh()->toApi(),
        ]);
    }

    public function activate(string $id): JsonResponse
    {
        $round = ElectionRound::find($id);

        if (! $round) {
            return response()->json(['message' => 'Data putaran tidak ditemukan.'], 404);
        }

        $election = Election::find($round->election_id);

        if (! $election) {
            return response()->json(['message' => 'Data pemilihan induk tidak ditemukan.'], 404);
        }

        $candCount = Candidate::where('election_id', (string) $election->_id)->count();
        if ($candCount < 1) {
            return response()->json([
                'message' => 'Pemilihan belum memiliki kandidat/paslon.',
            ], 422);
        }

        ElectionRound::where('election_id', (string) $election->_id)
            ->where('status', ElectionRound::STATUS_ACTIVE)
            ->where('_id', '!=', (string) $round->_id)
            ->update(['status' => ElectionRound::STATUS_CLOSED]);

        $round->update(['status' => ElectionRound::STATUS_ACTIVE]);
        $election->update(['status' => Election::STATUS_ACTIVE]);

        return response()->json([
            'message' => "Putaran {$round->round_number} berhasil diaktifkan.",
            'round' => $round->fresh()->toApi(),
        ]);
    }

    public function evaluation(string $id): JsonResponse
    {
        $round = ElectionRound::find($id);

        if (! $round) {
            return response()->json(['message' => 'Data putaran tidak ditemukan.'], 404);
        }

        $election = Election::find($round->election_id);

        if (! $election) {
            return response()->json(['message' => 'Data pemilihan induk tidak ditemukan.'], 404);
        }

        return response()->json([
            'round' => $round->toApi(),
            'evaluation' => $this->resultService->evaluateRound($election, $round),
        ]);
    }

    public function close(string $id): JsonResponse
    {
        $round = ElectionRound::find($id);

        if (! $round) {
            return response()->json(['message' => 'Data putaran tidak ditemukan.'], 404);
        }

        $election = Election::find($round->election_id);

        if (! $election) {
            $round->update(['status' => ElectionRound::STATUS_CLOSED]);

            return response()->json([
                'message' => "Putaran {$round->round_number} telah ditutup.",
                'round' => $round->fresh()->toApi(),
            ]);
        }

        $evaluation = $this->resultService->evaluateRound($election, $round);
        $nextStatus = $evaluation['status_suggestion'];

        $round->update(['status' => $nextStatus]);

        if ($nextStatus === ElectionRound::STATUS_COMPLETED) {
            $election->update(['status' => Election::STATUS_COMPLETED]);
        }

        $msg = $nextStatus === ElectionRound::STATUS_NEEDS_RUNOFF
            ? "Putaran {$round->round_number} ditutup. Belum ada paslon memenuhi ambang batas, perlu putaran berikutnya."
            : "Putaran {$round->round_number} selesai dan hasil akhir telah ditetapkan.";

        return response()->json([
            'message' => $msg,
            'round' => $round->fresh()->toApi(),
            'evaluation' => $evaluation,
        ]);
    }
}
