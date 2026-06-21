<?php

namespace App\Enum;

enum BookStatus: string
{
    case Own         = 'own';
    case Lent        = 'lent';
    case Unavailable = 'unavailable';
}
