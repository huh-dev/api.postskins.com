<?php

namespace App\Enums;

enum TradeOfferStatus: string
{
    /** Awaiting the post owner's decision. */
    case Pending = 'pending';

    /** The owner accepted it; it produced a trade. */
    case Accepted = 'accepted';

    /** The owner rejected it, or a sibling offer was accepted instead. */
    case Declined = 'declined';

    /** The offerer pulled it back before a decision. */
    case Withdrawn = 'withdrawn';

    /** Its post expired or was cancelled before a decision. */
    case Expired = 'expired';

    /**
     * Whether the offer is still awaiting a decision.
     */
    public function isActive(): bool
    {
        return $this === self::Pending;
    }
}
