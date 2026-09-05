<?php

namespace App\Service\Admin;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Runs `pg_dump` against the configured database.
 *
 * A thin wrapper on purpose — it exists so DumpService has something stubbable
 * in front of a subprocess, and so the two things that are easy to get wrong
 * live in one place:
 *
 *  - **The password never reaches argv.** `ps` is world-readable on a shared
 *    host, so it goes through PGPASSWORD in the process environment instead.
 *    (`--dbname=postgresql://user:pass@…` would be the same mistake in a
 *    different shape.)
 *  - **The client version has to match the server.** pg_dump refuses a server
 *    newer than itself, which is why both PHP images pin `postgresql16-client`
 *    against the `postgres:16` service rather than taking Alpine's default.
 *
 * The custom format (`-Fc`) rather than plain SQL: it is compressed, and it is
 * what `pg_restore` wants — selective restore included.
 */
class PgDump
{
    /**
     * Generous, because this scales with the database and runs at an operator's
     * deliberate request rather than in a request they are waiting on blindly.
     * Still finite: a hung subprocess must not hold a PHP-FPM worker forever.
     */
    private const TIMEOUT = 600;

    /** The availability probe is a version banner; it either answers at once or isn't there. */
    private const PROBE_TIMEOUT = 5;

    private ?bool $available = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Whether `pg_dump` is on PATH at all.
     *
     * Asked so the panel can disable the button and say why, instead of offering
     * an action that always fails — which is exactly what a dev machine outside
     * the container looks like, and there the missing binary is expected rather
     * than broken. Memoized per request; the answer cannot change mid-process.
     */
    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            $probe = new Process(['pg_dump', '--version']);
            $probe->setTimeout(self::PROBE_TIMEOUT);
            $probe->run();

            return $this->available = $probe->isSuccessful();
        } catch (ProcessException) {
            // Thrown when the binary cannot be executed at all.
            return $this->available = false;
        }
    }

    /**
     * Writes an archive of the whole database to $target.
     *
     * @throws \RuntimeException when pg_dump is missing, fails or times out
     */
    public function dumpTo(string $target): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('pg_dump is not installed on this server.');
        }

        $params = $this->connection->getParams();

        $process = new Process(
            [
                'pg_dump',
                '--format=custom',
                // The archive is for this application, not for recreating its
                // hosting: roles and grants differ between the droplet and a
                // laptop, and restoring them is how a restore fails.
                '--no-owner',
                '--no-privileges',
                '--host=' . ($params['host'] ?? 'localhost'),
                '--port=' . (string) ($params['port'] ?? 5432),
                '--username=' . ($params['user'] ?? ''),
                '--file=' . $target,
                (string) ($params['dbname'] ?? ''),
            ],
            env: ['PGPASSWORD' => (string) ($params['password'] ?? '')],
        );
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (ProcessException $e) {
            throw new \RuntimeException('pg_dump could not be started: ' . $e->getMessage(), previous: $e);
        }

        if ($process->isSuccessful()) {
            return;
        }

        // stderr carries the actual reason (auth, version mismatch, disk); it is
        // operator-facing detail, so it is logged rather than returned.
        $this->logger->error('pg_dump failed', [
            'exitCode' => $process->getExitCode(),
            'stderr' => mb_substr(trim($process->getErrorOutput()), 0, 500),
        ]);

        throw new \RuntimeException('pg_dump exited with code ' . $process->getExitCode());
    }
}
