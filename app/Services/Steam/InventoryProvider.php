<?php

namespace App\Services\Steam;

/**
 * A source of Steam inventories. Implementations differ only in transport
 * (official Steam endpoint, a paid aggregator, a proxy pool); the returned
 * item shape is identical so the rest of the app is driver-agnostic.
 */
interface InventoryProvider
{
    public function fetch(string $steamId, int $appId, int $contextId): InventoryResult;
}
