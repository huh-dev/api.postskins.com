<?php

namespace Database\Factories;

use App\Enums\TradeItemSide;
use App\Models\ItemDescription;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradeItem>
 */
class TradeItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trade_id' => Trade::factory(),
            'side' => TradeItemSide::FromInitiator,
            'giver_id' => User::factory(),
            'receiver_id' => User::factory(),
            'item_description_id' => ItemDescription::factory(),
            'app_id' => 730,
            'context_id' => 2,
            'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
            'asset_id_sent' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'asset_id_received' => null,
        ];
    }
}
