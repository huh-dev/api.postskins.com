<?php

namespace App\Enums;

enum OfferItemSide: string
{
    /** An asset the offerer will hand over. */
    case FromOfferer = 'from_offerer';

    /** One of the post owner's offered assets the offerer wants in return. */
    case FromPoster = 'from_poster';
}
