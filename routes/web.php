<?php

use Illuminate\Support\Facades\Route;

// Project ini hanya menyediakan API. Frontend ada di project pilkades-web.
Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'api' => url('/api/v1'),
    ]);
});
