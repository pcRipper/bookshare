<?php

namespace App\Service\Admin\Sql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The sandbox's own database connection, logged in as the restricted
 * `bookshare_sandbox` role (see the migration that creates it).
 *
 * Built here from SANDBOX_DATABASE_URL rather than declared in doctrine.yaml: a
 * second DBAL connection there would hand it to every Doctrine tool — the
 * profiler, the migrations diff, `dbal:run-sql` — and none of them has any
 * business with it.
 *
 * Unconfigured is a normal state, not a fault: a blank URL or password turns the
 * feature off and the panel says so, the way a missing pg_dump does. A URL that
 * names any other user is refused outright — pointing this at the application's
 * superuser would quietly remove the one wall the sandbox stands behind.
 */
class SandboxConnection
{
    public const ROLE = 'bookshare_sandbox';

    private ?Connection $connection = null;

    public function __construct(
        #[Autowire('%env(default::SANDBOX_DATABASE_URL)%')]
        private readonly ?string $url,
    ) {}

    public function isConfigured(): bool
    {
        $params = $this->params();

        return $params !== null
            && ($params['user'] ?? null) === self::ROLE
            && ($params['password'] ?? '') !== '';
    }

    /** The password from the URL, for the provision command. Null when unconfigured. */
    public function password(): ?string
    {
        return $this->isConfigured() ? $this->params()['password'] : null;
    }

    /**
     * @throws \LogicException when unconfigured — callers check isConfigured() first
     */
    public function get(): Connection
    {
        if (!$this->isConfigured()) {
            throw new \LogicException('The SQL sandbox connection is not configured.');
        }

        return $this->connection ??= DriverManager::getConnection($this->params());
    }

    /** @return array<string, mixed>|null */
    private function params(): ?array
    {
        if ($this->url === null || trim($this->url) === '') {
            return null;
        }

        try {
            return (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($this->url);
        } catch (\Throwable) {
            return null;
        }
    }
}
