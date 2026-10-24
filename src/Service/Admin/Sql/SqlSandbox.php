<?php

namespace App\Service\Admin\Sql;

use App\Entity\SqlSandboxEntry;
use App\Entity\User;
use App\Enum\SqlKind;
use App\Enum\SqlOutcome;
use App\Exception\DomainRuleException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs one operator statement against the database, inside the walls the
 * sandbox stands behind, and records the attempt whatever happens to it.
 *
 * The walls, outermost first:
 *  1. StatementGuard — one statement, SELECT/UPDATE/DELETE only, WHERE on
 *     writes, no keyword or function that reaches past the data;
 *  2. the `bookshare_sandbox` role on its own connection — no INSERT, no write
 *     on the audit trail or this log, no way back to the superuser;
 *  3. a transaction per statement, with its own timeouts: a read runs READ ONLY
 *     and is always rolled back; a write is rolled back unless the operator has
 *     seen the preview and confirmed the same row count;
 *  4. server-side preparation, so PostgreSQL itself refuses a second statement
 *     even if one were to get past the guard.
 *
 * **Every attempt is recorded** — accepted, rejected, failed — as a
 * SqlSandboxEntry on the application's own connection (persisted here, flushed
 * by the controller) and as one record on the `sql_sandbox` monolog channel.
 * The entry is the only trail these statements leave: they go through DBAL on a
 * connection auditor-bundle never sees, so no `_audit` row is written for them.
 */
class SqlSandbox
{
    public const MAX_ROWS = 500;
    public const MAX_AFFECTED = 1000;
    public const TIMEOUT_MS = 5000;
    private const LOCK_TIMEOUT_MS = 2000;
    private const MAX_CELL = 1000;

    public function __construct(
        private readonly SandboxConnection $sandbox,
        private readonly StatementGuard $guard,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function isAvailable(): bool
    {
        return $this->sandbox->isConfigured();
    }

    /**
     * @throws DomainRuleException     the guard or a rule refused it (nothing ran, or it was rolled back)
     * @throws SqlFailure              PostgreSQL refused it
     * @throws SandboxUnavailable      the sandbox connection is off or unusable
     */
    public function run(User $operator, string $sql, bool $commit = false, ?int $expectedRows = null): SqlResult
    {
        $started = hrtime(true);
        $kind = null;

        try {
            if (!$this->sandbox->isConfigured()) {
                throw new SandboxUnavailable();
            }

            $kind = $this->guard->check($sql);
            $result = $kind === SqlKind::Read
                ? $this->read($sql, $started)
                : $this->write($sql, $commit, $expectedRows, $started);

            $outcome = match (true) {
                $kind === SqlKind::Read => SqlOutcome::Ok,
                $result->committed => SqlOutcome::Committed,
                default => SqlOutcome::Previewed,
            };
            $this->record($operator, $sql, $kind, $outcome, $result->rowCount, $result->durationMs, null);

            return $result;
        } catch (DomainRuleException $e) {
            $this->record($operator, $sql, $kind, SqlOutcome::Rejected, null, $this->elapsed($started), $e->getMessage());
            throw $e;
        } catch (SqlFailure $e) {
            $this->record($operator, $sql, $kind, SqlOutcome::Failed, null, $this->elapsed($started), $e->getMessage());
            throw $e;
        } catch (SandboxUnavailable $e) {
            $this->record($operator, $sql, $kind, SqlOutcome::Failed, null, $this->elapsed($started), $e->getMessage());
            throw $e;
        }
    }

    private function read(string $sql, int $started): SqlResult
    {
        return $this->inTransaction(true, function (Connection $c) use ($sql, $started): array {
            $result = $c->prepare($sql)->executeQuery();

            $columns = [];
            for ($i = 0, $n = $result->columnCount(); $i < $n; $i++) {
                $columns[] = $result->getColumnName($i);
            }

            // One row past the cap says "there is more" without counting it all.
            $rows = [];
            $truncated = false;
            while (($row = $result->fetchNumeric()) !== false) {
                if (\count($rows) === self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                $rows[] = array_map($this->cell(...), $row);
            }
            $result->free();

            return [
                false,
                new SqlResult(SqlKind::Read, \count($rows), $this->elapsed($started), $columns, $rows, $truncated),
            ];
        });
    }

    private function write(string $sql, bool $commit, ?int $expectedRows, int $started): SqlResult
    {
        if ($commit && $expectedRows === null) {
            throw new DomainRuleException('Preview the change before committing it.');
        }

        return $this->inTransaction(false, function (Connection $c) use ($sql, $commit, $expectedRows, $started): array {
            $affected = (int) $c->prepare($sql)->executeStatement();

            if ($affected > self::MAX_AFFECTED) {
                throw new DomainRuleException(
                    'This would change %count% rows; the sandbox changes at most %max% at once.',
                    ['%count%' => $affected, '%max%' => self::MAX_AFFECTED],
                );
            }

            if ($commit && $affected !== $expectedRows) {
                throw new DomainRuleException(
                    'The data changed since the preview: this would now change %count% rows, not %expected%. Preview it again.',
                    ['%count%' => $affected, '%expected%' => (int) $expectedRows],
                );
            }

            return [
                $commit,
                new SqlResult(SqlKind::Write, $affected, $this->elapsed($started), committed: $commit),
            ];
        });
    }

    /**
     * Opens the transaction, applies the per-statement limits, runs $work and
     * commits only when it says so — every other path, an exception included,
     * rolls back.
     *
     * @param callable(Connection): array{bool, SqlResult} $work
     */
    private function inTransaction(bool $readOnly, callable $work): SqlResult
    {
        try {
            $c = $this->sandbox->get();
            $this->assertUnprivileged($c);
            $c->beginTransaction();
        } catch (SandboxUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('SQL sandbox connection failed', ['exception' => $e]);
            throw new SandboxUnavailable();
        }

        try {
            if ($readOnly) {
                $c->executeStatement('SET TRANSACTION READ ONLY');
            }
            $c->executeStatement(sprintf("SET LOCAL statement_timeout = '%dms'", self::TIMEOUT_MS));
            $c->executeStatement(sprintf("SET LOCAL lock_timeout = '%dms'", self::LOCK_TIMEOUT_MS));

            [$keep, $result] = $work($c);

            $keep ? $c->commit() : $c->rollBack();

            return $result;
        } catch (\Throwable $e) {
            if ($c->isTransactionActive()) {
                try {
                    $c->rollBack();
                } catch (\Throwable) {
                    // The connection is gone; the server rolls back on its own.
                }
            }

            if ($e instanceof DomainRuleException) {
                throw $e;
            }
            if ($e instanceof ConnectionException) {
                $this->logger->error('SQL sandbox connection failed', ['exception' => $e]);
                throw new SandboxUnavailable();
            }
            if ($e instanceof DriverException) {
                throw new SqlFailure($this->serverMessage($e), $e->getSQLState());
            }
            if ($e instanceof DbalException) {
                throw new SqlFailure($e->getMessage(), null);
            }

            throw $e;
        }
    }

    /**
     * Refuses to run on a connection that is more than the sandbox role — the
     * guard against someone later granting it SUPERUSER, BYPASSRLS or role
     * management, which would quietly remove the wall everything else assumes.
     */
    private function assertUnprivileged(Connection $c): void
    {
        $privileged = $c->fetchOne(
            'SELECT rolsuper OR rolbypassrls OR rolcreaterole OR rolcreatedb OR rolreplication
               FROM pg_roles WHERE rolname = session_user',
        );

        if ($privileged !== false && $privileged !== null && $privileged !== 'f') {
            $this->logger->critical('SQL sandbox role is privileged; refusing to run', [
                'role' => SandboxConnection::ROLE,
            ]);
            throw new SandboxUnavailable();
        }
    }

    /**
     * The server's own words, without the driver's wrapping: PDO prefixes the
     * message with SQLSTATE noise and, for some failures, the connection target.
     */
    private function serverMessage(DriverException $e): string
    {
        $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        if (preg_match('/ERROR:\s*(.*)$/s', $message, $m)) {
            $message = $m[1];
        }

        return mb_substr(trim($message), 0, 500);
    }

    private function cell(mixed $value): string|int|float|bool|null
    {
        if (\is_resource($value)) {
            $bytes = stream_get_contents($value);

            return sprintf('<binary %d bytes>', $bytes === false ? 0 : \strlen($bytes));
        }

        if (\is_string($value) && mb_strlen($value) > self::MAX_CELL) {
            return mb_substr($value, 0, self::MAX_CELL) . '…';
        }

        return $value;
    }

    private function record(
        User $operator,
        string $sql,
        ?SqlKind $kind,
        SqlOutcome $outcome,
        ?int $rows,
        int $durationMs,
        ?string $error,
    ): void {
        $entry = (new SqlSandboxEntry($operator, $sql, $outcome))
            ->setKind($kind)
            ->setRowCount($rows)
            ->setDurationMs($durationMs)
            ->setError($error);
        $this->em->persist($entry);

        $this->logger->info('SQL sandbox statement', [
            'operator' => $operator->getId(),
            'outcome' => $outcome->value,
            'kind' => $kind?->value,
            'rows' => $rows,
            'durationMs' => $durationMs,
            'error' => $error,
            'sql' => $sql,
        ]);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
