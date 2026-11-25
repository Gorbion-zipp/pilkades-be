<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionPeriod;
use App\Models\ElectionRound;
use App\Models\Region;
use App\Models\Vote;
use App\Models\Voter;
use App\Models\VoterBallot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoteController extends Controller
{
    public function candidates(): JsonResponse
    {
        $candidates = Candidate::orderBy('candidate_number')->get();

        return response()->json([
            'candidates' => $candidates->map(fn (Candidate $candidate) => $candidate->toApi())->all(),
        ]);
    }

    /**
     * Mengembalikan daftar pemilihan aktif yang diikuti pemilih berdasarkan domisili wilayahnya.
     */
    public function ballots(Request $request): JsonResponse
    {
        /** @var Voter $voter */
        $voter = $request->attributes->get('auth_user');
        $activePeriod = ElectionPeriod::activePeriod();

        if (! $activePeriod) {
            return $this->legacyBallotsResponse($voter);
        }

        $elections = Election::where('period_id', (string) $activePeriod->_id)->get();
        if ($elections->isEmpty()) {
            return $this->legacyBallotsResponse($voter);
        }

        $electionMap = $elections->keyBy(fn (Election $e) => (string) $e->_id)->all();
        $activeRounds = ElectionRound::whereIn('election_id', array_keys($electionMap))
            ->where('status', ElectionRound::STATUS_ACTIVE)
            ->get();

        $regionMap = Region::all()->keyBy(fn (Region $r) => (string) $r->_id)->all();
        $votedRoundIds = VoterBallot::where('voter_id', (string) $voter->_id)
            ->pluck('election_round_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $items = [];
        foreach ($activeRounds as $round) {
            $election = $electionMap[(string) $round->election_id] ?? null;
            if (! $election || ! $this->isVoterEligible($voter, $election)) {
                continue;
            }

            $candQuery = Candidate::where('election_id', (string) $election->_id)
                ->orderBy('candidate_number');
            $advancing = is_array($round->advancing_candidate_ids) ? $round->advancing_candidate_ids : [];
            if ((int) $round->round_number > 1 && $advancing !== []) {
                $candQuery->whereIn('_id', $advancing);
            }

            $candidates = $candQuery->get()->map(fn (Candidate $c) => $c->toApi())->all();
            if ($candidates === []) {
                continue;
            }

            $region = $regionMap[(string) $election->region_id] ?? null;

            $items[] = [
                'election_id' => (string) $election->_id,
                'election_round_id' => (string) $round->_id,
                'name' => $election->name,
                'position_type' => $election->position_type,
                'position_label' => Election::positionLabel((string) $election->position_type),
                'election_mode' => $election->election_mode,
                'dapil_name' => $election->dapil_name,
                'region_name' => $region?->name,
                'round_number' => (int) $round->round_number,
                'has_voted' => in_array((string) $round->_id, $votedRoundIds, true),
                'candidates' => $candidates,
            ];
        }

        return response()->json([
            'mode' => 'multi_election',
            'period' => $activePeriod->toApi(),
            'elections' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'candidate_id' => 'required|string',
            'election_round_id' => 'nullable|string',
        ]);

        /** @var Voter $voter */
        $voter = $request->attributes->get('auth_user');

        if (! empty($data['election_round_id'])) {
            return $this->storeRoundVote($voter, $data['election_round_id'], $data['candidate_id']);
        }

        if ($voter->has_voted) {
            return response()->json(['message' => 'Anda sudah menggunakan hak pilih!'], 422);
        }

        $candidate = Candidate::find($data['candidate_id']);

        if (! $candidate) {
            return response()->json(['message' => 'Pasangan calon tidak ditemukan.'], 422);
        }

        Vote::create(['candidate_id' => (string) $candidate->_id]);

        $voter->has_voted = true;
        $voter->save();

        return response()->json(['message' => 'Suara Anda berhasil dikirim.']);
    }

    private function storeRoundVote(Voter $voter, string $roundId, string $candidateId): JsonResponse
    {
        $round = ElectionRound::find($roundId);
        if (! $round || $round->status !== ElectionRound::STATUS_ACTIVE) {
            return response()->json(['message' => 'Putaran pemilihan tidak ditemukan atau sedang tidak aktif.'], 422);
        }

        $election = Election::find($round->election_id);
        if (! $election || ! $this->isVoterEligible($voter, $election)) {
            return response()->json(['message' => 'Anda tidak terdaftar dalam wilayah pemilihan ini.'], 403);
        }

        $alreadyVoted = VoterBallot::where('voter_id', (string) $voter->_id)
            ->where('election_round_id', (string) $round->_id)
            ->exists();

        if ($alreadyVoted) {
            return response()->json(['message' => 'Anda sudah menggunakan hak pilih pada pemilihan ini!'], 422);
        }

        $candidate = Candidate::find($candidateId);
        if (! $candidate || (string) $candidate->election_id !== (string) $election->_id) {
            return response()->json(['message' => 'Pasangan calon tidak ditemukan pada pemilihan ini.'], 422);
        }

        $advancing = is_array($round->advancing_candidate_ids) ? $round->advancing_candidate_ids : [];
        if ((int) $round->round_number > 1 && $advancing !== [] && ! in_array((string) $candidate->_id, $advancing, true)) {
            return response()->json(['message' => 'Pasangan calon ini tidak lolos ke putaran berikutnya.'], 422);
        }

        Vote::create([
            'election_id' => (string) $election->_id,
            'election_round_id' => (string) $round->_id,
            'candidate_id' => (string) $candidate->_id,
        ]);

        VoterBallot::create([
            'voter_id' => (string) $voter->_id,
            'election_id' => (string) $election->_id,
            'election_round_id' => (string) $round->_id,
            'voted_at' => now()->toIso8601String(),
        ]);

        // Cek apakah semua pemilihan aktif untuk pemilih ini sudah selesai dicoblos
        $this->syncVoterOverallStatus($voter);

        return response()->json(['message' => 'Suara Anda berhasil dikirim.']);
    }

    private function isVoterEligible(Voter $voter, Election $election): bool
    {
        return match ($election->position_type) {
            Election::POSITION_BUPATI => (string) $voter->kabupaten_id !== '' && (string) $voter->kabupaten_id === (string) $election->region_id,
            Election::POSITION_DPRD => (string) $voter->kecamatan_id !== '' && in_array((string) $voter->kecamatan_id, $election->dapil_region_ids ?? [], true),
            Election::POSITION_PERBEKEL => (string) $voter->kelurahan_id !== '' && (string) $voter->kelurahan_id === (string) $election->region_id,
            default => false,
        };
    }

    private function syncVoterOverallStatus(Voter $voter): void
    {
        $activePeriod = ElectionPeriod::activePeriod();
        if (! $activePeriod) {
            return;
        }

        $elections = Election::where('period_id', (string) $activePeriod->_id)->get();
        $eligibleIds = $elections
            ->filter(fn (Election $e) => $this->isVoterEligible($voter, $e))
            ->map(fn (Election $e) => (string) $e->_id)
            ->values()
            ->all();

        if ($eligibleIds === []) {
            return;
        }

        $activeRoundIds = ElectionRound::whereIn('election_id', $eligibleIds)
            ->where('status', ElectionRound::STATUS_ACTIVE)
            ->get(['_id'])
            ->map(fn ($r) => (string) $r->_id)
            ->all();

        if ($activeRoundIds === []) {
            return;
        }

        $votedCount = VoterBallot::where('voter_id', (string) $voter->_id)
            ->whereIn('election_round_id', $activeRoundIds)
            ->count();

        $voter->has_voted = $votedCount >= count($activeRoundIds);
        $voter->save();
    }

    private function legacyBallotsResponse(Voter $voter): JsonResponse
    {
        $candidates = Candidate::whereNull('election_id')
            ->orderBy('candidate_number')
            ->get();

        if ($candidates->isEmpty()) {
            return response()->json([
                'mode' => 'multi_election',
                'period' => null,
                'elections' => [],
            ]);
        }

        return response()->json([
            'mode' => 'legacy',
            'period' => null,
            'elections' => [[
                'election_id' => 'legacy',
                'election_round_id' => null,
                'name' => 'Pemilihan Kepala Desa',
                'position_type' => Election::POSITION_PERBEKEL,
                'position_label' => 'Perbekel / Kepala Desa',
                'election_mode' => Election::MODE_SINGLE_WINNER,
                'dapil_name' => null,
                'region_name' => null,
                'round_number' => 1,
                'has_voted' => (bool) $voter->has_voted,
                'candidates' => $candidates->map(fn (Candidate $c) => $c->toApi())->all(),
            ]],
        ]);
    }
}
