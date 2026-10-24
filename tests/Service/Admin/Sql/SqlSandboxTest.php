<?php

namespace App\Tests\Service\Admin\Sql;

use App\Entity\SqlSandboxEntry;
use App\Entity\User;
use App\Enum\SqlKind;
use App\Enum\SqlOutcome;
use App\Exception\DomainRuleException;
use App\Service\Admin\Sql\SandboxConnection;
use App\Service\Admin\Sql\SandboxUnavailable;
use App\Service\Admin\Sql\SqlSandbox;
use App\Service\Admin\Sql\StatementGuard;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The paths that decide before any connection is opened — and that must still
 * leave a log entry, because "every attempt is recorded" is the promise the
 * shared log makes. What PostgreSQL then does with an accepted statement is
 * pinned by SandboxGrantsTest against the real role.
 */
final class SqlSandboxTest extends TestCase
{
    /** @var SqlSandboxEntry[] */
    private array $persisted = [];

    private function sandbox(?string $url): SqlSandbox
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return new SqlSandbox(new SandboxConnection($url), new StatementGuard(), $em, new NullLogger());
    }

    /** A URL that parses but points nowhere — enough to count as configured. */
    private const CONFIGURED = 'postgresql://bookshare_sandbox:pw@127.0.0.1:1/none';

    public function testUnconfiguredIsUnavailableAndStillLogged(): void
    {
        try {
            $this->sandbox(null)->run(new User(), 'SELECT 1');
            self::fail('Ran without a sandbox connection');
        } catch (SandboxUnavailable) {
        }

        self::assertCount(1, $this->persisted);
        self::assertSame(SqlOutcome::Failed, $this->persisted[0]->getOutcome());
        self::assertSame('SELECT 1', $this->persisted[0]->getStatement());
    }

    public function testAConnectionAsAnyOtherUserIsNotConfigured(): void
    {
        self::assertFalse((new SandboxConnection('postgresql://app:changeme@127.0.0.1:5432/bookshare'))->isConfigured());
        self::assertFalse((new SandboxConnection('postgresql://bookshare_sandbox:@127.0.0.1:5432/bookshare'))->isConfigured());
        self::assertFalse((new SandboxConnection(''))->isConfigured());
        self::assertTrue((new SandboxConnection(self::CONFIGURED))->isConfigured());
    }

    public function testARejectedStatementIsLoggedWithTheReason(): void
    {
        try {
            $this->sandbox(self::CONFIGURED)->run(new User(), "INSERT INTO category (name) VALUES ('x')");
            self::fail('Accepted an INSERT');
        } catch (DomainRuleException) {
        }

        self::assertCount(1, $this->persisted);
        $entry = $this->persisted[0];
        self::assertSame(SqlOutcome::Rejected, $entry->getOutcome());
        self::assertNull($entry->getKind());
        self::assertSame('INSERT statements are not allowed in the sandbox.', $entry->getError());
    }

    public function testCommittingWithoutAPreviewIsRefusedBeforeConnecting(): void
    {
        try {
            // The configured URL points nowhere: reaching the connection would
            // surface as SandboxUnavailable instead of this rule.
            $this->sandbox(self::CONFIGURED)->run(new User(), 'DELETE FROM book WHERE id = 1', commit: true);
            self::fail('Committed without a preview count');
        } catch (DomainRuleException $e) {
            self::assertSame('Preview the change before committing it.', $e->id);
        }

        self::assertSame(SqlKind::Write, $this->persisted[0]->getKind());
        self::assertSame(SqlOutcome::Rejected, $this->persisted[0]->getOutcome());
    }

    public function testErrorsAreCappedForTheLog(): void
    {
        $entry = (new SqlSandboxEntry(null, 'SELECT 1', SqlOutcome::Failed))->setError(str_repeat('x', 900));

        self::assertSame(500, mb_strlen($entry->getError()));
    }
}
