<?php

namespace App\Services\Steam;

/**
 * The outcome of an inventory fetch, independent of which driver produced it.
 */
final class InventoryResult
{
    /**
     * @param  'ok'|'private'|'error'  $status
     * @param  array<int, array<string, mixed>>  $items
     */
    private function __construct(
        public readonly string $status,
        public readonly array $items = [],
        public readonly ?string $message = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function ok(array $items): self
    {
        return new self('ok', $items);
    }

    public static function privateInventory(): self
    {
        return new self('private');
    }

    public static function error(?string $message = null): self
    {
        return new self('error', message: $message);
    }
}
