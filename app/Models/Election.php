<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Election extends Model
{
    public const POSITION_BUPATI = 'bupati';

    public const POSITION_DPRD = 'dprd_kabkota';

    public const POSITION_PERBEKEL = 'perbekel';

    public const POSITIONS = [
        self::POSITION_BUPATI,
        self::POSITION_DPRD,
        self::POSITION_PERBEKEL,
    ];

    public const MODE_SINGLE_WINNER = 'single_winner';

    public const MODE_MULTI_SEAT = 'multi_seat';

    public const MODES = [
        self::MODE_SINGLE_WINNER,
        self::MODE_MULTI_SEAT,
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
    ];

    protected $connection = 'mongodb';

    protected $collection = 'elections';

    protected $fillable = [
        'period_id',
        'name',
        'position_type',
        'level',
        'region_id',
        'election_mode',
        'win_threshold_percent',
        'seat_count',
        'dapil_name',
        'dapil_region_ids',
        'status',
    ];

    protected $casts = [
        'win_threshold_percent' => 'float',
        'seat_count' => 'integer',
    ];

    public static function expectedLevelForPosition(string $positionType): ?string
    {
        return match ($positionType) {
            self::POSITION_BUPATI, self::POSITION_DPRD => Region::LEVEL_KABUPATEN,
            self::POSITION_PERBEKEL => Region::LEVEL_KELURAHAN,
            default => null,
        };
    }

    public static function defaultModeForPosition(string $positionType): string
    {
        return $positionType === self::POSITION_DPRD
            ? self::MODE_MULTI_SEAT
            : self::MODE_SINGLE_WINNER;
    }

    public static function positionLabel(string $positionType): string
    {
        return match ($positionType) {
            self::POSITION_BUPATI => 'Bupati / Wali Kota',
            self::POSITION_DPRD => 'Anggota DPRD Kab/Kota',
            self::POSITION_PERBEKEL => 'Perbekel / Kepala Desa',
            default => $positionType,
        };
    }

    public function rounds()
    {
        return $this->hasMany(ElectionRound::class, 'election_id');
    }

    public function candidates()
    {
        return $this->hasMany(Candidate::class, 'election_id');
    }

    /**
     * @param  array<string, Region>  $regionMap
     * @param  array<int, array<string, mixed>>|null  $roundsData
     * @return array<string, mixed>
     */
    public function toApi(array $regionMap = [], ?array $roundsData = null, ?int $candidatesCount = null): array
    {
        $region = $regionMap[(string) $this->region_id] ?? null;
        $dapilIds = is_array($this->dapil_region_ids) ? $this->dapil_region_ids : [];
        $dapilRegions = array_values(array_filter(array_map(function ($id) use ($regionMap) {
            $r = $regionMap[(string) $id] ?? null;

            return $r ? ['id' => (string) $r->_id, 'name' => $r->name] : null;
        }, $dapilIds)));

        $payload = [
            'id' => (string) $this->_id,
            'period_id' => (string) $this->period_id,
            'name' => $this->name,
            'position_type' => $this->position_type,
            'position_label' => self::positionLabel((string) $this->position_type),
            'level' => $this->level,
            'region_id' => (string) $this->region_id,
            'region_name' => $region?->name,
            'election_mode' => $this->election_mode,
            'win_threshold_percent' => $this->win_threshold_percent !== null ? (float) $this->win_threshold_percent : null,
            'seat_count' => $this->seat_count !== null ? (int) $this->seat_count : null,
            'dapil_name' => $this->dapil_name,
            'dapil_region_ids' => $dapilIds,
            'dapil_regions' => $dapilRegions,
            'status' => $this->status ?? self::STATUS_DRAFT,
        ];

        if ($roundsData !== null) {
            $payload['rounds'] = $roundsData;
        }

        if ($candidatesCount !== null) {
            $payload['candidates_count'] = $candidatesCount;
        }

        return $payload;
    }
}
