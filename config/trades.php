<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | ISO-4217 code for wallet balances. All money is stored as integer minor
    | units (e.g. cents) — never floats.
    |
    */

    'currency' => env('TRADE_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Trade Protection Hold
    |--------------------------------------------------------------------------
    |
    | Since Steam's 2024 Trade Protection update a received CS2 item is locked
    | and reversible for 7 days. We hold the seller's payout for this window and
    | only release it if no reversal is detected. Set the seconds override to a
    | small value locally (or 0 in tests) to watch the lifecycle without waiting.
    |
    */

    'protection_hold_days' => (int) env('TRADE_PROTECTION_HOLD_DAYS', 7),

    'protection_hold_seconds' => env('TRADE_PROTECTION_HOLD_SECONDS'),

    /*
    |--------------------------------------------------------------------------
    | Escrow Timeout
    |--------------------------------------------------------------------------
    |
    | Buyers without a mobile authenticator can have an incoming item held by
    | Steam for up to 15 days. We keep waiting for delivery until this timeout
    | before treating an undelivered trade as cancelled.
    |
    */

    'escrow_max_days' => (int) env('TRADE_ESCROW_MAX_DAYS', 15),

    /*
    |--------------------------------------------------------------------------
    | Verification Polling Cadence
    |--------------------------------------------------------------------------
    |
    | Seconds between inventory re-checks for a trade. We poll frequently right
    | after acceptance (reversals usually happen fast) and back off as it ages.
    |
    */

    'poll' => [
        'min_seconds' => (int) env('TRADE_POLL_MIN_SECONDS', 60),
        'max_seconds' => (int) env('TRADE_POLL_MAX_SECONDS', 3600),
        // How often to re-check an accepted trade for a rollback during the
        // protection window. Each check uses the seller's Steam session, so keep
        // this well above a minute in production to avoid login rate limits.
        'reversal_seconds' => (int) env('TRADE_REVERSAL_POLL_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fake Steam Driver
    |--------------------------------------------------------------------------
    |
    | When true, the inventory provider is swapped for an in-memory fake driven
    | by the local /trade-lab harness, so the whole P2P flow can be exercised
    | without real Steam trades. Never enable in production.
    |
    */

    'fake_steam' => (bool) env('TRADE_FAKE_STEAM', false),

];
