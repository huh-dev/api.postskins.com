<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'steam_id' => (string) fake()->numerify('765611980########'),
            'app_id' => 730,
            'context_id' => 2,
            'asset_id' => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'item_description_id' => ItemDescription::factory(),
            'amount' => 1,
            'tradable' => true,
            'tradable_after' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * A freshly received, trade-protected copy (locked for the protection window).
     */
    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'tradable' => false,
            'tradable_after' => now()->addDays(7),
        ]);
    }
}
