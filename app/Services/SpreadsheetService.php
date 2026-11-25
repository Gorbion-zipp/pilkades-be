<?php

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;
use Shuchkin\SimpleXLS;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetService
{
    /**
     * Baca baris data (setelah header baris pertama) dari file .xlsx, .xls, atau .csv/.txt.
     *
     * @return array<int, array{line: int, values: array<int, string>}>
     */
    public function readRows(string $path, string $extension): array
    {
        $ext = strtolower($extension);

        return match ($ext) {
            'xlsx' => $this->readXlsx($path),
            'xls' => $this->readXls($path),
            'csv', 'txt' => $this->readCsv($path),
            default => throw new RuntimeException('Format berkas tidak didukung. Gunakan .xlsx, .xls, atau .csv.'),
        };
    }

    /**
     * Unduh file Excel (.xlsx) dengan proteksi sel string agar NIK tidak berubah jadi notasi ilmiah.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     * @param  array<int, int>  $stringColumnIndexes  Indeks kolom (0-based) yang dipaksa bertipe string
     */
    public function downloadXlsx(string $fileName, array $headers, array $rows, array $stringColumnIndexes = []): StreamedResponse
    {
        $styledHeader = array_map(
            fn (string $h): string => '<b><style bgcolor="#1E293B" color="#FFFFFF">'.htmlspecialchars($h, ENT_QUOTES, 'UTF-8').'</style></b>',
            $headers
        );

        $data = [$styledHeader];

        foreach ($rows as $row) {
            $formattedRow = [];
            foreach (array_values($row) as $idx => $cell) {
                if ($cell === null || $cell === '') {
                    $formattedRow[] = '';
                    continue;
                }

                if (in_array($idx, $stringColumnIndexes, true)) {
                    // Awalan null-byte (\0) memaksa SimpleXLSXGen menyimpan sel sebagai Teks (t="s").
                    $formattedRow[] = "\0".(string) $cell;
                } else {
                    $formattedRow[] = $cell;
                }
            }
            $data[] = $formattedRow;
        }

        $xlsx = SimpleXLSXGen::fromArray($data);

        return response()->streamDownload(function () use ($xlsx): void {
            echo $xlsx;
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Unduh file CSV UTF-8 dengan BOM agar terbaca rapi di Microsoft Excel.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public function downloadCsv(string $fileName, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($v) => $v === null ? '' : (string) $v, $row));
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<int, array{line: int, values: array<int, string>}>
     */
    private function readXlsx(string $path): array
    {
        $xlsx = SimpleXLSX::parse($path);

        if (! $xlsx) {
            throw new RuntimeException('Gagal membaca berkas Excel (.xlsx): '.SimpleXLSX::parseError());
        }

        return $this->normalizeMatrixRows($xlsx->rows());
    }

    /**
     * @return array<int, array{line: int, values: array<int, string>}>
     */
    private function readXls(string $path): array
    {
        $xls = SimpleXLS::parse($path);

        if (! $xls) {
            throw new RuntimeException('Gagal membaca berkas Excel (.xls): '.SimpleXLS::parseError());
        }

        return $this->normalizeMatrixRows($xls->rows());
    }

    /**
     * @param  array<int, array<int, mixed>>  $rawRows
     * @return array<int, array{line: int, values: array<int, string>}>
     */
    private function normalizeMatrixRows(array $rawRows): array
    {
        $rows = [];
        $line = 0;

        foreach ($rawRows as $rawRow) {
            $line++;

            if ($line === 1) {
                continue;
            }

            $values = array_map(fn ($value): string => trim((string) $value), $rawRow);

            if (implode('', $values) === '') {
                continue;
            }

            $rows[] = ['line' => $line, 'values' => $values];
        }

        return $rows;
    }

    /**
     * @return array<int, array{line: int, values: array<int, string>}>
     */
    private function readCsv(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return [];
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);

        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, (string) $contents);
        rewind($handle);

        $firstLine = (string) fgets($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($handle);

        $rows = [];
        $line = 0;

        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;

            if ($line === 1) {
                continue;
            }

            $values = array_map(fn ($value): string => trim((string) $value), $values);

            if (implode('', $values) === '') {
                continue;
            }

            $rows[] = ['line' => $line, 'values' => $values];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Konversi nilai tanggal string maupun serial numerik Excel menjadi Y-m-d.
     */
    public function normalizeDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Hilangkan jam apabila SimpleXLSX mengembalikan "YYYY-MM-DD 00:00:00"
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+\d{2}:\d{2}:\d{2}$/', $value, $matches)) {
            $value = $matches[1];
        }

        // Tangani Excel serial date number (misal 32874 -> 1990-01-01)
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value)) {
            $serial = (int) floor((float) $value);
            if ($serial >= 1 && $serial <= 100000) {
                $base = new DateTimeImmutable('1899-12-30');
                return $base->modify("+{$serial} days")->format('Y-m-d');
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
