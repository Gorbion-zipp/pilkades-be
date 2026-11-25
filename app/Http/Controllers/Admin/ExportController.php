<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Models\Region;
use App\Models\Voter;
use App\Models\VoterBallot;
use App\Services\ElectionResultService;
use App\Services\SpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly SpreadsheetService $spreadsheet,
        private readonly ElectionResultService $resultService
    ) {}

    public function exportElection(Request $request, string $electionId): StreamedResponse|JsonResponse
    {
        $election = Election::find($electionId);
        if (! $election) {
            return response()->json(['message' => 'Data pemilihan tidak ditemukan.'], 404);
        }

        $roundId = $request->query('round_id');
        $round = $roundId
            ? ElectionRound::where('election_id', (string) $election->_id)->where('_id', $roundId)->first()
            : ElectionRound::where('election_id', (string) $election->_id)->orderBy('round_number', 'desc')->first();

        if (! $round) {
            return response()->json(['message' => 'Data putaran pemilihan tidak ditemukan.'], 404);
        }

        $evaluation = $this->resultService->evaluateRound($election, $round);
        $format = strtolower((string) $request->query('format', 'xlsx'));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $election->name));
        $fileName = "hasil-{$slug}-putaran-{$round->round_number}.{$format}";

        if ($election->election_mode === Election::MODE_MULTI_SEAT) {
            $headers = ['No Urut', 'Nama Caleg', 'Partai Pengusung', 'Perolehan Suara', 'Persentase (%)', 'Kursi Partai', 'Status Terpilih'];
            $partySeats = [];
            foreach ($evaluation['party_results'] ?? [] as $p) {
                $partySeats[$p['party_name']] = $p['seats_won'];
            }

            $rows = array_map(function (array $c) use ($partySeats) {
                $party = $c['party_name'] ?? 'Independen';

                return [
                    (string) $c['candidate_number'],
                    (string) $c['name'],
                    (string) $party,
                    (int) $c['votes'],
                    (string) $c['percentage'],
                    (int) ($partySeats[$party] ?? 0),
                    ! empty($c['is_elected']) ? 'Terpilih' : 'Tidak Terpilih',
                ];
            }, $evaluation['candidates'] ?? []);
        } else {
            $threshold = (float) ($evaluation['threshold_percent'] ?? 50);
            $headers = ['No Urut', 'Nama Calon', 'Nama Wakil', 'Perolehan Suara', 'Persentase (%)', 'Ambang Batas (%)', 'Status'];

            $rows = array_map(function (array $c) use ($threshold) {
                $status = ! empty($c['is_winner'])
                    ? 'Pemenang'
                    : (! empty($c['meets_threshold']) ? 'Memenuhi Ambang Batas' : 'Belum Memenuhi');

                return [
                    (string) $c['candidate_number'],
                    (string) $c['name'],
                    (string) ($c['vice_name'] ?? '-'),
                    (int) $c['votes'],
                    (string) $c['percentage'],
                    (string) $threshold,
                    $status,
                ];
            }, $evaluation['candidates'] ?? []);
        }

        if ($format === 'csv') {
            return $this->spreadsheet->downloadCsv($fileName, $headers, $rows);
        }

        return $this->spreadsheet->downloadXlsx($fileName, $headers, $rows, [0]);
    }

    public function exportVoters(Request $request): StreamedResponse
    {
        $query = Voter::orderBy('name');
        if ($kabId = $request->query('kabupaten_id')) {
            $query->where('kabupaten_id', $kabId);
        }

        $voters = $query->get();
        $regionMap = Region::all()->keyBy(fn (Region $r) => (string) $r->_id)->all();

        $electionId = $request->query('election_id');
        $votedVoterIds = [];
        if ($electionId) {
            $votedVoterIds = VoterBallot::where('election_id', $electionId)
                ->pluck('voter_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }

        $headers = ['NIK', 'Nama', 'Tanggal Lahir', 'Kabupaten', 'Kecamatan', 'Kelurahan/Desa', 'Banjar', 'Status Memilih'];
        $rows = $voters->map(function (Voter $v) use ($regionMap, $electionId, $votedVoterIds) {
            $api = $v->toApi($regionMap);
            $hasVoted = $electionId
                ? in_array((string) $v->_id, $votedVoterIds, true)
                : (bool) $v->has_voted;

            return [
                (string) $api['nik'],
                (string) $api['name'],
                (string) ($api['birth_date'] ?? ''),
                (string) ($api['kabupaten_name'] ?? ''),
                (string) ($api['kecamatan_name'] ?? ''),
                (string) ($api['kelurahan_name'] ?? ''),
                (string) ($api['banjar_name'] ?? ''),
                $hasVoted ? 'Sudah Memilih' : 'Belum Memilih',
            ];
        })->all();

        $format = strtolower((string) $request->query('format', 'xlsx'));
        $date = now()->format('Y-m-d');
        $fileName = "laporan-dpt-{$date}.{$format}";

        if ($format === 'csv') {
            return $this->spreadsheet->downloadCsv($fileName, $headers, $rows);
        }

        return $this->spreadsheet->downloadXlsx($fileName, $headers, $rows, [0, 2]);
    }
}
