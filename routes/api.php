<?php

use App\Http\Controllers\Admin\CandidateController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ElectionController;
use App\Http\Controllers\Admin\ElectionPeriodController;
use App\Http\Controllers\Admin\ElectionRoundController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\RegionController;
use App\Http\Controllers\Admin\VoterController;
use App\Http\Controllers\Admin\VoterImportController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LiveCountController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Route;

// --- Publik ---
Route::post('/auth/voter/login', [AuthController::class, 'loginVoter']);
Route::post('/auth/admin/login', [AuthController::class, 'loginAdmin']);
Route::get('/live-count', [LiveCountController::class, 'index']);

// --- Perlu token (peran apa pun) ---
Route::middleware('jwt')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
});

// --- Pemilih ---
Route::middleware('jwt:voter')->group(function () {
    Route::get('/vote/ballots', [VoteController::class, 'ballots']);
    Route::get('/vote/candidates', [VoteController::class, 'candidates']);
    Route::post('/vote', [VoteController::class, 'store']);
});

// --- Admin ---
Route::middleware('jwt:admin')->prefix('admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/reset-election', [DashboardController::class, 'resetElection']);

    Route::get('/regions', [RegionController::class, 'index']);
    Route::post('/regions', [RegionController::class, 'store']);
    Route::put('/regions/{id}', [RegionController::class, 'update']);
    Route::delete('/regions/{id}', [RegionController::class, 'destroy']);

    Route::get('/periods', [ElectionPeriodController::class, 'index']);
    Route::post('/periods', [ElectionPeriodController::class, 'store']);
    Route::put('/periods/{id}', [ElectionPeriodController::class, 'update']);
    Route::post('/periods/{id}/activate', [ElectionPeriodController::class, 'activate']);
    Route::delete('/periods/{id}', [ElectionPeriodController::class, 'destroy']);

    Route::get('/elections', [ElectionController::class, 'index']);
    Route::get('/elections/{id}', [ElectionController::class, 'show']);
    Route::post('/elections', [ElectionController::class, 'store']);
    Route::put('/elections/{id}', [ElectionController::class, 'update']);
    Route::delete('/elections/{id}', [ElectionController::class, 'destroy']);

    Route::post('/elections/{electionId}/rounds', [ElectionRoundController::class, 'store']);
    Route::put('/rounds/{id}', [ElectionRoundController::class, 'update']);
    Route::post('/rounds/{id}/activate', [ElectionRoundController::class, 'activate']);
    Route::post('/rounds/{id}/close', [ElectionRoundController::class, 'close']);
    Route::get('/rounds/{id}/evaluation', [ElectionRoundController::class, 'evaluation']);

    Route::get('/voters/template', [VoterImportController::class, 'template']);
    Route::post('/voters/import/preview', [VoterImportController::class, 'preview']);
    Route::post('/voters/import/confirm', [VoterImportController::class, 'confirm']);

    Route::post('/voters', [VoterController::class, 'store']);
    Route::post('/voters/{id}/reset', [VoterController::class, 'resetStatus']);
    Route::delete('/voters/{id}', [VoterController::class, 'destroy']);

    Route::get('/candidates', [CandidateController::class, 'index']);
    Route::post('/candidates', [CandidateController::class, 'store']);
    Route::post('/candidates/{id}', [CandidateController::class, 'update']);
    Route::delete('/candidates/{id}', [CandidateController::class, 'destroy']);

    Route::get('/export/election/{electionId}', [ExportController::class, 'exportElection']);
    Route::get('/export/voters', [ExportController::class, 'exportVoters']);
});
