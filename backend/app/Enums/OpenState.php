<?php

namespace App\Enums;

/** Whether a store takes orders right now (spec 10 "Store status"). */
enum OpenState: string
{
    case Open = 'open';
    /** Open, but slower or not taking new orders for a while. */
    case Busy = 'busy';
    case Closed = 'closed';
}
