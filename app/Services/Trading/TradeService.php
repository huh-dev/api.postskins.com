<?php

namespace App\Services\Trading;

use App\Enums\TradeStatus;
use App\Models\InventoryItem;
use App\Models\Trade;
use App\Models\User;
use App\Services\Trading\Wallet\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Opens P2P trades. Validation of the buyer/seller/item lives in the caller;
 * this service performs the atomic "create trade + hold the buyer's funds" step
 * so the HTTP controller and the local trade-lab share one code path.
 */
class TradeService
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Create a trade for the buyer to receive $item from its owner, holding the
     * buyer's payment until the trade is verified and its protection window ends.
     */
    public function open(User $buyer, InventoryItem $item, int $price): Trade
    {
        return DB::transaction(function () use ($buyer, $item, $price): Trade {
            $description = $item->itemDescription;

            $trade = Trade::create([
                'seller_id' => $item->user_id,
                'buyer_id' => $buyer->id,
                'item_description_id' => $item->item_description_id,
                'app_id' => $item->app_id,
                'context_id' => $item->context_id,
                'market_hash_name' => $description->market_hash_name,
                'asset_id_listed' => $item->asset_id,
                'price' => $price,
                'currency' => config('trades.currency'),
                'status' => TradeStatus::PendingDelivery,
                'next_poll_at' => now(),
            ]);

            $this->ledger->hold($buyer->ensureWallet(), $price, $trade);

            $trade->recordEvent('created', [
                'price' => $price,
                'market_hash_name' => $description->market_hash_name,
            ]);

            return $trade;
        });
    }
}
