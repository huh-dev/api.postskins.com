<?php

namespace Database\Factories;

use App\Enums\TradeStatus;
use App\Models\ItemDescription;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trade>
 */
class TradeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $description = ItemDescription::factory();

        return [
            'seller_id' => User::factory(),
            'buyer_id' => User::factory(),
            'item_description_id' => $description,
            'app_id' => 730,
            'context_id' => 2,
            'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
            'asset_id_listed' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'asset_id_received' => null,
            'price' => fake()->numberBetween(500, 50_000),
            'currency' => config('trades.currency'),
            'status' => TradeStatus::PendingDelivery,
            'escrow' => false,
            'next_poll_at' => now(),
        ];
    }
}
