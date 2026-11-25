<?php

use App\Models\Admin;
use App\Models\Region;
use App\Models\Voter;
use App\Services\JwtService;
use Tests\UsesMongo;

uses(UsesMongo::class);

function regionAdminToken(): string
{
    $admin = Admin::create([
        'name' => 'Admin Wilayah',
        'username' => 'admin_region_'.uniqid(),
        'password' => bcrypt('rahasia'),
    ]);

    return JwtService::create((string) $admin->_id, 'admin');
}

it('membuat hierarki wilayah bali dari kabupaten sampai banjar', function () {
    $token = regionAdminToken();

    $kab = $this->withToken($token)->postJson('/api/v1/admin/regions', [
        'name' => 'Kabupaten Gianyar',
        'level' => 'kabupaten',
        'code' => '5104',
    ])->assertCreated()->json('region');

    $kec = $this->withToken($token)->postJson('/api/v1/admin/regions', [
        'name' => 'Kecamatan Ubud',
        'level' => 'kecamatan',
        'parent_id' => $kab['id'],
    ])->assertCreated()->json('region');

    $kel = $this->withToken($token)->postJson('/api/v1/admin/regions', [
        'name' => 'Desa Sayan',
        'level' => 'kelurahan',
        'parent_id' => $kec['id'],
    ])->assertCreated()->json('region');

    $banjar = $this->withToken($token)->postJson('/api/v1/admin/regions', [
        'name' => 'Banjar Penestanan',
        'level' => 'banjar',
        'parent_id' => $kel['id'],
    ])->assertCreated()->json('region');

    expect($banjar['kabupaten_id'])->toBe($kab['id']);
    expect($banjar['kecamatan_id'])->toBe($kec['id']);
    expect($banjar['kelurahan_id'])->toBe($kel['id']);
});

it('menolak pembuatan sub wilayah jika level induk salah', function () {
    $token = regionAdminToken();

    $kab = $this->withToken($token)->postJson('/api/v1/admin/regions', [
        'name' => 'Kabupaten Badung',
        'level' => 'kabupaten',
    ])->assertCreated()->json('region');

    $this->withToken($token)->postJson('/api/v1/admin/regions', [
        'name' => 'Banjar Langsung',
        'level' => 'banjar',
        'parent_id' => $kab['id'],
    ])->assertStatus(422);
});

it('mencegah penghapusan wilayah yang masih memiliki sub wilayah atau dpt', function () {
    $token = regionAdminToken();

    $kab = Region::create(['name' => 'Kabupaten Badung', 'level' => 'kabupaten', 'parent_id' => null]);
    $kec = Region::create(['name' => 'Kecamatan Kuta', 'level' => 'kecamatan', 'parent_id' => (string) $kab->_id, 'kabupaten_id' => (string) $kab->_id]);
    $kel = Region::create(['name' => 'Kelurahan Kuta', 'level' => 'kelurahan', 'parent_id' => (string) $kec->_id, 'kabupaten_id' => (string) $kab->_id, 'kecamatan_id' => (string) $kec->_id]);
    $banjar = Region::create(['name' => 'Banjar Pande Mas', 'level' => 'banjar', 'parent_id' => (string) $kel->_id, 'kabupaten_id' => (string) $kab->_id, 'kecamatan_id' => (string) $kec->_id, 'kelurahan_id' => (string) $kel->_id]);

    $this->withToken($token)->deleteJson('/api/v1/admin/regions/'.$kab->_id)->assertStatus(422);

    Voter::create([
        'nik' => '5103010101900001',
        'name' => 'Wayan Pande',
        'birth_date' => '1990-01-01',
        'kabupaten_id' => (string) $kab->_id,
        'kecamatan_id' => (string) $kec->_id,
        'kelurahan_id' => (string) $kel->_id,
        'banjar_id' => (string) $banjar->_id,
        'has_voted' => false,
    ]);

    $this->withToken($token)->deleteJson('/api/v1/admin/regions/'.$banjar->_id)->assertStatus(422);
});
