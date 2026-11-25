<?php

use App\Models\Admin;
use App\Models\Region;
use App\Models\Voter;
use App\Services\JwtService;
use Illuminate\Http\UploadedFile;
use Shuchkin\SimpleXLSXGen;
use Tests\UsesMongo;

uses(UsesMongo::class);

const CSV_HEADER = "nik,nama,tanggal_lahir,kabupaten,kecamatan,kelurahan,banjar\n";

function adminToken(): string
{
    $admin = Admin::create([
        'name' => 'Admin Uji',
        'username' => 'admin_uji_'.uniqid(),
        'password' => bcrypt('rahasia'),
    ]);

    return JwtService::create((string) $admin->_id, 'admin');
}

function seedSampleBaliRegion(): Region
{
    $kab = Region::create([
        'name' => 'Kabupaten Badung',
        'level' => Region::LEVEL_KABUPATEN,
        'parent_id' => null,
        'code' => '5103',
    ]);
    $kec = Region::create([
        'name' => 'Kecamatan Kuta Utara',
        'level' => Region::LEVEL_KECAMATAN,
        'parent_id' => (string) $kab->_id,
        'kabupaten_id' => (string) $kab->_id,
    ]);
    $kel = Region::create([
        'name' => 'Desa Canggu',
        'level' => Region::LEVEL_KELURAHAN,
        'parent_id' => (string) $kec->_id,
        'kabupaten_id' => (string) $kab->_id,
        'kecamatan_id' => (string) $kec->_id,
    ]);

    return Region::create([
        'name' => 'Banjar Canggu',
        'level' => Region::LEVEL_BANJAR,
        'parent_id' => (string) $kel->_id,
        'kabupaten_id' => (string) $kab->_id,
        'kecamatan_id' => (string) $kec->_id,
        'kelurahan_id' => (string) $kel->_id,
    ]);
}

function previewFile(string $contents, string $name = 'dpt.csv'): array
{
    return test()->withToken(adminToken())
        ->post('/api/v1/admin/voters/import/preview', [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ])
        ->assertOk()
        ->json();
}

it('menyediakan template csv dan xlsx berisi kolom wilayah bali', function () {
    $csvResponse = $this->withToken(adminToken())->get('/api/v1/admin/voters/template?format=csv');
    $csvResponse->assertOk();
    expect($csvResponse->streamedContent())
        ->toContain('nik,nama,tanggal_lahir,kabupaten,kecamatan,kelurahan,banjar')
        ->toContain('5103010101900011');

    $xlsxResponse = $this->withToken(adminToken())->get('/api/v1/admin/voters/template?format=xlsx');
    $xlsxResponse->assertOk();
    expect(substr($xlsxResponse->streamedContent(), 0, 2))->toBe('PK');
});

it('menghitung baris valid csv tanpa menyimpan apa pun', function () {
    seedSampleBaliRegion();

    $result = previewFile(CSV_HEADER.
        "5103010101900001,I Wayan Sudiarta,1990-01-01,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n".
        "5103011503880002,Ni Luh Anggreni,1988-03-15,Badung,Kuta Utara,Canggu,Canggu\n"
    );

    expect($result['total_read'])->toBe(2);
    expect($result['total_valid'])->toBe(2);
    expect($result['invalid'])->toBeEmpty();
    expect(Voter::count())->toBe(0);
});

it('membaca berkas excel xlsx secara langsung', function () {
    seedSampleBaliRegion();

    $xlsx = SimpleXLSXGen::fromArray([
        ['nik', 'nama', 'tanggal_lahir', 'kabupaten', 'kecamatan', 'kelurahan', 'banjar'],
        ["\0".'5103010101900001', 'I Wayan Excel', "\0".'1990-01-01', 'Kabupaten Badung', 'Kecamatan Kuta Utara', 'Desa Canggu', 'Banjar Canggu'],
    ]);

    $result = previewFile((string) $xlsx, 'dpt.xlsx');

    expect($result['total_read'])->toBe(1);
    expect($result['total_valid'])->toBe(1);
    expect($result['valid_rows'][0]['nik'])->toBe('5103010101900001');
    expect($result['valid_rows'][0]['name'])->toBe('I Wayan Excel');
});

it('menolak baris jika wilayah banjar tidak terdaftar di master bali', function () {
    seedSampleBaliRegion();

    $result = previewFile(CSV_HEADER.
        "5103010101900001,Wayan Salah Wilayah,1990-01-01,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Fiktif\n"
    );

    expect($result['total_valid'])->toBe(0);
    expect($result['invalid'][0]['reason'])->toContain('Banjar/Lingkungan "Banjar Fiktif" tidak ditemukan');
});

it('menandai nik yang bukan 16 digit', function () {
    seedSampleBaliRegion();
    $result = previewFile(CSV_HEADER."12345,Budi Pendek,1990-01-01,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n");

    expect($result['total_valid'])->toBe(0);
    expect($result['invalid'][0]['reason'])->toContain('16 digit');
});

it('menandai nik notasi ilmiah excel', function () {
    seedSampleBaliRegion();
    $result = previewFile(CSV_HEADER."5.1030101019E+15,Budi Notasi,1990-01-01,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n");

    expect($result['invalid'][0]['reason'])->toContain('notasi ilmiah');
});

it('mewajibkan tanggal lahir', function () {
    seedSampleBaliRegion();
    $result = previewFile(CSV_HEADER."5103010101900001,Tanpa Tanggal,,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n");

    expect($result['invalid'][0]['reason'])->toContain('tanggal lahir');
});

it('menormalkan tanggal dd/mm/yyyy', function () {
    seedSampleBaliRegion();
    $result = previewFile(CSV_HEADER."5103010101900001,Wayan Slash,17/05/1990,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n");

    expect($result['invalid'])->toBeEmpty();
    expect($result['valid_rows'][0]['birth_date'])->toBe('1990-05-17');
});

it('hanya mengambil baris pertama saat nik ganda dalam berkas', function () {
    seedSampleBaliRegion();
    $result = previewFile(CSV_HEADER.
        "5103010101900001,Wayan Pertama,1990-01-01,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n".
        "5103010101900001,Wayan Kedua,1990-01-01,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n"
    );

    expect($result['total_valid'])->toBe(1);
    expect($result['valid_rows'][0]['name'])->toBe('Wayan Pertama');
    expect($result['duplicate_in_file'][0]['reason'])->toContain('ganda dalam berkas');
});

it('melewati nik yang sudah terdaftar di dpt', function () {
    $banjar = seedSampleBaliRegion();
    Voter::create([
        'nik' => '5103010101900001',
        'name' => 'Sudah Ada',
        'birth_date' => '1990-01-01',
        'banjar_id' => (string) $banjar->_id,
        'has_voted' => false,
    ]);

    $result = previewFile(CSV_HEADER.
        "5103010101900001,Duplikat Database,1990-01-01,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n".
        "5103011503880002,Pemilih Baru,1988-03-15,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n"
    );

    expect($result['total_valid'])->toBe(1);
    expect($result['valid_rows'][0]['nik'])->toBe('5103011503880002');
    expect($result['duplicate_in_database'])->toHaveCount(1);
});

it('membaca berkas pemisah titik koma dan bom utf-8', function () {
    seedSampleBaliRegion();
    $result = previewFile("\xEF\xBB\xBF"."nik;nama;tanggal_lahir;kabupaten;kecamatan;kelurahan;banjar\n5103010101900001;Wayan Semicolon;1990-01-01;Kabupaten Badung;Kecamatan Kuta Utara;Desa Canggu;Banjar Canggu\n");

    expect($result['invalid'])->toBeEmpty();
    expect($result['valid_rows'][0]['name'])->toBe('Wayan Semicolon');
});

it('mengabaikan baris kosong', function () {
    seedSampleBaliRegion();
    $result = previewFile(CSV_HEADER."5103010101900001,Wayan Ada,1990-01-01,Kabupaten Badung,Kecamatan Kuta Utara,Desa Canggu,Banjar Canggu\n,,,,,,\n\n");

    expect($result['total_read'])->toBe(1);
    expect($result['invalid'])->toBeEmpty();
});

it('menolak berkas selain excel dan csv', function () {
    $this->withToken(adminToken())
        ->post('/api/v1/admin/voters/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('dpt.pdf', 'apa saja'),
        ])
        ->assertStatus(422);
});

it('menyimpan baris yang dikonfirmasi admin dan memvalidasi ulang', function () {
    $banjar = seedSampleBaliRegion();

    $rows = [
        ['nik' => '999', 'name' => 'Nik Dipalsukan', 'birth_date' => '1990-01-01', 'banjar_id' => (string) $banjar->_id],
        ['nik' => '5103010101900001', 'name' => 'Wayan Asli', 'birth_date' => '1990-01-01', 'banjar_id' => (string) $banjar->_id],
        ['nik' => '5103011503880002', 'name' => 'Luh Valid', 'birth_date' => '1988-03-15', 'banjar_id' => (string) $banjar->_id],
    ];

    $this->withToken(adminToken())
        ->postJson('/api/v1/admin/voters/import/confirm', ['rows' => $rows])
        ->assertOk()
        ->assertJsonPath('imported', 2)
        ->assertJsonPath('skipped', 1);

    expect(Voter::count())->toBe(2);
    expect(Voter::where('nik', '5103011503880002')->first()->banjar_id)->toBe((string) $banjar->_id);
});
