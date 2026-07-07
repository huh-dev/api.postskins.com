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
use Illuminate\Support\Collection;

/**
 * Reads one buyer's inventory once, then verifies all of their due trades
 * against it. Batching per buyer (not per trade) keeps Steam reads to a minimum.
 * A failed/private read is never used to advance a trade — we only verify after
 * a successful read, so an outage can't wrongly release funds or suspend anyone.
 */
class VerifyBuyerInventory implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $buyerId) {}

    public function handle(InventoryProvider $provider, SteamInventorySync $sync, TradeVerifier $verifier): void
    {
        $buyer = User::find($this->buyerId);

        if ($buyer === null || ! $buyer->steam_id) {
            return;
        }

        $trades = $this->dueTrades($buyer);

        if ($trades->isEmpty()) {
            return;
        }

        // A buyer's due trades may span app/context pairs; read each once.
        foreach ($trades->groupBy(fn (Trade $trade): string => "{$trade->app_id}:{$trade->context_id}") as $group) {
            /** @var Trade $first */
            $first = $group->first();
            $result = $provider->fetch($buyer->steam_id, $first->app_id, $first->context_id);

            if ($result->status !== 'ok') {
                // Can't verify without a clean read — back off and try later.
                $this->backOff($group);

                continue;
            }

            $sync->sync($buyer, $first->app_id, $first->context_id, $result->items);

            foreach ($group as $trade) {
                $verifier->verify($trade);
            }
        }
    }

    /**
     * Active trades for this buyer whose next poll is due.
     *
     * @return Collection<int, Trade>
     */
    private function dueTrades(User $buyer): Collection
    {
        return Trade::query()
            ->where('buyer_id', $buyer->id)
            ->whereIn('status', [TradeStatus::PendingDelivery, TradeStatus::Accepted])
            ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->get();
    }

    /**
     * @param  Collection<int, Trade>  $trades
     */
    private function backOff(Collection $trades): void
    {
        Trade::whereKey($trades->modelKeys())->update([
            'next_poll_at' => now()->addSeconds((int) config('trades.poll.min_seconds')),
            'last_polled_at' => now(),
        ]);
    }
}
