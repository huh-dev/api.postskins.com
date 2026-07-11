<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A trade could not be executed from an accepted offer. Carries the HTTP status
 * and machine-readable code the API should surface, so callers need one catch.
 */
class TradeExecutionException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly string $reason,
        public readonly int $status,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** The post was cancelled, expired, or already fulfilled by another offer. */
    public static function postUnavailable(): self
    {
        return new self('post_unavailable', 409, 'This trade post is no longer open.');
    }

    /** The offer was withdrawn, declined, or already accepted. */
    public static function offerUnavailable(): self
    {
        return new self('offer_unavailable', 409, 'This offer is no longer available.');
    }

    /**
     * One or more assets moved or became untradable between offer and acceptance.
     *
     * @param  list<string>  $assetIds
     */
    public static function itemsUnavailable(array $assetIds): self
    {
        return new self(
            'items_unavailable',
            409,
            'Some items in this trade are no longer available.',
            ['asset_ids' => $assetIds],
        );
    }

    /** The post owner sends the Steam offer, so they must have a Steam session. */
    public static function initiatorNotConnected(): self
    {
        return new self('seller_not_connected', 422, 'Connect your Steam account before accepting an offer.');
    }

    /** The counterparty receives the Steam offer, so they need a trade URL. */
    public static function counterpartyMissingTradeUrl(): self
    {
        return new self('trade_url_required', 422, 'The offering user has not set a Steam trade URL.');
    }

    public static function partySuspended(): self
    {
        return new self('party_suspended', 422, 'One of the parties to this trade is suspended.');
    }

    public static function selfTrade(): self
    {
        return new self('self_trade', 422, 'You cannot trade with yourself.');
    }
}
