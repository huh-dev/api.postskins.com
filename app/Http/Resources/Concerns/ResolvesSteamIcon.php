<?php

namespace App\Http\Resources\Concerns;

trait ResolvesSteamIcon
{
    /**
     * Base URL for Steam economy item images (icon_url is a relative path).
     */
    private const STEAM_IMAGE_BASE = 'https://community.cloudflare.steamstatic.com/economy/image/';

    /**
     * Resolve a relative Steam icon_url to an absolute CDN URL.
     */
    private function resolveIconUrl(?string $iconUrl): ?string
    {
        if (! $iconUrl) {
            return null;
        }

        return str_starts_with($iconUrl, 'http') ? $iconUrl : self::STEAM_IMAGE_BASE.$iconUrl;
    }
}
