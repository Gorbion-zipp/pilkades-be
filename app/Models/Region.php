<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Region extends Model
{
    public const LEVEL_KABUPATEN = 'kabupaten';

    public const LEVEL_KECAMATAN = 'kecamatan';

    public const LEVEL_KELURAHAN = 'kelurahan';

    public const LEVEL_BANJAR = 'banjar';

    public const LEVELS = [
        self::LEVEL_KABUPATEN,
        self::LEVEL_KECAMATAN,
        self::LEVEL_KELURAHAN,
        self::LEVEL_BANJAR,
    ];

    protected $connection = 'mongodb';

    protected $collection = 'regions';

    protected $fillable = [
        'name',
        'level',
        'parent_id',
        'code',
        'kabupaten_id',
        'kecamatan_id',
        'kelurahan_id',
    ];

    /**
     * Level induk yang diizinkan untuk setiap tingkat wilayah.
     */
    public static function expectedParentLevel(string $level): ?string
    {
        return match ($level) {
            self::LEVEL_KABUPATEN => null,
            self::LEVEL_KECAMATAN => self::LEVEL_KABUPATEN,
            self::LEVEL_KELURAHAN => self::LEVEL_KECAMATAN,
            self::LEVEL_BANJAR => self::LEVEL_KELURAHAN,
            default => null,
        };
    }

    /**
     * Label level wilayah yang ramah dibaca pengguna.
     */
    public static function levelLabel(string $level): string
    {
        return match ($level) {
            self::LEVEL_KABUPATEN => 'Kabupaten / Kota',
            self::LEVEL_KECAMATAN => 'Kecamatan',
            self::LEVEL_KELURAHAN => 'Kelurahan / Desa',
            self::LEVEL_BANJAR => 'Banjar / Lingkungan',
            default => ucfirst($level),
        };
    }

    /**
     * Normalisasi nama wilayah untuk pencocokan fleksibel saat impor.
     */
    public static function normalizeName(string $name, ?string $level = null): string
    {
        $clean = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));

        if ($level !== null) {
            $prefixes = match ($level) {
                self::LEVEL_KABUPATEN => ['kabupaten ', 'kab. ', 'kab ', 'kota '],
                self::LEVEL_KECAMATAN => ['kecamatan ', 'kec. ', 'kec '],
                self::LEVEL_KELURAHAN => ['kelurahan ', 'kel. ', 'kel ', 'desa dinas ', 'desa adat ', 'desa '],
                self::LEVEL_BANJAR => ['banjar dinas ', 'banjar adat ', 'banjar ', 'br. ', 'br ', 'lingkungan ', 'lingk. '],
                default => [],
            };

            foreach ($prefixes as $prefix) {
                if (str_starts_with($clean, $prefix)) {
                    $clean = trim(mb_substr($clean, mb_strlen($prefix)));
                    break;
                }
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, Region>  $regionMap
     * @return array<string, mixed>
     */
    public function toApi(array $regionMap = []): array
    {
        $id = (string) $this->_id;

        return [
            'id' => $id,
            'name' => $this->name,
            'level' => $this->level,
            'level_label' => self::levelLabel((string) $this->level),
            'parent_id' => $this->parent_id,
            'parent_name' => $this->parent_id && isset($regionMap[$this->parent_id])
                ? $regionMap[$this->parent_id]->name
                : null,
            'code' => $this->code,
            'kabupaten_id' => $this->kabupaten_id,
            'kecamatan_id' => $this->kecamatan_id,
            'kelurahan_id' => $this->kelurahan_id,
            'kabupaten_name' => $this->kabupaten_id && isset($regionMap[$this->kabupaten_id])
                ? $regionMap[$this->kabupaten_id]->name
                : ($this->level === self::LEVEL_KABUPATEN ? $this->name : null),
            'kecamatan_name' => $this->kecamatan_id && isset($regionMap[$this->kecamatan_id])
                ? $regionMap[$this->kecamatan_id]->name
                : ($this->level === self::LEVEL_KECAMATAN ? $this->name : null),
            'kelurahan_name' => $this->kelurahan_id && isset($regionMap[$this->kelurahan_id])
                ? $regionMap[$this->kelurahan_id]->name
                : ($this->level === self::LEVEL_KELURAHAN ? $this->name : null),
        ];
    }
}
