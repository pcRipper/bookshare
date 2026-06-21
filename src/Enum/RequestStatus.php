<?php

namespace App\Enum;

enum RequestStatus: string
{
    case Pending       = 'pending';
    case Approved      = 'approved';
    case Declined      = 'declined';
    case ReturnPending = 'return_pending';
    case Returned      = 'returned';
}
