<?php

use App\Models\Admin;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionPeriod;
use App\Models\ElectionRound;
use App\Models\Region;
use App\Models\Vote;
use App\Models\Voter;
use Illuminate\Support\Facades\Hash;
use Tests\UsesMongo;

uses(UsesMongo::class);

function m3AdminToken(): string
{
    Admin::create([
        'username' => 'admin_m3',
        'password' => Hash::make('rahasia123'),
    ]);

    return test()->postJson('/api/v1/auth/admin/login', [
        'username' => 'admin_m3',
        'password' => 'rahasia123',
    ])->assertOk()->json('token');
}

function m3VoterToken(Voter $voter): string
{
    return test()->postJson('/api/v1/auth/voter/login', [
        'nik' => $voter->nik,
        'birth_date' => $voter->birth_date,
    ])->assertOk()->json('token');
}

function m3SeedRegions(): array
{
    $badung = Region::create(['name' => 'Kabupaten Badung', 'level' => Region::LEVEL_KABUPATEN]);
    $gianyar = Region::create(['name' => 'Kabupaten Gianyar', 'level' => Region::LEVEL_KABUPATEN]);

    $kutaUtara = Region::create([
        'name' => 'Kecamatan Kuta Utara',
        'level' => Region::LEVEL_KECAMATAN,
        'parent_id' => (string) $badung->_id,
        'kabupaten_id' => (string) $badung->_id,
    ]);

    $canggu = Region::create([
        'name' => 'Desa Canggu',
        'level' => Region::LEVEL_KELURAHAN,
        'parent_id' => (string) $kutaUtara->_id,
        'kabupaten_id' => (string) $badung->_id,
        'kecamatan_id' => (string) $kutaUtara->_id,
    ]);

    $banjarCanggu = Region::create([
        'name' => 'Banjar Canggu',
        'level' => Region::LEVEL_BANJAR,
        'parent_id' => (string) $canggu->_id,
        'kabupaten_id' => (string) $badung->_id,
        'kecamatan_id' => (string) $kutaUtara->_id,
        'kelurahan_id' => (string) $canggu->_id,
    ]);

    return compact('badung', 'gianyar', 'kutaUtara', 'canggu', 'banjarCanggu');
}

it('menampilkan hanya pemilihan sesuai domisili pemilih dan mencatat suara per putaran secara anonim', function () {
    $adminToken = m3AdminToken();
    $reg = m3SeedRegions();

    $this->withToken($adminToken)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan Serentak 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'active',
    ])->assertCreated();

    $bupati = $this->withToken($adminToken)->postJson('/api/v1/admin/elections', [
        'name' => 'Pilbup Badung 2026',
        'position_type' => 'bupati',
        'region_id' => (string) $reg['badung']->_id,
        'win_threshold_percent' => 50,
    ])->assertCreated()->json('election');

    $perbekel = $this->withToken($adminToken)->postJson('/api/v1/admin/elections', [
        'name' => 'Pilkel Canggu 2026',
        'position_type' => 'perbekel',
        'region_id' => (string) $reg['canggu']->_id,
        'win_threshold_percent' => 50,
    ])->assertCreated()->json('election');

    $bupC1 = $this->withToken($adminToken)->postJson('/api/v1/admin/candidates', [
        'election_id' => $bupati['id'],
        'candidate_number' => '1',
        'name' => 'Calon Bupati 1',
        'vice_name' => 'Wakil Bupati 1',
    ])->assertCreated()->json('candidate');

    $perbC1 = $this->withToken($adminToken)->postJson('/api/v1/admin/candidates', [
        'election_id' => $perbekel['id'],
        'candidate_number' => '1',
        'name' => 'Calon Perbekel 1',
    ])->assertCreated()->json('candidate');

    $this->withToken($adminToken)->postJson("/api/v1/admin/rounds/{$bupati['rounds'][0]['id']}/activate")->assertOk();
    $this->withToken($adminToken)->postJson("/api/v1/admin/rounds/{$perbekel['rounds'][0]['id']}/activate")->assertOk();

    $voterCanggu = Voter::create([
        'nik' => '5103010101900001',
        'name' => 'Warga Canggu',
        'birth_date' => '1990-01-01',
        'kabupaten_id' => (string) $reg['badung']->_id,
        'kecamatan_id' => (string) $reg['kutaUtara']->_id,
        'kelurahan_id' => (string) $reg['canggu']->_id,
        'banjar_id' => (string) $reg['banjarCanggu']->_id,
        'has_voted' => false,
    ]);

    $voterGianyar = Voter::create([
        'nik' => '5104010101900002',
        'name' => 'Warga Gianyar',
        'birth_date' => '1990-01-01',
        'kabupaten_id' => (string) $reg['gianyar']->_id,
        'has_voted' => false,
    ]);

    // Warga Gianyar tidak mendapat surat suara Badung/Canggu
    $gianyarBallots = $this->withToken(m3VoterToken($voterGianyar))
        ->getJson('/api/v1/vote/ballots')
        ->assertOk()
        ->json('elections');
    expect($gianyarBallots)->toBeEmpty();

    // Warga Canggu mendapat 2 surat suara (Bupati + Perbekel)
    $tokenCanggu = m3VoterToken($voterCanggu);
    $ballots = $this->withToken($tokenCanggu)
        ->getJson('/api/v1/vote/ballots')
        ->assertOk()
        ->json('elections');
    expect($ballots)->toHaveCount(2);

    // Coblos pemilihan pertama (Bupati)
    $this->withToken($tokenCanggu)->postJson('/api/v1/vote', [
        'election_round_id' => $bupati['rounds'][0]['id'],
        'candidate_id' => $bupC1['id'],
    ])->assertOk();

    // Belum selesai semua, has_voted global masih false
    expect($voterCanggu->fresh()->has_voted)->toBeFalse();

    // Tidak boleh coblos 2x di pemilihan yang sama
    $this->withToken($tokenCanggu)->postJson('/api/v1/vote', [
        'election_round_id' => $bupati['rounds'][0]['id'],
        'candidate_id' => $bupC1['id'],
    ])->assertStatus(422);

    // Coblos pemilihan kedua (Perbekel)
    $this->withToken($tokenCanggu)->postJson('/api/v1/vote', [
        'election_round_id' => $perbekel['rounds'][0]['id'],
        'candidate_id' => $perbC1['id'],
    ])->assertOk();

    expect($voterCanggu->fresh()->has_voted)->toBeTrue();
    expect(Vote::first()->toArray())->not->toHaveKey('voter_id');
});

it('mengevaluasi ambang batas 60 persen dan menjalankan putaran lanjutan', function () {
    $adminToken = m3AdminToken();
    $reg = m3SeedRegions();

    $this->withToken($adminToken)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'active',
    ])->assertCreated();

    $perbekel = $this->withToken($adminToken)->postJson('/api/v1/admin/elections', [
        'name' => 'Pilkel Canggu',
        'position_type' => 'perbekel',
        'region_id' => (string) $reg['canggu']->_id,
        'win_threshold_percent' => 60,
    ])->assertCreated()->json('election');

    $c1 = Candidate::create(['election_id' => $perbekel['id'], 'candidate_number' => '1', 'name' => 'Calon 1']);
    $c2 = Candidate::create(['election_id' => $perbekel['id'], 'candidate_number' => '2', 'name' => 'Calon 2']);
    $c3 = Candidate::create(['election_id' => $perbekel['id'], 'candidate_number' => '3', 'name' => 'Calon 3']);

    $round1Id = $perbekel['rounds'][0]['id'];
    $this->withToken($adminToken)->postJson("/api/v1/admin/rounds/{$round1Id}/activate")->assertOk();

    // Suara putaran 1: 2, 1, 1 (50%, 25%, 25%) -> belum capai 60%
    Vote::create(['election_id' => $perbekel['id'], 'election_round_id' => $round1Id, 'candidate_id' => (string) $c1->_id]);
    Vote::create(['election_id' => $perbekel['id'], 'election_round_id' => $round1Id, 'candidate_id' => (string) $c1->_id]);
    Vote::create(['election_id' => $perbekel['id'], 'election_round_id' => $round1Id, 'candidate_id' => (string) $c2->_id]);
    Vote::create(['election_id' => $perbekel['id'], 'election_round_id' => $round1Id, 'candidate_id' => (string) $c3->_id]);

    $closeRes = $this->withToken($adminToken)->postJson("/api/v1/admin/rounds/{$round1Id}/close")
        ->assertOk()
        ->json();

    expect($closeRes['round']['status'])->toBe('needs_runoff');
    expect($closeRes['evaluation']['winner'])->toBeNull();

    // Admin buat Putaran 2 dengan c1 & c2
    $round2 = $this->withToken($adminToken)->postJson("/api/v1/admin/elections/{$perbekel['id']}/rounds", [
        'advancing_candidate_ids' => [(string) $c1->_id, (string) $c2->_id],
    ])->assertCreated()->json('round');

    $this->withToken($adminToken)->postJson("/api/v1/admin/rounds/{$round2['id']}/activate")->assertOk();

    $voter = Voter::create([
        'nik' => '5103010101900099',
        'name' => 'Pemilih Putaran 2',
        'birth_date' => '1990-01-01',
        'kelurahan_id' => (string) $reg['canggu']->_id,
    ]);
    $vToken = m3VoterToken($voter);

    // Bilik suara hanya tampilkan 2 paslon lolos
    $ballots = $this->withToken($vToken)->getJson('/api/v1/vote/ballots')->assertOk()->json('elections');
    expect($ballots[0]['candidates'])->toHaveCount(2);

    // Mencoblos c3 yang tereliminasi ditolak
    $this->withToken($vToken)->postJson('/api/v1/vote', [
        'election_round_id' => $round2['id'],
        'candidate_id' => (string) $c3->_id,
    ])->assertStatus(422);

    // Putaran 2: c1 = 3 suara (75%), c2 = 1 suara (25%) -> menang >= 60%
    $this->withToken($vToken)->postJson('/api/v1/vote', [
        'election_round_id' => $round2['id'],
        'candidate_id' => (string) $c1->_id,
    ])->assertOk();
    Vote::create(['election_id' => $perbekel['id'], 'election_round_id' => $round2['id'], 'candidate_id' => (string) $c1->_id]);
    Vote::create(['election_id' => $perbekel['id'], 'election_round_id' => $round2['id'], 'candidate_id' => (string) $c1->_id]);
    Vote::create(['election_id' => $perbekel['id'], 'election_round_id' => $round2['id'], 'candidate_id' => (string) $c2->_id]);

    $close2 = $this->withToken($adminToken)->postJson("/api/v1/admin/rounds/{$round2['id']}/close")
        ->assertOk()
        ->json();

    expect($close2['round']['status'])->toBe('completed');
    expect($close2['evaluation']['winner']['id'])->toBe((string) $c1->_id);
});

it('menghitung alokasi kursi dprd kab kota dengan metode sainte lague', function () {
    $adminToken = m3AdminToken();
    $reg = m3SeedRegions();

    $this->withToken($adminToken)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'active',
    ])->assertCreated();

    $dprd = $this->withToken($adminToken)->postJson('/api/v1/admin/elections', [
        'name' => 'DPRD Badung Dapil Kuta Utara',
        'position_type' => 'dprd_kabkota',
        'region_id' => (string) $reg['badung']->_id,
        'seat_count' => 4,
        'dapil_name' => 'Dapil Kuta Utara',
        'dapil_region_ids' => [(string) $reg['kutaUtara']->_id],
    ])->assertCreated()->json('election');

    $roundId = $dprd['rounds'][0]['id'];

    // Partai A: 7 suara (Caleg A1=5, Caleg A2=2)
    $a1 = Candidate::create(['election_id' => $dprd['id'], 'candidate_number' => '1', 'name' => 'Caleg A1', 'party_name' => 'Partai A']);
    $a2 = Candidate::create(['election_id' => $dprd['id'], 'candidate_number' => '2', 'name' => 'Caleg A2', 'party_name' => 'Partai A']);
    // Partai B: 4 suara (Caleg B1=4)
    $b1 = Candidate::create(['election_id' => $dprd['id'], 'candidate_number' => '3', 'name' => 'Caleg B1', 'party_name' => 'Partai B']);
    // Partai C: 2 suara (Caleg C1=2)
    $c1 = Candidate::create(['election_id' => $dprd['id'], 'candidate_number' => '4', 'name' => 'Caleg C1', 'party_name' => 'Partai C']);

    foreach (range(1, 5) as $_) {
        Vote::create(['election_id' => $dprd['id'], 'election_round_id' => $roundId, 'candidate_id' => (string) $a1->_id]);
    }
    foreach (range(1, 2) as $_) {
        Vote::create(['election_id' => $dprd['id'], 'election_round_id' => $roundId, 'candidate_id' => (string) $a2->_id]);
    }
    foreach (range(1, 4) as $_) {
        Vote::create(['election_id' => $dprd['id'], 'election_round_id' => $roundId, 'candidate_id' => (string) $b1->_id]);
    }
    foreach (range(1, 2) as $_) {
        Vote::create(['election_id' => $dprd['id'], 'election_round_id' => $roundId, 'candidate_id' => (string) $c1->_id]);
    }

    // Sainte-Lague 4 kursi:
    // Kursi 1: A (7/1=7) | Kursi 2: B (4/1=4) | Kursi 3: A (7/3=2.33) | Kursi 4: C (2/1=2)
    $eval = $this->withToken($adminToken)
        ->getJson("/api/v1/admin/rounds/{$roundId}/evaluation")
        ->assertOk()
        ->json('evaluation');

    expect($eval['total_votes'])->toBe(13);
    expect($eval['elected_candidates'])->toHaveCount(4);

    $partySeats = collect($eval['party_results'])->pluck('seats_won', 'party_name')->all();
    expect($partySeats['Partai A'])->toBe(2);
    expect($partySeats['Partai B'])->toBe(1);
    expect($partySeats['Partai C'])->toBe(1);
});
