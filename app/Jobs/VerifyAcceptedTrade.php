<?php

namespace App\Jobs;

use App\Enums\TradeStatus;
use App\Models\Trade;
use App\Models\User;
use App\Services\Steam\InventoryProvider;
use App\Services\SteamInventorySync;
use App\Services\Trading\TradeVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Watches an accepted trade for a rollback during the protection window using
 * only the two parties' PUBLIC inventories — no seller session/token, so a
 * seller who reverses (and never returns) cannot prevent detection. A rollback
 * returns the sold asset to the seller and removes the received copy from the
 * buyer; either is a reversal.
 */
class VerifyAcceptedTrade implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tradeId) {}

    public function handle(InventoryProvider $provider, SteamInventorySync $sync, TradeVerifier $verifier): void
    {
        $trade = Trade::with(['seller', 'buyer'])->find($this->tradeId);

        if ($trade === null || $trade->status !== TradeStatus::Accepted) {
            return;
        }

        $sellerReadOk = $this->refreshInventory($provider, $sync, $trade->seller, $trade->app_id, $trade->context_id);
        $buyerReadOk = $this->refreshInventory($provider, $sync, $trade->buyer, $trade->app_id, $trade->context_id);

        $verifier->reviewAcceptedTrade($trade, $sellerReadOk, $buyerReadOk);
    }

    /**
     * Fetch + persist a user's public inventory. Returns whether the read was
     * clean — only a clean read may advance the trade.
     */
    private function refreshInventory(InventoryProvider $provider, SteamInventorySync $sync, User $user, int $appId, int $contextId): bool
    {
        if ($user->steam_id === null) {
            return false;
        }

        $result = $provider->fetch($user->steam_id, $appId, $contextId);

        if ($result->status !== 'ok') {
            return false;
        }

        $sync->sync($user, $appId, $contextId, $result->items);

        return true;
    }
}
