<?php

use App\Http\Controllers\DordieWatchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/dordiewatch/config', [DordieWatchController::class, 'config'])
    ->name('dordiewatch.config');

Route::get('/dordiewatch/media/{media}', [DordieWatchController::class, 'show'])
    ->middleware('signed')
    ->name('dordiewatch.media');

Route::post('/dordiewatch/library', [DordieWatchController::class, 'library'])
    ->middleware('signed')
    ->name('dordiewatch.library');
