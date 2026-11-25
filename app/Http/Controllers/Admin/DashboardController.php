<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\ElectionPeriod;
use App\Models\Region;
use App\Models\Vote;
use App\Models\Voter;
use App\Models\VoterBallot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $totalDpt = Voter::count();
        $totalVoted = Voter::where('has_voted', true)->count();

        $voters = Voter::orderBy('created_at', 'desc')->get();

        $candidateQuery = Candidate::orderBy('candidate_number');
        if ($electionId = $request->query('election_id')) {
            $candidateQuery->where('election_id', $electionId);
        }
        $candidates = $candidateQuery->get();

        $regionMap = Region::all()->keyBy(fn (Region $r) => (string) $r->_id)->all();
        $activePeriod = ElectionPeriod::activePeriod();

        return response()->json([
            'stats' => [
                'total_dpt' => $totalDpt,
                'total_voted' => $totalVoted,
                'total_unvoted' => max(0, $totalDpt - $totalVoted),
                'total_candidates' => $candidates->count(),
            ],
            'active_period' => $activePeriod?->toApi(),
            'voters' => $voters->map(fn (Voter $voter) => $voter->toApi($regionMap))->all(),
            'candidates' => $candidates
                ->map(fn (Candidate $candidate) => $candidate->toApi(
                    Vote::where('candidate_id', (string) $candidate->_id)->count()
                ))
                ->all(),
        ]);
    }

    /**
     * Hapus semua suara dan kembalikan status seluruh DPT ke belum memilih.
     */
    public function resetElection(): JsonResponse
    {
        Vote::truncate();
        VoterBallot::truncate();
        Voter::query()->update(['has_voted' => false]);

        return response()->json(['message' => 'Data hasil pemilihan berhasil direset total.']);
    }
}
