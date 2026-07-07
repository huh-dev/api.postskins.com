<?php

namespace Database\Factories;

use App\Models\ItemDescription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemDescription>
 */
class ItemDescriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['AK-47 | Redline', 'AWP | Asiimov', 'M4A4 | Howl', 'Glock-18 | Fade']);

        return [
            'app_id' => 730,
            'classid' => (string) fake()->unique()->numberBetween(1000, 9_999_999),
            'instanceid' => '0',
            'name' => $name,
            'market_name' => $name.' (Field-Tested)',
            'market_hash_name' => $name.' (Field-Tested)',
            'type' => 'Classified Rifle',
            'icon_url' => fake()->lexify('icon??????'),
            'marketable' => true,
            'trade_hold_days' => 7,
        ];
    }
}
