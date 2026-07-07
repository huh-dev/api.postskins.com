<?php

namespace App\Enums;

enum TradeStatus: string
{
    /** Buyer has paid; waiting for the seller to send and the buyer to receive the item. */
    case PendingDelivery = 'pending_delivery';

    /** Item received by the buyer; the trade-protection window is running. */
    case Accepted = 'accepted';

    /** Protection window passed with the item still held; seller has been paid. */
    case Completed = 'completed';

    /** Item was rolled back during the window; seller suspended, buyer refunded. */
    case Reversed = 'reversed';

    /** Never delivered in time (or declined); buyer refunded, no penalty. */
    case Cancelled = 'cancelled';

    /** Wrong item received or inventory unverifiable; awaiting manual review. */
    case Disputed = 'disputed';

    /**
     * Whether the trade still needs to be polled for delivery/reversal.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::PendingDelivery, self::Accepted], true);
    }
}
