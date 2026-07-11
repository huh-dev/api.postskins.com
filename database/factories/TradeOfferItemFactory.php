<?php

namespace Database\Factories;

use App\Enums\OfferItemSide;
use App\Models\ItemDescription;
use App\Models\TradeOffer;
use App\Models\TradeOfferItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradeOfferItem>
 */
class TradeOfferItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trade_offer_id' => TradeOffer::factory(),
            'side' => OfferItemSide::FromOfferer,
            'item_description_id' => ItemDescription::factory(),
            'inventory_item_id' => null,
            'app_id' => 730,
            'context_id' => 2,
            'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
            'asset_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
        ];
    }
}
