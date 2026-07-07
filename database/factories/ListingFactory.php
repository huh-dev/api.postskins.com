<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => User::factory(),
            'inventory_item_id' => null,
            'item_description_id' => ItemDescription::factory(),
            'app_id' => 730,
            'context_id' => 2,
            'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
            'asset_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'price' => fake()->numberBetween(500, 50_000),
            'currency' => config('trades.currency'),
            'status' => ListingStatus::Active,
        ];
    }

    /**
     * Build the listing from a concrete inventory item owned by its seller.
     */
    public function forItem(InventoryItem $item): static
    {
        return $this->state(fn (): array => [
            'seller_id' => $item->user_id,
            'inventory_item_id' => $item->id,
            'item_description_id' => $item->item_description_id,
            'app_id' => $item->app_id,
            'context_id' => $item->context_id,
            'market_hash_name' => $item->itemDescription->market_hash_name,
            'asset_id' => $item->asset_id,
        ]);
    }
}
