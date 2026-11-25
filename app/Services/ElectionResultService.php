<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Models\Vote;

class ElectionResultService
{
    /**
     * Hitung hasil perolehan suara pada satu putaran pemilihan:
     * - single_winner: evaluasi ambang batas kemenangan (win_threshold_percent)
     * - multi_seat: alokasi kursi menggunakan metode Sainte-Laguë (pembagi 1, 3, 5, 7, ...)
     *
     * @return array<string, mixed>
     */
    public function evaluateRound(Election $election, ElectionRound $round): array
    {
        $candidatesQuery = Candidate::where('election_id', (string) $election->_id)
            ->orderBy('candidate_number');

        $advancing = is_array($round->advancing_candidate_ids) ? $round->advancing_candidate_ids : [];
        if ((int) $round->round_number > 1 && $advancing !== []) {
            $candidatesQuery->whereIn('_id', $advancing);
        }

        $candidates = $candidatesQuery->get();

        $votes = Vote::where('election_round_id', (string) $round->_id)->get(['candidate_id']);
        $voteCounts = [];
        foreach ($votes as $vote) {
            $cid = (string) $vote->candidate_id;
            $voteCounts[$cid] = ($voteCounts[$cid] ?? 0) + 1;
        }

        $totalVotes = array_sum($voteCounts);

        $candidateResults = $candidates->map(function (Candidate $c) use ($voteCounts, $totalVotes) {
            $count = $voteCounts[(string) $c->_id] ?? 0;
            $pct = $totalVotes > 0 ? round(($count / $totalVotes) * 100, 2) : 0.0;

            return [
                'id' => (string) $c->_id,
                'candidate_number' => $c->candidate_number,
                'name' => $c->name,
                'vice_name' => $c->vice_name,
                'party_name' => $c->party_name,
                'votes' => $count,
                'percentage' => $pct,
            ];
        })->values()->all();

        usort($candidateResults, fn ($a, $b) => $b['votes'] <=> $a['votes']);

        if ($election->election_mode === Election::MODE_MULTI_SEAT) {
            return $this->evaluateSainteLague($election, $round, $candidateResults, $totalVotes);
        }

        return $this->evaluateSingleWinner($election, $round, $candidateResults, $totalVotes);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidateResults
     * @return array<string, mixed>
     */
    private function evaluateSingleWinner(
        Election $election,
        ElectionRound $round,
        array $candidateResults,
        int $totalVotes
    ): array {
        $threshold = (float) ($election->win_threshold_percent ?? 50.0);

        $top = $candidateResults[0] ?? null;
        $second = $candidateResults[1] ?? null;
        $isTieAtTop = $top !== null && $second !== null && $top['votes'] === $second['votes'] && $top['votes'] > 0;

        $winner = null;
        if ($totalVotes > 0 && $top !== null && ! $isTieAtTop && $top['percentage'] >= $threshold) {
            $winner = $top;
        }

        $annotated = array_map(function (array $item) use ($threshold, $winner) {
            $item['meets_threshold'] = $item['percentage'] >= $threshold;
            $item['is_winner'] = $winner !== null && $winner['id'] === $item['id'];

            return $item;
        }, $candidateResults);

        return [
            'election_id' => (string) $election->_id,
            'round_id' => (string) $round->_id,
            'round_number' => (int) $round->round_number,
            'election_mode' => Election::MODE_SINGLE_WINNER,
            'total_votes' => $totalVotes,
            'threshold_percent' => $threshold,
            'status_suggestion' => $winner !== null ? ElectionRound::STATUS_COMPLETED : ElectionRound::STATUS_NEEDS_RUNOFF,
            'is_tie' => $isTieAtTop,
            'winner' => $winner,
            'candidates' => $annotated,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidateResults
     * @return array<string, mixed>
     */
    private function evaluateSainteLague(
        Election $election,
        ElectionRound $round,
        array $candidateResults,
        int $totalVotes
    ): array {
        $seatCount = max(1, (int) ($election->seat_count ?? 1));

        // Kelompokkan suara dan caleg berdasarkan partai pengusung
        $parties = [];
        foreach ($candidateResults as $cand) {
            $party = trim((string) ($cand['party_name'] ?? 'Independen'));
            if ($party === '') {
                $party = 'Independen';
            }

            if (! isset($parties[$party])) {
                $parties[$party] = [
                    'party_name' => $party,
                    'total_votes' => 0,
                    'seats_won' => 0,
                    'candidates' => [],
                ];
            }

            $parties[$party]['total_votes'] += $cand['votes'];
            $parties[$party]['candidates'][] = $cand;
        }

        // Urutkan caleg dalam tiap partai berdasarkan perolehan suara tertinggi
        foreach ($parties as &$pData) {
            usort($pData['candidates'], fn ($a, $b) => $b['votes'] <=> $a['votes']);
        }
        unset($pData);

        // Alokasi kursi menggunakan pembagi ganjil Sainte-Laguë: 1, 3, 5, 7, ...
        $seatAllocations = [];
        if ($totalVotes > 0 && $parties !== []) {
            for ($seat = 1; $seat <= $seatCount; $seat++) {
                $bestParty = null;
                $bestQuotient = -1.0;

                foreach ($parties as $partyName => $pData) {
                    // Partai tidak bisa mendapat kursi melebihi jumlah caleg yang dimilikinya
                    if ($pData['seats_won'] >= count($pData['candidates'])) {
                        continue;
                    }
                    if ($pData['total_votes'] <= 0) {
                        continue;
                    }

                    $divisor = 2 * $pData['seats_won'] + 1;
                    $quotient = $pData['total_votes'] / $divisor;

                    if (
                        $quotient > $bestQuotient ||
                        ($quotient === $bestQuotient && $bestParty !== null && $pData['total_votes'] > $parties[$bestParty]['total_votes'])
                    ) {
                        $bestQuotient = $quotient;
                        $bestParty = $partyName;
                    }
                }

                if ($bestParty === null) {
                    break;
                }

                $seatIndex = $parties[$bestParty]['seats_won'];
                $parties[$bestParty]['seats_won']++;
                $electedCand = $parties[$bestParty]['candidates'][$seatIndex];

                $seatAllocations[] = [
                    'seat_number' => $seat,
                    'party_name' => $bestParty,
                    'divisor' => 2 * $seatIndex + 1,
                    'quotient' => round($bestQuotient, 2),
                    'candidate' => $electedCand,
                ];
            }
        }

        $electedIds = array_column(array_column($seatAllocations, 'candidate'), 'id');
        $annotatedCandidates = array_map(function (array $cand) use ($electedIds) {
            $cand['is_elected'] = in_array($cand['id'], $electedIds, true);

            return $cand;
        }, $candidateResults);

        $partySummary = array_values(array_map(function (array $pData) {
            return [
                'party_name' => $pData['party_name'],
                'total_votes' => $pData['total_votes'],
                'seats_won' => $pData['seats_won'],
                'elected_candidates' => array_slice($pData['candidates'], 0, $pData['seats_won']),
            ];
        }, $parties));

        usort($partySummary, fn ($a, $b) => $b['seats_won'] <=> $a['seats_won'] ?: $b['total_votes'] <=> $a['total_votes']);

        return [
            'election_id' => (string) $election->_id,
            'round_id' => (string) $round->_id,
            'round_number' => (int) $round->round_number,
            'election_mode' => Election::MODE_MULTI_SEAT,
            'total_votes' => $totalVotes,
            'seat_count' => $seatCount,
            'status_suggestion' => ElectionRound::STATUS_COMPLETED,
            'party_results' => $partySummary,
            'seat_allocations' => $seatAllocations,
            'elected_candidates' => array_column($seatAllocations, 'candidate'),
            'candidates' => $annotatedCandidates,
        ];
    }
}
