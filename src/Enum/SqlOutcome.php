<?php

namespace App\Enum;

/**
 * How one sandbox attempt ended — the column the shared query log is read by.
 *
 * `rejected` and `failed` are deliberately different: the first never reached
 * the database (StatementGuard refused it), the second did and Postgres refused
 * it — usually a typo, sometimes a permission the sandbox role does not hold.
 */
enum SqlOutcome: string
{
    /** A read that ran. */
    case Ok = 'ok';
    /** A write that ran and was rolled back to report its row count. */
    case Previewed = 'previewed';
    /** A write that ran and was kept. */
    case Committed = 'committed';
    case Rejected = 'rejected';
    case Failed = 'failed';
}
