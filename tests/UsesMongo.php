<?php

namespace Tests;

use App\Models\Admin;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\ElectionPeriod;
use App\Models\ElectionRound;
use App\Models\Region;
use App\Models\Vote;
use App\Models\Voter;
use App\Models\VoterBallot;

/**
 * MongoDB tidak mendukung rollback transaksi seperti RefreshDatabase pada SQL,
 * jadi setiap tes dimulai dengan koleksi yang sudah dikosongkan.
 */
trait UsesMongo
{
    protected function setUpUsesMongo(): void
    {
        ElectionPeriod::truncate();
        Election::truncate();
        ElectionRound::truncate();
        VoterBallot::truncate();
        Region::truncate();
        Voter::truncate();
        Candidate::truncate();
        Vote::truncate();
        Admin::truncate();
    }
}
