<?php

namespace Database\Factories;

use App\Enums\PostItemSide;
use App\Enums\TradePostStatus;
use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\TradePost;
use App\Models\TradePostItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradePost>
 */
class TradePostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'app_id' => 730,
            'context_id' => 2,
            'offer_cash' => 0,
            'want_cash' => 0,
            'currency' => config('trades.currency'),
            'wants_anything' => false,
            'note' => null,
            'status' => TradePostStatus::Open,
        ];
    }

    /**
     * Put concrete assets up for trade. The post's owner is set from the items.
     */
    public function offering(InventoryItem ...$items): static
    {
        return $this
            ->state(fn (): array => ['owner_id' => $items[0]->user_id])
            ->afterCreating(function (TradePost $post) use ($items): void {
                foreach ($items as $item) {
                    TradePostItem::create([
                        'trade_post_id' => $post->id,
                        'side' => PostItemSide::Offering,
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
     * Ask for item descriptions in return (a wish — no asset behind it).
     */
    public function wanting(ItemDescription ...$descriptions): static
    {
        return $this->afterCreating(function (TradePost $post) use ($descriptions): void {
            foreach ($descriptions as $description) {
                TradePostItem::create([
                    'trade_post_id' => $post->id,
                    'side' => PostItemSide::Wanting,
                    'item_description_id' => $description->id,
                    'app_id' => $post->app_id,
                    'context_id' => $post->context_id,
                    'market_hash_name' => $description->market_hash_name,
                ]);
            }
        });
    }

    /**
     * A cash-only sale: items up, money wanted, nothing else.
     */
    public function sellingFor(int $cash): static
    {
        return $this->state(fn (): array => ['want_cash' => $cash]);
    }
}
