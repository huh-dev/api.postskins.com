<?php

namespace App\Console\Commands;

use App\Enums\TradeStatus;
use App\Jobs\CheckTradeOffer;
use App\Jobs\VerifyAcceptedTrade;
use App\Jobs\VerifyTradeInventories;
use App\Models\Trade;
use Illuminate\Console\Command;

/**
 * Every minute, advances active trades:
 *  - pending delivery WITH a sent offer -> confirm delivery via the offer state.
 *  - accepted WITH a sent offer -> watch for a rollback via the PUBLIC
 *    inventories (no session, so a reversing party can't hide it).
 *  - anything without an offer id (e.g. the lab) -> inventory checks.
 */
class PollTrades extends Command
{
    protected $signature = 'trades:poll';

    protected $description = 'Verify delivery and detect reversals for active trades';

    public function handle(): int
    {
        $due = fn ($query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now());

        $trades = Trade::query()
            ->whereIn('status', [TradeStatus::PendingDelivery, TradeStatus::Accepted])
            ->where($due)
            ->get(['id', 'status', 'steam_tradeoffer_id']);

        $offerChecks = 0;
        $reversalChecks = 0;
        $inventoryChecks = 0;

        foreach ($trades as $trade) {
            if ($trade->steam_tradeoffer_id !== null) {
                if ($trade->status === TradeStatus::PendingDelivery) {
                    CheckTradeOffer::dispatch($trade->id);
                    $offerChecks++;
                } else {
                    VerifyAcceptedTrade::dispatch($trade->id);
                    $reversalChecks++;
                }

                continue;
            }

            // No offer to track (lab / manual) -> fall back to inventory reads.
            VerifyTradeInventories::dispatch($trade->id);
            $inventoryChecks++;
        }

        $this->info("Dispatched {$offerChecks} delivery, {$reversalChecks} reversal, {$inventoryChecks} inventory check(s).");

        return self::SUCCESS;
    }
}
