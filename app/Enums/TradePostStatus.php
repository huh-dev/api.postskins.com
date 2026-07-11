<?php

namespace App\Enums;

enum TradePostStatus: string
{
    /** Visible on the market and accepting offers. */
    case Open = 'open';

    /** The owner accepted an offer; a trade is in progress or done. */
    case Fulfilled = 'fulfilled';

    /** The owner withdrew it from the market. */
    case Cancelled = 'cancelled';

    /** Passed its expiry without an accepted offer. */
    case Expired = 'expired';

    /**
     * Whether the post can still receive and accept offers.
     */
    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
