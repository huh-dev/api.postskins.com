<?php

namespace App\Jobs;

use App\Models\Trade;
use App\Models\User;
use App\Services\Steam\InventoryProvider;
use App\Services\SteamInventorySync;
use App\Services\Trading\TradeVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Fallback verification for a trade with no Steam offer id to track (lab or
 * manual delivery). Reads every party's inventory once; a two-sided swap touches
 * two inventories, so both must read cleanly before the verifier runs. A
 * failed/private read is never used to advance a trade — we back off and retry.
 */
class VerifyTradeInventories implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tradeId) {}

    public function handle(InventoryProvider $provider, SteamInventorySync $sync, TradeVerifier $verifier): void
    {
        $trade = Trade::with('items')->find($this->tradeId);

        if ($trade === null || ! $trade->status->isActive()) {
            return;
        }

        $userIds = $trade->items
            ->flatMap(fn ($leg): array => [(int) $leg->giver_id, (int) $leg->receiver_id])
            ->unique();

        foreach (User::query()->whereKey($userIds)->get() as $user) {
            if (! $this->refreshInventory($provider, $sync, $user, $trade->app_id, $trade->context_id)) {
                Log::info('VerifyTradeInventories: read not clean, backing off', ['trade_id' => $trade->id, 'user_id' => $user->id]);
                $this->backOff($trade);

                return;
            }
        }

        $verifier->verify($trade);
    }

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

    private function backOff(Trade $trade): void
    {
        $trade->forceFill([
            'next_poll_at' => now()->addSeconds((int) config('trades.poll.min_seconds')),
            'last_polled_at' => now(),
        ])->save();
    }
}
