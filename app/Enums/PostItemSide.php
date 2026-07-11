<?php

namespace App\Enums;

enum PostItemSide: string
{
    /** A concrete asset the post owner is putting up. */
    case Offering = 'offering';

    /** A wish: an item description the owner wants, with no asset behind it. */
    case Wanting = 'wanting';
}
