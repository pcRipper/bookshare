<?php

namespace App\Enum;

/**
 * What a sandbox statement does, as decided by StatementGuard.
 *
 * Two kinds only, because only two kinds are let through: a read can never
 * change anything and is always rolled back, a write is previewed first and
 * committed only on a second, confirming request.
 */
enum SqlKind: string
{
    case Read = 'read';
    case Write = 'write';
}
