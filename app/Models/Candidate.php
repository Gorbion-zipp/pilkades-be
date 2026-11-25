<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Candidate extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'candidates';

    protected $fillable = [
        'election_id',
        'candidate_number',
        'name',
        'vice_name',
        'party_name',
        'vision_mission',
        'photo_url',
    ];

    public function votes()
    {
        return $this->hasMany(Vote::class, 'candidate_id');
    }

    /**
     * Bentuk data paslon yang dikirim ke frontend.
     *
     * @return array<string, mixed>
     */
    public function toApi(int $voteCount = 0): array
    {
        return [
            'id' => (string) $this->_id,
            'election_id' => $this->election_id ? (string) $this->election_id : null,
            'candidate_number' => $this->candidate_number,
            'name' => $this->name,
            'vice_name' => $this->vice_name,
            'party_name' => $this->party_name,
            'vision_mission' => $this->vision_mission,
            'photo_url' => $this->photo_url,
            'votes' => $voteCount,
        ];
    }
}
