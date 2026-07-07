<?php

namespace App\Enums;

enum ListingStatus: string
{
    /** Available to buy. */
    case Active = 'active';

    /** A buyer has purchased it; a trade is in progress or done. */
    case Sold = 'sold';

    /** The seller withdrew it from sale. */
    case Cancelled = 'cancelled';
}
