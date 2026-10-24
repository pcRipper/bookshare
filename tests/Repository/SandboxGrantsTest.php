<?php

namespace App\Tests\Repository;

use App\Service\Admin\Sql\SandboxConnection;

/**
 * Pins what the `bookshare_sandbox` role may do — the real wall the SQL sandbox
 * stands behind, so the one worth a DB-backed test.
 *
 * Switches to the role with SET LOCAL ROLE inside the rolled-back test
 * transaction rather than logging in as it (which would need a password on the
 * test cluster). The privileges checked are the role's own either way. Each
 * probe runs under a savepoint, since a refused statement aborts the
 * transaction around it.
 */
final class SandboxGrantsTest extends RepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('SET LOCAL ROLE ' . SandboxConnection::ROLE);
    }

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'select a domain table' => ['SELECT count(*) FROM book'];
        yield 'select an audit table' => ['SELECT count(*) FROM book_audit'];
        yield 'select user' => ['SELECT email FROM "user" LIMIT 1'];
        yield 'update a book' => ['UPDATE book SET title = title WHERE false'];
        yield 'delete a book' => ['DELETE FROM book WHERE false'];
        yield 'update a user bio' => ['UPDATE "user" SET bio = bio WHERE false'];
        yield 'update a user ban' => ['UPDATE "user" SET banned_at = NULL WHERE false'];
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'insert' => ["INSERT INTO category (name, color_hex) VALUES ('x', '#000000')"];
        yield 'update user roles' => ['UPDATE "user" SET roles = \'[]\' WHERE false'];
        yield 'update user email' => ["UPDATE \"user\" SET email = 'x' WHERE false"];
        yield 'update user google id' => ["UPDATE \"user\" SET google_id = 'x' WHERE false"];
        yield 'delete a user' => ['DELETE FROM "user" WHERE false'];
        yield 'read its own log' => ['SELECT count(*) FROM sql_sandbox_entry'];
        yield 'erase its own log' => ['DELETE FROM sql_sandbox_entry WHERE false'];
        yield 'rewrite an audit table' => ['UPDATE book_audit SET id = id WHERE false'];
        yield 'rewrite request history' => ['DELETE FROM library_request_event WHERE false'];
        yield 'rewrite the migration ledger' => ['DELETE FROM doctrine_migration_versions WHERE false'];
        yield 'read a server file' => ["SELECT pg_read_file('/etc/passwd')"];
        yield 'create a table' => ['CREATE TABLE sandbox_probe (id int)'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('allowed')]
    public function testAllowed(string $sql): void
    {
        $this->em->getConnection()->executeQuery($sql);
        $this->addToAssertionCount(1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testRefused(string $sql): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction(); // a savepoint inside the test transaction

        try {
            $conn->executeQuery($sql);
            self::fail('The sandbox role was allowed to run: ' . $sql);
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            self::assertSame('42501', $e->getSQLState(), $e->getMessage());
        } finally {
            $conn->rollBack();
        }
    }
}
