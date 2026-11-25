<?php

use App\Models\Admin;
use App\Models\Candidate;
use App\Models\Region;
use App\Models\Vote;
use App\Models\Voter;
use App\Models\VoterBallot;
use Illuminate\Support\Facades\Hash;
use Tests\UsesMongo;

uses(UsesMongo::class);

function m4AdminToken(): string
{
    Admin::create([
        'username' => 'admin_m4',
        'password' => Hash::make('rahasia123'),
    ]);

    return test()->postJson('/api/v1/auth/admin/login', [
        'username' => 'admin_m4',
        'password' => 'rahasia123',
    ])->assertOk()->json('token');
}

it('menampilkan live count berjenjang per periode pemilihan dan putaran', function () {
    $token = m4AdminToken();

    $badung = Region::create(['name' => 'Kabupaten Badung', 'level' => Region::LEVEL_KABUPATEN]);
    $gianyar = Region::create(['name' => 'Kabupaten Gianyar', 'level' => Region::LEVEL_KABUPATEN]);

    $this->withToken($token)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan Serentak 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'active',
    ])->assertCreated();

    $pilbup = $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'name' => 'Pilbup Badung 2026',
        'position_type' => 'bupati',
        'region_id' => (string) $badung->_id,
        'win_threshold_percent' => 50,
    ])->assertCreated()->json('election');

    $roundId = $pilbup['rounds'][0]['id'];
    $c1 = Candidate::create([
        'election_id' => $pilbup['id'],
        'candidate_number' => '1',
        'name' => 'Paslon Badung 1',
        'vice_name' => 'Wakil 1',
        'vision_mission' => '<p>Rahasia Visi Misi</p>',
    ]);

    $v1 = Voter::create(['nik' => '5103010101900001', 'name' => 'Warga Badung 1', 'birth_date' => '1990-01-01', 'kabupaten_id' => (string) $badung->_id]);
    Voter::create(['nik' => '5103010101900002', 'name' => 'Warga Badung 2', 'birth_date' => '1990-01-01', 'kabupaten_id' => (string) $badung->_id]);
    Voter::create(['nik' => '5104010101900003', 'name' => 'Warga Gianyar', 'birth_date' => '1990-01-01', 'kabupaten_id' => (string) $gianyar->_id]);

    Vote::create(['election_id' => $pilbup['id'], 'election_round_id' => $roundId, 'candidate_id' => (string) $c1->_id]);
    VoterBallot::create(['voter_id' => (string) $v1->_id, 'election_id' => $pilbup['id'], 'election_round_id' => $roundId]);

    $res = $this->getJson("/api/v1/live-count?election_id={$pilbup['id']}")
        ->assertOk()
        ->json();

    // Hanya 2 warga Badung yang dihitung di DPT Pilbup Badung
    expect($res['stats']['total_dpt'])->toBe(2);
    expect($res['stats']['total_voted'])->toBe(1);
    expect($res['candidates'][0]['votes'])->toBe(1);
    expect($res['candidates'][0])->not->toHaveKey('vision_mission');
    expect($res['evaluation']['winner']['id'])->toBe((string) $c1->_id);
});

it('mengekspor hasil pemilihan dan dpt ke format xlsx dan csv', function () {
    $token = m4AdminToken();

    $badung = Region::create(['name' => 'Kabupaten Badung', 'level' => Region::LEVEL_KABUPATEN]);
    $kec = Region::create(['name' => 'Kecamatan Kuta Utara', 'level' => Region::LEVEL_KECAMATAN, 'parent_id' => (string) $badung->_id, 'kabupaten_id' => (string) $badung->_id]);
    $kel = Region::create(['name' => 'Desa Canggu', 'level' => Region::LEVEL_KELURAHAN, 'parent_id' => (string) $kec->_id, 'kabupaten_id' => (string) $badung->_id, 'kecamatan_id' => (string) $kec->_id]);
    $banjar = Region::create(['name' => 'Banjar Canggu', 'level' => Region::LEVEL_BANJAR, 'parent_id' => (string) $kel->_id, 'kabupaten_id' => (string) $badung->_id, 'kecamatan_id' => (string) $kec->_id, 'kelurahan_id' => (string) $kel->_id]);

    $this->withToken($token)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'active',
    ])->assertCreated();

    $pilkel = $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'name' => 'Pilkel Canggu 2026',
        'position_type' => 'perbekel',
        'region_id' => (string) $kel->_id,
        'win_threshold_percent' => 50,
    ])->assertCreated()->json('election');

    $c1 = Candidate::create(['election_id' => $pilkel['id'], 'candidate_number' => '1', 'name' => 'I Made Perbekel']);
    Vote::create(['election_id' => $pilkel['id'], 'election_round_id' => $pilkel['rounds'][0]['id'], 'candidate_id' => (string) $c1->_id]);

    Voter::create([
        'nik' => '5103010101900010',
        'name' => 'Ni Luh DPT',
        'birth_date' => '1990-01-01',
        'kabupaten_id' => (string) $badung->_id,
        'kecamatan_id' => (string) $kec->_id,
        'kelurahan_id' => (string) $kel->_id,
        'banjar_id' => (string) $banjar->_id,
        'has_voted' => true,
    ]);

    // Export hasil pemilihan XLSX & CSV
    $xlsxRes = $this->withToken($token)->get("/api/v1/admin/export/election/{$pilkel['id']}?format=xlsx")->assertOk();
    expect(substr($xlsxRes->streamedContent(), 0, 2))->toBe('PK');

    $csvRes = $this->withToken($token)->get("/api/v1/admin/export/election/{$pilkel['id']}?format=csv")->assertOk();
    expect($csvRes->streamedContent())
        ->toContain('I Made Perbekel')
        ->toContain('Pemenang');

    // Export DPT XLSX & CSV
    $dptXlsx = $this->withToken($token)->get('/api/v1/admin/export/voters?format=xlsx')->assertOk();
    expect(substr($dptXlsx->streamedContent(), 0, 2))->toBe('PK');

    $dptCsv = $this->withToken($token)->get('/api/v1/admin/export/voters?format=csv')->assertOk();
    expect($dptCsv->streamedContent())
        ->toContain('5103010101900010')
        ->toContain('Banjar Canggu')
        ->toContain('Sudah Memilih');
});
