<?php

namespace Database\Factories;

use App\Enums\PostItemSide;
use App\Models\ItemDescription;
use App\Models\TradePost;
use App\Models\TradePostItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradePostItem>
 */
class TradePostItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trade_post_id' => TradePost::factory(),
            'side' => PostItemSide::Wanting,
            'item_description_id' => ItemDescription::factory(),
            'inventory_item_id' => null,
            'app_id' => 730,
            'context_id' => 2,
            'market_hash_name' => 'AK-47 | Redline (Field-Tested)',
            'asset_id' => null,
            'quantity' => 1,
        ];
    }
}
