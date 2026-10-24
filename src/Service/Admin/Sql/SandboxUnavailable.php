<?php

namespace App\Service\Admin\Sql;

/**
 * The sandbox cannot run here: SANDBOX_DATABASE_URL is blank, the role cannot
 * log in, or it turned out to be privileged. The detail goes to the error log;
 * the operator reads one generic sentence, since a connection failure's own
 * message names hosts and users.
 */
final class SandboxUnavailable extends \RuntimeException
{
    public const MESSAGE = 'The SQL sandbox is not available on this server.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
