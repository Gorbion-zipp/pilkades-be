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
use App\Services\ElectionResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveCountController extends Controller
{
    public function __construct(private readonly ElectionResultService $resultService) {}

    public function index(Request $request): JsonResponse
    {
        $periods = ElectionPeriod::orderBy('year', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($periods->isEmpty() || Election::count() === 0) {
            return $this->legacyLiveCount();
        }

        $requestedPeriodId = $request->query('period_id');
        $selectedPeriod = $requestedPeriodId
            ? $periods->first(fn (ElectionPeriod $p) => (string) $p->_id === $requestedPeriodId)
            : ($periods->first(fn (ElectionPeriod $p) => $p->status === ElectionPeriod::STATUS_ACTIVE) ?? $periods->first());

        $elections = $selectedPeriod
            ? Election::where('period_id', (string) $selectedPeriod->_id)->orderBy('created_at')->get()
            : collect();

        if ($elections->isEmpty()) {
            return response()->json([
                'mode' => 'multi_election',
                'periods' => $periods->map(fn (ElectionPeriod $p) => $p->toApi())->all(),
                'selected_period_id' => $selectedPeriod ? (string) $selectedPeriod->_id : null,
                'elections' => [],
                'selected_election' => null,
                'selected_round' => null,
                'stats' => ['total_dpt' => 0, 'total_voted' => 0, 'total_unvoted' => 0, 'percentage' => 0],
                'candidates' => [],
                'evaluation' => null,
            ]);
        }

        $regionMap = Region::all()->keyBy(fn (Region $r) => (string) $r->_id)->all();
        $electionIds = $elections->map(fn (Election $e) => (string) $e->_id)->all();
        $allRounds = ElectionRound::whereIn('election_id', $electionIds)->orderBy('round_number')->get();
        $roundsByElection = [];
        foreach ($allRounds as $r) {
            $roundsByElection[(string) $r->election_id][] = $r->toApi();
        }

        $requestedElectionId = $request->query('election_id');
        $selectedElection = $requestedElectionId
            ? $elections->first(fn (Election $e) => (string) $e->_id === $requestedElectionId)
            : $elections->first();

        if (! $selectedElection) {
            $selectedElection = $elections->first();
        }

        $electionRounds = $allRounds->where('election_id', (string) $selectedElection->_id)->values();
        $requestedRoundId = $request->query('round_id');
        $selectedRound = $requestedRoundId
            ? $electionRounds->first(fn (ElectionRound $r) => (string) $r->_id === $requestedRoundId)
            : ($electionRounds->first(fn (ElectionRound $r) => $r->status === ElectionRound::STATUS_ACTIVE) ?? $electionRounds->last());

        $totalDpt = $this->countEligibleVoters($selectedElection);
        $totalVoted = $selectedRound
            ? VoterBallot::where('election_round_id', (string) $selectedRound->_id)->count()
            : 0;

        $evaluation = $selectedRound
            ? $this->resultService->evaluateRound($selectedElection, $selectedRound)
            : null;

        return response()->json([
            'mode' => 'multi_election',
            'periods' => $periods->map(fn (ElectionPeriod $p) => $p->toApi())->all(),
            'selected_period_id' => $selectedPeriod ? (string) $selectedPeriod->_id : null,
            'elections' => $elections->map(fn (Election $e) => $e->toApi($regionMap, $roundsByElection[(string) $e->_id] ?? []))->all(),
            'selected_election' => $selectedElection->toApi($regionMap, $roundsByElection[(string) $selectedElection->_id] ?? []),
            'selected_round' => $selectedRound?->toApi(),
            'stats' => [
                'total_dpt' => $totalDpt,
                'total_voted' => $totalVoted,
                'total_unvoted' => max(0, $totalDpt - $totalVoted),
                'percentage' => $totalDpt > 0 ? round(($totalVoted / $totalDpt) * 100, 1) : 0,
            ],
            'candidates' => $evaluation['candidates'] ?? [],
            'evaluation' => $evaluation,
        ]);
    }

    private function countEligibleVoters(Election $election): int
    {
        return match ($election->position_type) {
            Election::POSITION_BUPATI => Voter::where('kabupaten_id', (string) $election->region_id)->count(),
            Election::POSITION_DPRD => Voter::whereIn('kecamatan_id', $election->dapil_region_ids ?? [])->count(),
            Election::POSITION_PERBEKEL => Voter::where('kelurahan_id', (string) $election->region_id)->count(),
            default => Voter::count(),
        };
    }

    private function legacyLiveCount(): JsonResponse
    {
        $totalDpt = Voter::count();
        $totalVoted = Voter::where('has_voted', true)->count();

        $candidates = Candidate::orderBy('candidate_number')->get()->map(function (Candidate $candidate) {
            return [
                'id' => (string) $candidate->_id,
                'candidate_number' => $candidate->candidate_number,
                'name' => $candidate->name,
                'votes' => Vote::where('candidate_id', (string) $candidate->_id)->count(),
            ];
        });

        return response()->json([
            'mode' => 'legacy',
            'periods' => [],
            'elections' => [],
            'stats' => [
                'total_dpt' => $totalDpt,
                'total_voted' => $totalVoted,
                'total_unvoted' => max(0, $totalDpt - $totalVoted),
                'percentage' => $totalDpt > 0 ? round(($totalVoted / $totalDpt) * 100, 1) : 0,
            ],
            'candidates' => $candidates->all(),
        ]);
    }
}
