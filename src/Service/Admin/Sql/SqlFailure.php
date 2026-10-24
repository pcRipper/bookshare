<?php

namespace App\Service\Admin\Sql;

/**
 * PostgreSQL refused a sandbox statement — a syntax error, a missing column, a
 * privilege the sandbox role does not hold.
 *
 * Carried back to the operator verbatim (they wrote the SQL and need the
 * database's own words to fix it) but stripped down to the server's message,
 * so the driver's wrapping, which can name the host, never reaches the page.
 */
final class SqlFailure extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $sqlState,
    ) {
        parent::__construct($message);
    }
}
