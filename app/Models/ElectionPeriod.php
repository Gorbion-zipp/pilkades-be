<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ElectionPeriod extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_ARCHIVED,
    ];

    protected $connection = 'mongodb';

    protected $collection = 'election_periods';

    protected $fillable = [
        'name',
        'year',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    public static function activePeriod(): ?self
    {
        return self::where('status', self::STATUS_ACTIVE)->first();
    }

    public function elections()
    {
        return $this->hasMany(Election::class, 'period_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(?int $electionsCount = null): array
    {
        $payload = [
            'id' => (string) $this->_id,
            'name' => $this->name,
            'year' => (int) $this->year,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status ?? self::STATUS_DRAFT,
        ];

        if ($electionsCount !== null) {
            $payload['elections_count'] = $electionsCount;
        }

        return $payload;
    }
}
