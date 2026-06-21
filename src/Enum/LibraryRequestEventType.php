<?php

namespace App\Enum;

/**
 * One step in a borrow request's lifecycle. Each transition appends an
 * immutable LibraryRequestEvent so the full history can be retraced, rather
 * than only the request's current status being known.
 */
enum LibraryRequestEventType: string
{
    case Requested       = 'requested';
    case Approved        = 'approved';
    case Declined        = 'declined';
    case ReturnRequested = 'return_requested';
    case Returned        = 'returned';
}
