<?php

namespace App\Enums;

enum TradeItemSide: string
{
    /** Leaving the initiator (the post owner, who sends the Steam offer). */
    case FromInitiator = 'from_initiator';

    /** Leaving the counterparty (the offerer, who accepts the Steam offer). */
    case FromCounterparty = 'from_counterparty';
}
