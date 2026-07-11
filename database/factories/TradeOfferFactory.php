<?php

namespace Database\Factories;

use App\Enums\OfferItemSide;
use App\Enums\TradeOfferStatus;
use App\Models\InventoryItem;
use App\Models\TradeOffer;
use App\Models\TradeOfferItem;
use App\Models\TradePost;
use App\Models\TradePostItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradeOffer>
 */
class TradeOfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trade_post_id' => TradePost::factory(),
            'offerer_id' => User::factory(),
            'cash_amount' => 0,
            'cash_payer' => null,
            'currency' => config('trades.currency'),
            'message' => null,
            'status' => TradeOfferStatus::Pending,
        ];
    }

    /**
     * Offer against a post, claiming every asset the owner put up.
     */
    public function on(TradePost $post): static
    {
        return $this
            ->state(fn (): array => ['trade_post_id' => $post->id])
            ->afterCreating(function (TradeOffer $offer) use ($post): void {
                $post->offeringItems->each(function (TradePostItem $item) use ($offer): void {
                    TradeOfferItem::create([
                        'trade_offer_id' => $offer->id,
                        'side' => OfferItemSide::FromPoster,
                        'item_description_id' => $item->item_description_id,
                        'inventory_item_id' => $item->inventory_item_id,
                        'app_id' => $item->app_id,
                        'context_id' => $item->context_id,
                        'market_hash_name' => $item->market_hash_name,
                        'asset_id' => $item->asset_id,
                    ]);
                });
            });
    }

    /**
     * Hand over concrete assets. The offerer is set from the items.
     */
    public function giving(InventoryItem ...$items): static
    {
        return $this
            ->state(fn (): array => ['offerer_id' => $items[0]->user_id])
            ->afterCreating(function (TradeOffer $offer) use ($items): void {
                foreach ($items as $item) {
                    TradeOfferItem::create([
                        'trade_offer_id' => $offer->id,
                        'side' => OfferItemSide::FromOfferer,
                        'item_description_id' => $item->item_description_id,
                        'inventory_item_id' => $item->id,
                        'app_id' => $item->app_id,
                        'context_id' => $item->context_id,
                        'market_hash_name' => $item->itemDescription->market_hash_name,
                        'asset_id' => $item->asset_id,
                    ]);
                }
            });
    }

    /**
     * The offerer tops the deal up with cash.
     */
    public function payingCash(int $amount): static
    {
        return $this->state(fn (): array => [
            'cash_amount' => $amount,
            'cash_payer' => TradeOffer::PAYER_OFFERER,
        ]);
    }

    /**
     * The post owner tops the deal up with cash.
     */
    public function receivingCash(int $amount): static
    {
        return $this->state(fn (): array => [
            'cash_amount' => $amount,
            'cash_payer' => TradeOffer::PAYER_POSTER,
        ]);
    }
}
