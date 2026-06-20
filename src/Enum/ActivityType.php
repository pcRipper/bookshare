<?php

namespace App\Enum;

enum ActivityType: string
{
    case Borrowed  = 'borrowed';
    case Commented = 'commented';
    case Followed  = 'followed';
    case AddedBook = 'added_book';
}
