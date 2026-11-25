<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class ElectionRound extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_NEEDS_RUNOFF = 'needs_runoff';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_CLOSED,
        self::STATUS_NEEDS_RUNOFF,
        self::STATUS_COMPLETED,
    ];

    protected $connection = 'mongodb';

    protected $collection = 'election_rounds';

    protected $fillable = [
        'election_id',
        'round_number',
        'status',
        'start_datetime',
        'end_datetime',
        'advancing_candidate_ids',
    ];

    protected $casts = [
        'round_number' => 'integer',
    ];

    public function election()
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApi(?int $totalVotes = null): array
    {
        $payload = [
            'id' => (string) $this->_id,
            'election_id' => (string) $this->election_id,
            'round_number' => (int) $this->round_number,
            'status' => $this->status ?? self::STATUS_DRAFT,
            'start_datetime' => $this->start_datetime,
            'end_datetime' => $this->end_datetime,
            'advancing_candidate_ids' => is_array($this->advancing_candidate_ids) ? $this->advancing_candidate_ids : [],
        ];

        if ($totalVotes !== null) {
            $payload['total_votes'] = $totalVotes;
        }

        return $payload;
    }
}
