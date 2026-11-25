<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\Voter;
use App\Services\SpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoterImportController extends Controller
{
    private const COLUMNS = ['nik', 'nama', 'tanggal_lahir', 'kabupaten', 'kecamatan', 'kelurahan', 'banjar'];

    public function __construct(private readonly SpreadsheetService $spreadsheet) {}

    public function template(Request $request): StreamedResponse
    {
        $format = strtolower($request->query('format', 'xlsx'));
        $date = now()->format('Y-m-d');

        $sampleRows = [
            ['5103010101900011', 'I Wayan Dharma Putra', '1990-01-01', 'Kabupaten Badung', 'Kecamatan Kuta Utara', 'Desa Canggu', 'Banjar Canggu'],
            ['5103011503880012', 'Ni Luh Putu Sari Dewi', '1988-03-15', 'Kabupaten Badung', 'Kecamatan Kuta Utara', 'Desa Canggu', 'Banjar Pipitan'],
        ];

        if ($format === 'csv') {
            return $this->spreadsheet->downloadCsv("template-dpt-pilkades-{$date}.csv", self::COLUMNS, $sampleRows);
        }

        // Indeks 0 (nik) dan 2 (tanggal_lahir) dipaksa sebagai teks agar Excel tidak mengubahnya.
        return $this->spreadsheet->downloadXlsx("template-dpt-pilkades-{$date}.xlsx", self::COLUMNS, $sampleRows, [0, 2]);
    }

    /**
     * Baca dan verifikasi berkas (.xlsx, .xls, atau .csv) tanpa menyimpan apa pun.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:5120']);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        if (! in_array($ext, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            return response()->json([
                'message' => 'Format berkas harus Excel (.xlsx / .xls) atau CSV (.csv).',
            ], 422);
        }

        try {
            $rows = $this->spreadsheet->readRows($file->getRealPath(), $ext);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($rows === []) {
            return response()->json([
                'message' => 'Berkas kosong atau hanya berisi baris header.',
            ], 422);
        }

        return response()->json($this->verifyRows($rows));
    }

    /**
     * Simpan baris yang dikirim balik oleh frontend.
     *
     * Baris divalidasi ulang di sini supaya data dari browser tidak
     * langsung dipercaya.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.nik' => 'required|string',
            'rows.*.name' => 'required|string',
            'rows.*.birth_date' => 'required|string',
            'rows.*.banjar_id' => 'required|string',
        ]);

        $existingNik = Voter::whereIn('nik', array_column($request->input('rows'), 'nik'))
            ->pluck('nik')
            ->map(fn ($nik) => (string) $nik)
            ->all();

        $banjarIds = array_values(array_unique(array_column($request->input('rows'), 'banjar_id')));
        $banjarMap = Region::whereIn('_id', $banjarIds)
            ->where('level', Region::LEVEL_BANJAR)
            ->get()
            ->keyBy(fn (Region $r) => (string) $r->_id)
            ->all();

        $payload = [];
        $seenNik = [];
        $skipped = 0;
        $timestamp = now();

        foreach ($request->input('rows') as $row) {
            $nik = trim((string) $row['nik']);
            $name = trim((string) $row['name']);
            $birthDate = $this->spreadsheet->normalizeDate((string) $row['birth_date']);
            $banjarId = trim((string) $row['banjar_id']);
            $banjar = $banjarMap[$banjarId] ?? null;

            $invalid = $this->findIdentityError($nik, $name, (string) $row['birth_date']) !== null || $banjar === null;

            if ($invalid || $birthDate === null || isset($seenNik[$nik]) || in_array($nik, $existingNik, true)) {
                $skipped++;

                continue;
            }

            $seenNik[$nik] = true;

            $payload[] = [
                'nik' => $nik,
                'name' => $name,
                'birth_date' => $birthDate,
                'kabupaten_id' => $banjar->kabupaten_id,
                'kecamatan_id' => $banjar->kecamatan_id,
                'kelurahan_id' => $banjar->kelurahan_id,
                'banjar_id' => (string) $banjar->_id,
                'has_voted' => false,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($payload === []) {
            return response()->json([
                'message' => 'Tidak ada data baru yang bisa diimpor. Seluruh baris sudah terdaftar atau tidak valid.',
            ], 422);
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            Voter::insert($chunk);
        }

        $message = count($payload).' data pemilih berhasil diimpor ke DPT.';

        if ($skipped > 0) {
            $message .= ' '.$skipped.' data dilewati karena sudah terdaftar atau tidak valid.';
        }

        return response()->json([
            'message' => $message,
            'imported' => count($payload),
            'skipped' => $skipped,
        ]);
    }

    /**
     * Klasifikasikan setiap baris: valid, tidak valid, ganda dalam berkas,
     * atau sudah terdaftar di DPT.
     *
     * @param  array<int, array{line: int, values: array<int, string>}>  $rows
     * @return array<string, mixed>
     */
    private function verifyRows(array $rows): array
    {
        $existingNik = Voter::pluck('nik')->map(fn ($nik) => (string) $nik)->all();
        $regionLookup = $this->buildRegionLookup();

        $validRows = [];
        $preview = [];
        $invalid = [];
        $duplicateInFile = [];
        $duplicateInDatabase = [];
        $seenNik = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $nik = $row['values'][0] ?? '';
            $name = $row['values'][1] ?? '';
            $rawBirthDate = $row['values'][2] ?? '';
            $rawKab = $row['values'][3] ?? '';
            $rawKec = $row['values'][4] ?? '';
            $rawKel = $row['values'][5] ?? '';
            $rawBanjar = $row['values'][6] ?? '';

            if ($reason = $this->findIdentityError($nik, $name, $rawBirthDate)) {
                $invalid[] = ['line' => $line, 'nik' => $nik, 'name' => $name, 'reason' => $reason];

                continue;
            }

            [$resolved, $regionError] = $this->resolveRegionHierarchy($regionLookup, $rawKab, $rawKec, $rawKel, $rawBanjar);

            if ($regionError !== null || $resolved === null) {
                $invalid[] = ['line' => $line, 'nik' => $nik, 'name' => $name, 'reason' => $regionError ?? 'Wilayah tidak valid.'];

                continue;
            }

            if (isset($seenNik[$nik])) {
                $duplicateInFile[] = [
                    'line' => $line,
                    'nik' => $nik,
                    'name' => $name,
                    'reason' => 'NIK ganda dalam berkas (sudah ada di baris '.$seenNik[$nik].').',
                ];

                continue;
            }

            if (in_array($nik, $existingNik, true)) {
                $duplicateInDatabase[] = [
                    'line' => $line,
                    'nik' => $nik,
                    'name' => $name,
                    'reason' => 'NIK sudah terdaftar di DPT.',
                ];

                continue;
            }

            $seenNik[$nik] = $line;
            $birthDate = $this->spreadsheet->normalizeDate($rawBirthDate);

            $validRows[] = [
                'nik' => $nik,
                'name' => $name,
                'birth_date' => $birthDate,
                'kabupaten_id' => (string) $resolved['kabupaten']->_id,
                'kecamatan_id' => (string) $resolved['kecamatan']->_id,
                'kelurahan_id' => (string) $resolved['kelurahan']->_id,
                'banjar_id' => (string) $resolved['banjar']->_id,
            ];

            $preview[] = [
                'line' => $line,
                'nik' => $nik,
                'name' => $name,
                'birth_date' => $birthDate,
                'kabupaten_name' => $resolved['kabupaten']->name,
                'kecamatan_name' => $resolved['kecamatan']->name,
                'kelurahan_name' => $resolved['kelurahan']->name,
                'banjar_name' => $resolved['banjar']->name,
            ];
        }

        return [
            'total_read' => count($rows),
            'total_valid' => count($validRows),
            'valid_rows' => $validRows,
            'preview' => array_slice($preview, 0, 50),
            'invalid' => $invalid,
            'duplicate_in_file' => $duplicateInFile,
            'duplicate_in_database' => $duplicateInDatabase,
        ];
    }

    private function findIdentityError(string $nik, string $name, string $birthDate): ?string
    {
        if ($nik === '') {
            return 'Kolom NIK wajib diisi.';
        }

        // Excel sering mengubah NIK 16 digit menjadi 5,1030101019E+15.
        if (preg_match('/e\+?\d+/i', $nik)) {
            return 'NIK terbaca sebagai notasi ilmiah ('.$nik.'). Format kolom NIK sebagai Teks di Excel, lalu simpan ulang.';
        }

        if (! preg_match('/^\d{16}$/', $nik)) {
            return 'NIK harus tepat 16 digit angka (terbaca '.mb_strlen($nik).' karakter).';
        }

        if ($name === '') {
            return 'Kolom nama wajib diisi.';
        }

        if (mb_strlen($name) > 255) {
            return 'Nama melebihi 255 karakter.';
        }

        if ($birthDate === '') {
            return 'Kolom tanggal lahir wajib diisi (dipakai pemilih untuk login).';
        }

        if ($this->spreadsheet->normalizeDate($birthDate) === null) {
            return 'Tanggal lahir "'.$birthDate.'" tidak valid. Gunakan format YYYY-MM-DD atau DD/MM/YYYY.';
        }

        return null;
    }

    /**
     * Bangun indeks wilayah in-memory untuk lookup cepat per (level, parent_id).
     *
     * @return array<string, array<string, array<string, Region>>>
     */
    private function buildRegionLookup(): array
    {
        $lookup = [];

        foreach (Region::all() as $region) {
            $level = (string) $region->level;
            $parentKey = $region->parent_id ? (string) $region->parent_id : '_root';

            $exactKey = mb_strtolower(trim((string) $region->name));
            $strippedKey = Region::normalizeName((string) $region->name, $level);

            $lookup[$level][$parentKey]['exact'][$exactKey] = $region;
            $lookup[$level][$parentKey]['stripped'][$strippedKey] = $region;
        }

        return $lookup;
    }

    /**
     * @param  array<string, mixed>  $lookup
     */
    private function findRegionMatch(array $lookup, string $level, string $parentKey, string $input): ?Region
    {
        $exactKey = mb_strtolower(trim($input));
        if (isset($lookup[$level][$parentKey]['exact'][$exactKey])) {
            return $lookup[$level][$parentKey]['exact'][$exactKey];
        }

        $strippedKey = Region::normalizeName($input, $level);
        if (isset($lookup[$level][$parentKey]['stripped'][$strippedKey])) {
            return $lookup[$level][$parentKey]['stripped'][$strippedKey];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $lookup
     * @return array{0: array<string, Region>|null, 1: string|null}
     */
    private function resolveRegionHierarchy(array $lookup, string $rawKab, string $rawKec, string $rawKel, string $rawBanjar): array
    {
        if ($rawKab === '' || $rawKec === '' || $rawKel === '' || $rawBanjar === '') {
            return [null, 'Kolom kabupaten, kecamatan, kelurahan, dan banjar wajib diisi lengkap.'];
        }

        $kab = $this->findRegionMatch($lookup, Region::LEVEL_KABUPATEN, '_root', $rawKab);
        if (! $kab) {
            return [null, 'Kabupaten/Kota "'.$rawKab.'" tidak ditemukan di Data Master Wilayah Bali.'];
        }

        $kec = $this->findRegionMatch($lookup, Region::LEVEL_KECAMATAN, (string) $kab->_id, $rawKec);
        if (! $kec) {
            return [null, 'Kecamatan "'.$rawKec.'" tidak ditemukan di bawah '.$kab->name.'.'];
        }

        $kel = $this->findRegionMatch($lookup, Region::LEVEL_KELURAHAN, (string) $kec->_id, $rawKel);
        if (! $kel) {
            return [null, 'Kelurahan/Desa "'.$rawKel.'" tidak ditemukan di bawah '.$kec->name.'.'];
        }

        $banjar = $this->findRegionMatch($lookup, Region::LEVEL_BANJAR, (string) $kel->_id, $rawBanjar);
        if (! $banjar) {
            return [null, 'Banjar/Lingkungan "'.$rawBanjar.'" tidak ditemukan di bawah '.$kel->name.'.'];
        }

        return [[
            'kabupaten' => $kab,
            'kecamatan' => $kec,
            'kelurahan' => $kel,
            'banjar' => $banjar,
        ], null];
    }
}
