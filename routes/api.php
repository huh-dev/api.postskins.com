<?php

use App\Http\Controllers\Dev\DevWalletController;
use App\Http\Controllers\Dev\TradeLabController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/inventory', [InventoryController::class, 'index'])
    ->middleware('auth:sanctum')
    ->name('inventory.index');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wallet', [WalletController::class, 'show'])->name('wallet.show');
    Route::put('/user/trade-url', [ProfileController::class, 'updateTradeUrl'])->name('user.trade-url');

    Route::get('/listings', [ListingController::class, 'index'])->name('listings.index');
    Route::get('/listings/mine', [ListingController::class, 'mine'])->name('listings.mine');
    Route::post('/listings', [ListingController::class, 'store'])
        ->middleware('not.suspended')
        ->name('listings.store');
    Route::delete('/listings/{listing}', [ListingController::class, 'destroy'])->name('listings.destroy');
    Route::post('/listings/{listing}/purchase', [ListingController::class, 'purchase'])
        ->middleware('not.suspended')
        ->name('listings.purchase');

    Route::post('/trades', [TradeController::class, 'store'])
        ->middleware('not.suspended')
        ->name('trades.store');

    Route::get('/trades/{trade}', [TradeController::class, 'show'])
        ->name('trades.show');
});

// Local-only helpers for testing without a real deposit flow or Steam trades.
if (app()->environment('local')) {
    Route::post('/dev/credit', [DevWalletController::class, 'credit'])->middleware('auth:sanctum');

    Route::prefix('dev/trade-lab')->group(function () {
        Route::get('/state', [TradeLabController::class, 'state']);
        Route::post('/reset', [TradeLabController::class, 'reset']);
        Route::post('/sync-seller', [TradeLabController::class, 'syncSeller']);
        Route::post('/demo-seller', [TradeLabController::class, 'demoSeller']);
        Route::post('/buy', [TradeLabController::class, 'buy']);
        Route::post('/simulate-received', [TradeLabController::class, 'simulateReceived']);
        Route::post('/simulate-reversal', [TradeLabController::class, 'simulateReversal']);
    });
}
