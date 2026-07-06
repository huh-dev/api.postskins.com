<?php

use App\Http\Controllers\Auth\SteamController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::controller(SteamController::class)->group(function () {
    Route::get('/auth/steam/redirect', 'redirect')->name('auth.steam.redirect');
    Route::get('/auth/steam/callback', 'callback')->name('auth.steam.callback');
    Route::post('/logout', 'logout')->middleware('auth:sanctum')->name('logout');
});
