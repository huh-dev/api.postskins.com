<?php

use App\Http\Controllers\InventoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/inventory', [InventoryController::class, 'index'])
    ->middleware('auth:sanctum')
    ->name('inventory.index');
