<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Mencatat partisipasi pemilih per putaran pemilihan tanpa menyimpan pilihan paslonnya.
 * Dengan pemisahan ini, surat suara di koleksi `votes` tetap anonim.
 */
class VoterBallot extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'voter_ballots';

    protected $fillable = [
        'voter_id',
        'election_id',
        'election_round_id',
        'voted_at',
    ];
}
