<?php

namespace App\Services\Steam;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * Shared parsing for Steam-format inventories. Both the official endpoint and
 * SteamAPIs return the same `{ assets, descriptions }` structure, so merging
 * and normalization live here; subclasses only handle the HTTP transport.
 */
abstract class AbstractSteamInventoryProvider implements InventoryProvider
{
    /**
     * Merge raw assets with their descriptions into normalized item rows.
     *
     * @param  array<int, array<string, mixed>>  $assets
     * @param  array<int, array<string, mixed>>  $descriptions
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(array $assets, array $descriptions): array
    {
        $descriptionsByKey = collect($descriptions)
            ->keyBy(fn (array $description): string => $description['classid'].'_'.($description['instanceid'] ?? '0'));

        return collect($assets)
            ->map(function (array $asset) use ($descriptionsByKey): ?array {
                $description = $descriptionsByKey->get($asset['classid'].'_'.($asset['instanceid'] ?? '0'));

                if ($description === null) {
                    return null;
                }

                $tradable = (bool) ($description['tradable'] ?? false);

                return [
                    'asset_id' => $asset['assetid'] ?? null,
                    'class_id' => $asset['classid'] ?? null,
                    'instance_id' => $asset['instanceid'] ?? null,
                    'amount' => (int) ($asset['amount'] ?? 1),
                    'name' => $description['name'] ?? null,
                    'market_name' => $description['market_name'] ?? null,
                    'market_hash_name' => $description['market_hash_name'] ?? null,
                    'type' => $description['type'] ?? null,
                    'tradable' => $tradable,
                    'tradable_after' => $tradable ? null : $this->resolveTradableAfter($description),
                    'trade_hold_days' => isset($description['market_tradable_restriction'])
                        ? (int) $description['market_tradable_restriction']
                        : null,
                    'marketable' => (bool) ($description['marketable'] ?? false),
                    'icon_url' => $description['icon_url'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Determine whether the upstream response represents a private inventory.
     *
     * Steam returns HTTP 403 for private profiles, but also for rate limits and
     * third-party quota errors — so we key off the error text, not the status.
     */
    protected function indicatesPrivateInventory(Response $response): bool
    {
        $error = strtolower($this->upstreamErrorMessage($response) ?? '');

        if ($error === '') {
            return false;
        }

        return str_contains($error, 'private')
            || str_contains($error, 'inventory is not available');
    }

    protected function upstreamErrorMessage(Response $response): ?string
    {
        $error = $response->json('Error') ?? $response->json('error') ?? $response->json('message');

        return is_string($error) && $error !== '' ? $error : null;
    }

    /**
     * Resolve the exact trade-lock expiry from a description's owner_descriptions.
     *
     * Steam annotates locked items with a line such as
     * "Tradable After 14 Jul 2026 (07:00:00) GMT". This is only reliably present
     * when the inventory is fetched in the owner's context; returns null otherwise.
     *
     * @param  array<string, mixed>  $description
     */
    protected function resolveTradableAfter(array $description): ?CarbonImmutable
    {
        foreach ($description['owner_descriptions'] ?? [] as $line) {
            $value = is_array($line) ? ($line['value'] ?? '') : '';

            if (! preg_match('/After\s+(\d{1,2}\s+[A-Za-z]+\s+\d{4})(?:\s*\(([\d:]+)\))?/i', $value, $matches)) {
                continue;
            }

            $datetime = trim($matches[1].' '.($matches[2] ?? '00:00:00'));

            try {
                return CarbonImmutable::createFromFormat('j M Y H:i:s', $datetime, 'GMT')->utc();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
