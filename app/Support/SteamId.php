<?php

namespace App\Support;

/**
 * Helpers for Steam identifiers and trade-offer links.
 */
final class SteamId
{
    /**
     * Offset between a 64-bit SteamID and its 32-bit account id.
     */
    private const ACCOUNT_ID_BASE = 76561197960265728;

    /**
     * Convert a 64-bit SteamID to the 32-bit account id used in trade URLs.
     */
    public static function toAccountId(string $steamId64): int
    {
        return (int) ((int) $steamId64 - self::ACCOUNT_ID_BASE);
    }

    /**
     * Build the "send a new trade offer" link the seller opens to deliver an
     * item to this buyer. Prefers the buyer's saved trade URL (which carries
     * the required token); falls back to a partner-only link otherwise.
     */
    public static function tradeOfferLink(string $buyerSteamId64, ?string $buyerTradeUrl = null): string
    {
        if ($buyerTradeUrl) {
            return $buyerTradeUrl;
        }

        return 'https://steamcommunity.com/tradeoffer/new/?partner='.self::toAccountId($buyerSteamId64);
    }
}
