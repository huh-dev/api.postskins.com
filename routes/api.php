<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\Dev\DevWalletController;
use App\Http\Controllers\Dev\TradeLabController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SellerController;
use App\Http\Controllers\TradeController;
use App\Http\Controllers\TradeOfferController;
use App\Http\Controllers\TradePostController;
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

    Route::get('/seller/status', [SellerController::class, 'status'])->name('seller.status');
    Route::post('/seller/connect', [SellerController::class, 'startConnect'])->name('seller.connect');
    Route::get('/seller/connect/{id}', [SellerController::class, 'connectStatus'])->name('seller.connect.status');
    Route::delete('/seller/connect', [SellerController::class, 'disconnect'])->name('seller.disconnect');

    Route::get('/catalog/items', [CatalogController::class, 'items'])->name('catalog.items');

    // Trade posts: the market feed and a user's own posts.
    Route::get('/posts', [TradePostController::class, 'index'])->name('posts.index');
    Route::get('/posts/mine', [TradePostController::class, 'mine'])->name('posts.mine');
    Route::get('/posts/{post}', [TradePostController::class, 'show'])->name('posts.show');
    Route::post('/posts', [TradePostController::class, 'store'])
        ->middleware('not.suspended')
        ->name('posts.store');
    Route::delete('/posts/{post}', [TradePostController::class, 'destroy'])->name('posts.destroy');

    // The caller's own offers, across every post, for their account page.
    Route::get('/offers/sent', [TradeOfferController::class, 'sent'])->name('offers.sent');
    Route::get('/offers/received', [TradeOfferController::class, 'received'])->name('offers.received');

    // Counter-offers against a post.
    Route::get('/posts/{post}/offers', [TradeOfferController::class, 'index'])->name('posts.offers.index');
    Route::post('/posts/{post}/offers', [TradeOfferController::class, 'store'])
        ->middleware('not.suspended')
        ->name('posts.offers.store');
    Route::delete('/offers/{offer}', [TradeOfferController::class, 'destroy'])->name('offers.destroy');
    Route::post('/offers/{offer}/accept', [TradeOfferController::class, 'accept'])
        ->middleware('not.suspended')
        ->name('offers.accept');

    // Executed trades.
    Route::get('/trades/mine', [TradeController::class, 'mine'])->name('trades.mine');
    Route::get('/trades/{trade}', [TradeController::class, 'show'])->name('trades.show');
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
