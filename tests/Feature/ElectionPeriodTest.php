<?php

use App\Models\Admin;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionPeriod;
use App\Models\ElectionRound;
use App\Models\Region;
use Illuminate\Support\Facades\Hash;
use Tests\UsesMongo;

uses(UsesMongo::class);

function periodAdminToken(): string
{
    Admin::create([
        'username' => 'admin_periode',
        'password' => Hash::make('rahasia123'),
    ]);

    $response = test()->postJson('/api/v1/auth/admin/login', [
        'username' => 'admin_periode',
        'password' => 'rahasia123',
    ])->assertOk();

    return $response->json('token');
}

function seedBaliRegionsForElection(): array
{
    $badung = Region::create(['name' => 'Kabupaten Badung', 'level' => Region::LEVEL_KABUPATEN]);
    $gianyar = Region::create(['name' => 'Kabupaten Gianyar', 'level' => Region::LEVEL_KABUPATEN]);

    $kutaUtara = Region::create([
        'name' => 'Kecamatan Kuta Utara',
        'level' => Region::LEVEL_KECAMATAN,
        'parent_id' => (string) $badung->_id,
        'kabupaten_id' => (string) $badung->_id,
    ]);

    $mengwi = Region::create([
        'name' => 'Kecamatan Mengwi',
        'level' => Region::LEVEL_KECAMATAN,
        'parent_id' => (string) $badung->_id,
        'kabupaten_id' => (string) $badung->_id,
    ]);

    $ubud = Region::create([
        'name' => 'Kecamatan Ubud',
        'level' => Region::LEVEL_KECAMATAN,
        'parent_id' => (string) $gianyar->_id,
        'kabupaten_id' => (string) $gianyar->_id,
    ]);

    $canggu = Region::create([
        'name' => 'Desa Canggu',
        'level' => Region::LEVEL_KELURAHAN,
        'parent_id' => (string) $kutaUtara->_id,
        'kabupaten_id' => (string) $badung->_id,
        'kecamatan_id' => (string) $kutaUtara->_id,
    ]);

    return compact('badung', 'gianyar', 'kutaUtara', 'mengwi', 'ubud', 'canggu');
}

it('mengelola periode pemilihan dan mengarsipkan periode aktif sebelumnya', function () {
    $token = periodAdminToken();

    $p1 = $this->withToken($token)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan Serentak 2025',
        'year' => 2025,
        'start_date' => '2025-10-01',
        'end_date' => '2025-10-30',
        'status' => 'active',
    ])->assertCreated()->json('period');

    expect($p1['status'])->toBe('active');

    $p2 = $this->withToken($token)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan Serentak 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'draft',
    ])->assertCreated()->json('period');

    $this->withToken($token)->postJson("/api/v1/admin/periods/{$p2['id']}/activate")
        ->assertOk()
        ->assertJsonPath('period.status', 'active');

    expect(ElectionPeriod::find($p1['id'])->status)->toBe('archived');
});

it('membuat pemilihan bupati dprd dan perbekel beserta putaran 1', function () {
    $token = periodAdminToken();
    $regions = seedBaliRegionsForElection();

    $period = $this->withToken($token)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan Bali 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'active',
    ])->assertCreated()->json('period');

    // 1. Bupati Badung (single_winner, threshold 60%)
    $bupati = $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'period_id' => $period['id'],
        'name' => 'Pilbup Badung 2026',
        'position_type' => 'bupati',
        'region_id' => (string) $regions['badung']->_id,
        'win_threshold_percent' => 60,
    ])->assertCreated()->json('election');

    expect($bupati['election_mode'])->toBe('single_winner');
    expect((float) $bupati['win_threshold_percent'])->toBe(60.0);
    expect($bupati['rounds'])->toHaveCount(1);
    expect($bupati['rounds'][0]['round_number'])->toBe(1);

    // 2. DPRD Kab. Badung (multi_seat, Sainte-Lague, Dapil Kuta Utara + Mengwi)
    $dprd = $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'period_id' => $period['id'],
        'name' => 'DPRD Badung Dapil 1',
        'position_type' => 'dprd_kabkota',
        'region_id' => (string) $regions['badung']->_id,
        'seat_count' => 8,
        'dapil_name' => 'Dapil Badung 1',
        'dapil_region_ids' => [
            (string) $regions['kutaUtara']->_id,
            (string) $regions['mengwi']->_id,
        ],
    ])->assertCreated()->json('election');

    expect($dprd['election_mode'])->toBe('multi_seat');
    expect($dprd['seat_count'])->toBe(8);
    expect($dprd['dapil_regions'])->toHaveCount(2);

    // 3. Perbekel Desa Canggu (kelurahan level)
    $perbekel = $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'period_id' => $period['id'],
        'name' => 'Pilkel Desa Canggu 2026',
        'position_type' => 'perbekel',
        'region_id' => (string) $regions['canggu']->_id,
        'win_threshold_percent' => 50,
    ])->assertCreated()->json('election');

    expect($perbekel['level'])->toBe('kelurahan');

    // Periode tidak boleh dihapus jika sudah ada pemilihan
    $this->withToken($token)->deleteJson("/api/v1/admin/periods/{$period['id']}")
        ->assertStatus(422);
});

it('menolak pemilihan jika wilayah tidak sesuai jabatan atau dapil lintas kabupaten', function () {
    $token = periodAdminToken();
    $regions = seedBaliRegionsForElection();

    $this->withToken($token)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'active',
    ])->assertCreated();

    // Perbekel diberi kabupaten (harus kelurahan)
    $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'name' => 'Pilkel Salah Wilayah',
        'position_type' => 'perbekel',
        'region_id' => (string) $regions['badung']->_id,
    ])->assertStatus(422);

    // DPRD Badung diberi kecamatan Ubud (milik Gianyar)
    $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'name' => 'DPRD Salah Dapil',
        'position_type' => 'dprd_kabkota',
        'region_id' => (string) $regions['badung']->_id,
        'seat_count' => 6,
        'dapil_region_ids' => [(string) $regions['ubud']->_id],
    ])->assertStatus(422);
});

it('mengelola paslon per pemilihan dan putaran lanjutan', function () {
    $token = periodAdminToken();
    $regions = seedBaliRegionsForElection();

    $this->withToken($token)->postJson('/api/v1/admin/periods', [
        'name' => 'Pemilihan 2026',
        'year' => 2026,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-30',
        'status' => 'active',
    ])->assertCreated();

    $perbekel = $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'name' => 'Pilkel Canggu',
        'position_type' => 'perbekel',
        'region_id' => (string) $regions['canggu']->_id,
        'win_threshold_percent' => 60,
    ])->assertCreated()->json('election');

    $dprd = $this->withToken($token)->postJson('/api/v1/admin/elections', [
        'name' => 'DPRD Badung',
        'position_type' => 'dprd_kabkota',
        'region_id' => (string) $regions['badung']->_id,
        'seat_count' => 5,
        'dapil_region_ids' => [(string) $regions['kutaUtara']->_id],
    ])->assertCreated()->json('election');

    // Perbekel: nomor urut 1 tanpa vice_name
    $c1 = $this->withToken($token)->postJson('/api/v1/admin/candidates', [
        'election_id' => $perbekel['id'],
        'candidate_number' => '1',
        'name' => 'I Made Calon Perbekel 1',
    ])->assertCreated()->json('candidate');

    $c2 = $this->withToken($token)->postJson('/api/v1/admin/candidates', [
        'election_id' => $perbekel['id'],
        'candidate_number' => '2',
        'name' => 'Ni Ketut Calon Perbekel 2',
    ])->assertCreated()->json('candidate');

    // DPRD: nomor urut 1 juga boleh di pemilihan berbeda, wajib party_name
    $this->withToken($token)->postJson('/api/v1/admin/candidates', [
        'election_id' => $dprd['id'],
        'candidate_number' => '1',
        'name' => 'I Wayan Caleg',
    ])->assertStatus(422);

    $this->withToken($token)->postJson('/api/v1/admin/candidates', [
        'election_id' => $dprd['id'],
        'candidate_number' => '1',
        'name' => 'I Wayan Caleg',
        'party_name' => 'Partai Nusantara',
    ])->assertCreated();

    // Aktivasi Putaran 1 Perbekel, lalu tutup, lalu buat Putaran 2
    $round1Id = $perbekel['rounds'][0]['id'];
    $this->withToken($token)->postJson("/api/v1/admin/rounds/{$round1Id}/activate")
        ->assertOk()
        ->assertJsonPath('round.status', 'active');

    $this->withToken($token)->postJson("/api/v1/admin/rounds/{$round1Id}/close")
        ->assertOk()
        ->assertJsonPath('round.status', 'needs_runoff');

    $round2 = $this->withToken($token)->postJson("/api/v1/admin/elections/{$perbekel['id']}/rounds", [
        'advancing_candidate_ids' => [$c1['id'], $c2['id']],
    ])->assertCreated()->json('round');

    expect($round2['round_number'])->toBe(2);
    expect($round2['advancing_candidate_ids'])->toHaveCount(2);
});
