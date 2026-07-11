<?php

use App\Models\InventoryItem;
use App\Models\ItemDescription;
use App\Models\Trade;
use App\Models\TradeOffer;
use App\Models\TradePost;
use App\Models\User;
use App\Services\Trading\TradeService;

/**
 * Shared builders for the trade-post flow, used across the feature tests.
 */

/**
 * A user who has connected their Steam account for selling (can be an initiator).
 */
function connectedUser(string $steamId): User
{
    $user = User::factory()->create([
        'steam_id' => $steamId,
        'steam_selling_connected_at' => now(),
    ]);

    // steam_refresh_token is guarded + encrypted; set it directly.
    $user->forceFill(['steam_refresh_token' => 'token-'.$steamId])->save();

    return $user->fresh();
}

/**
 * A user with a Steam trade URL (can be a counterparty/offerer).
 */
function tradeUrlUser(string $steamId, int $balance = 0): User
{
    $user = User::factory()->create([
        'steam_id' => $steamId,
        'trade_url' => "https://steamcommunity.com/tradeoffer/new/?partner={$steamId}&token=abc",
    ]);

    if ($balance > 0) {
        $user->ensureWallet()->forceFill(['balance' => $balance])->save();
    }

    return $user;
}

/**
 * A tradable inventory item owned by $owner with a fresh description.
 */
function ownedItem(User $owner, string $marketHashName): InventoryItem
{
    $description = ItemDescription::factory()->create([
        'name' => $marketHashName,
        'market_name' => $marketHashName,
        'market_hash_name' => $marketHashName,
    ]);

    return InventoryItem::factory()->for($owner)->create([
        'item_description_id' => $description->id,
        'tradable' => true,
    ]);
}

/**
 * Execute a cash purchase: the initiator sells one item for cash from the
 * counterparty. Returns the trade and both parties.
 *
 * @return array{trade: Trade, initiator: User, counterparty: User, item: InventoryItem}
 */
function executeCashPurchase(int $price, int $counterpartyBalance): array
{
    $initiator = connectedUser('76561199000000010');
    $counterparty = tradeUrlUser('76561199000000011', $counterpartyBalance);

    $item = ownedItem($initiator, 'AK-47 | Redline (Field-Tested)');

    $post = TradePost::factory()->offering($item)->sellingFor($price)->create();
    $offer = TradeOffer::factory()
        ->on($post)
        ->payingCash($price)
        ->create(['offerer_id' => $counterparty->id]);

    $trade = app(TradeService::class)->execute($offer);

    return compact('trade', 'initiator', 'counterparty', 'item');
}

/**
 * Execute a two-sided swap: the initiator gives one item, the counterparty gives
 * one item, with optional cash on one side.
 *
 * @return array{trade: Trade, initiator: User, counterparty: User, initiatorItem: InventoryItem, counterpartyItem: InventoryItem}
 */
function executeSwap(int $cash = 0, string $cashPayer = 'offerer', int $counterpartyBalance = 0): array
{
    $initiator = connectedUser('76561199000000020');
    $counterparty = tradeUrlUser('76561199000000021', $counterpartyBalance);

    $initiatorItem = ownedItem($initiator, 'AWP | Dragon Lore (Factory New)');
    $counterpartyItem = ownedItem($counterparty, 'M4A4 | Howl (Minimal Wear)');

    $post = TradePost::factory()->offering($initiatorItem)->create();

    $factory = TradeOffer::factory()->on($post)->giving($counterpartyItem);
    if ($cash > 0) {
        $factory = $cashPayer === 'offerer' ? $factory->payingCash($cash) : $factory->receivingCash($cash);
    }
    $offer = $factory->create(['offerer_id' => $counterparty->id]);

    $trade = app(TradeService::class)->execute($offer);

    return compact('trade', 'initiator', 'counterparty', 'initiatorItem', 'counterpartyItem');
}

/**
 * Simulate every leg of a trade arriving (trade-locked) in its receiver's
 * inventory, matching the leg's market hash name.
 */
function deliverAllLegs(Trade $trade): void
{
    foreach ($trade->items()->with('itemDescription')->get() as $leg) {
        InventoryItem::create([
            'user_id' => $leg->receiver_id,
            'steam_id' => (string) $leg->receiver_id,
            'app_id' => $leg->app_id,
            'context_id' => $leg->context_id,
            'asset_id' => 'rx-'.$leg->id,
            'item_description_id' => $leg->item_description_id,
            'amount' => 1,
            'tradable' => false,
            'tradable_after' => now()->addDays(7),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}

/**
 * Remove a delivered copy from a receiver's inventory (simulate a rollback).
 */
function removeReceivedLeg(Trade $trade): void
{
    $leg = $trade->items()->first();
    InventoryItem::where('user_id', $leg->receiver_id)
        ->where('asset_id', $leg->asset_id_received)
        ->delete();
}
