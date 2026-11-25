<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection('mongodb')->create('election_periods', function (Blueprint $collection) {
            $collection->index('year');
            $collection->index('status');
        });

        Schema::connection('mongodb')->create('elections', function (Blueprint $collection) {
            $collection->index('period_id');
            $collection->index('position_type');
            $collection->index('region_id');
            $collection->index('status');
        });

        Schema::connection('mongodb')->create('election_rounds', function (Blueprint $collection) {
            $collection->index('election_id');
            $collection->index('status');
            $collection->unique(['election_id', 'round_number'], 'election_round_unique');
        });

        Schema::connection('mongodb')->create('voter_ballots', function (Blueprint $collection) {
            $collection->index('election_id');
            $collection->index('election_round_id');
            $collection->unique(['voter_id', 'election_round_id'], 'voter_ballot_unique');
        });

        Schema::connection('mongodb')->table('candidates', function (Blueprint $collection) {
            $collection->index('election_id');
        });

        Schema::connection('mongodb')->table('votes', function (Blueprint $collection) {
            $collection->index('election_id');
            $collection->index('election_round_id');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->drop('voter_ballots');
        Schema::connection('mongodb')->drop('election_rounds');
        Schema::connection('mongodb')->drop('elections');
        Schema::connection('mongodb')->drop('election_periods');
    }
};
