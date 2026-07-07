<?php

namespace App\Console\Commands;

use App\Enums\TradeStatus;
use App\Jobs\VerifyBuyerInventory;
use App\Models\Trade;
use Illuminate\Console\Command;

/**
 * Dispatches one inventory-verification job per buyer with due trades. Runs
 * every minute from the scheduler; the per-buyer jobs do the actual Steam reads.
 */
class PollTrades extends Command
{
    protected $signature = 'trades:poll';

    protected $description = 'Verify delivery and detect reversals for active trades';

    public function handle(): int
    {
        $buyerIds = Trade::query()
            ->whereIn('status', [TradeStatus::PendingDelivery, TradeStatus::Accepted])
            ->where(fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->distinct()
            ->pluck('buyer_id');

        foreach ($buyerIds as $buyerId) {
            VerifyBuyerInventory::dispatch($buyerId);
        }

        $this->info("Dispatched verification for {$buyerIds->count()} buyer(s).");

        return self::SUCCESS;
    }
}
