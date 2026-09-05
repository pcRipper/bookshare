<?php

namespace App\Tests\Service\Admin;

use App\Enum\DumpKind;
use App\Service\Admin\DumpService;
use App\Service\Admin\JsonExporter;
use App\Service\Admin\PgDump;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The service, against a real temporary directory but with both writers stubbed.
 *
 * The writers are the uninteresting half — one forks a subprocess, the other
 * streams SQL rows, and neither can go wrong in a way this class could catch.
 * What *can* go wrong here is the name handling, and it fails open: `path()` is
 * reached with a string from a URL, so a mistake there is an arbitrary file
 * read, not a wrong answer.
 */
class DumpServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bookshare-dumps-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function service(?PgDump $pgDump = null, ?JsonExporter $json = null): DumpService
    {
        return new DumpService(
            $pgDump ?? $this->writingPgDump(),
            $json ?? $this->writingJsonExporter(),
            new NullLogger(),
            $this->dir,
        );
    }

    /** A stub that behaves like the real thing: it leaves a file behind. */
    private function writingPgDump(bool $available = true): PgDump
    {
        $pgDump = $this->createStub(PgDump::class);
        $pgDump->method('isAvailable')->willReturn($available);
        $pgDump->method('dumpTo')->willReturnCallback(
            static fn (string $target) => file_put_contents($target, 'PGDMP-fake'),
        );

        return $pgDump;
    }

    private function writingJsonExporter(): JsonExporter
    {
        $json = $this->createStub(JsonExporter::class);
        $json->method('exportTo')->willReturnCallback(
            static fn (string $target) => file_put_contents($target, '{"tables":{}}'),
        );

        return $json;
    }

    /* ── creating ─────────────────────────────────────────────────────────── */

    public function testCreatingWritesAFileAndDescribesIt(): void
    {
        $dump = $this->service()->create(DumpKind::Sql);

        self::assertSame(DumpKind::Sql, $dump->kind);
        self::assertSame(\strlen('PGDMP-fake'), $dump->bytes);
        self::assertFileExists($this->dir . '/' . $dump->name);
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[0-9a-f]{4}-sql\.dump$/', $dump->name);
    }

    public function testTheJsonKindGetsItsOwnExtension(): void
    {
        $dump = $this->service()->create(DumpKind::Json);

        self::assertStringEndsWith('-json.json', $dump->name);
        self::assertFalse($dump->kind->isRestorable());
    }

    /**
     * The name carries a random suffix precisely so two dumps started inside the
     * same second are two files rather than one overwriting the other.
     */
    public function testTwoDumpsInTheSameSecondDoNotCollide(): void
    {
        $service = $this->service();

        $first = $service->create(DumpKind::Json);
        $second = $service->create(DumpKind::Json);

        self::assertNotSame($first->name, $second->name);
        self::assertCount(2, $service->list());
    }

    public function testTheDirectoryIsCreatedOnDemand(): void
    {
        rmdir($this->dir);

        $dump = $this->service()->create(DumpKind::Json);

        self::assertFileExists($this->dir . '/' . $dump->name);
    }

    /**
     * pg_dump writes as it goes, so a failure mid-run leaves a partial archive.
     * Left in place it would list as a backup and restore as nothing.
     */
    public function testAFailedDumpLeavesNoPartialFileBehind(): void
    {
        $pgDump = $this->createStub(PgDump::class);
        $pgDump->method('isAvailable')->willReturn(true);
        $pgDump->method('dumpTo')->willReturnCallback(static function (string $target): void {
            file_put_contents($target, 'half a');
            throw new \RuntimeException('pg_dump exited with code 1');
        });

        $service = $this->service($pgDump);

        try {
            $service->create(DumpKind::Sql);
            self::fail('The failure should have propagated.');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame([], $service->list());
        self::assertSame([], glob($this->dir . '/*'));
    }

    /* ── capabilities ─────────────────────────────────────────────────────── */

    public function testTheJsonKindIsAlwaysSupported(): void
    {
        // No subprocess involved, so nothing to be missing.
        self::assertTrue($this->service($this->writingPgDump(available: false))->supports(DumpKind::Json));
    }

    public function testTheSqlKindNeedsPgDump(): void
    {
        self::assertFalse($this->service($this->writingPgDump(available: false))->supports(DumpKind::Sql));
        self::assertTrue($this->service($this->writingPgDump(available: true))->supports(DumpKind::Sql));
    }

    /* ── listing ──────────────────────────────────────────────────────────── */

    public function testListingIsNewestFirst(): void
    {
        $this->seed('20260101-120000-aaaa-sql.dump');
        $this->seed('20260103-120000-cccc-sql.dump');
        $this->seed('20260102-120000-bbbb-sql.dump');

        $names = array_map(static fn ($d) => $d->name, $this->service()->list());

        self::assertSame([
            '20260103-120000-cccc-sql.dump',
            '20260102-120000-bbbb-sql.dump',
            '20260101-120000-aaaa-sql.dump',
        ], $names);
    }

    /**
     * A stray file is somebody else's — a half-copied archive, an editor's
     * backup. Listing it as a dump would invite an operator to trust it.
     */
    public function testFilesThatAreNotOursAreIgnored(): void
    {
        $this->seed('20260101-120000-aaaa-sql.dump');
        $this->seed('notes.txt');
        $this->seed('backup.sql');
        $this->seed('20260101-120000-sql.dump'); // missing the random suffix

        self::assertCount(1, $this->service()->list());
    }

    public function testAnAbsentDirectoryListsAsEmptyRatherThanFailing(): void
    {
        rmdir($this->dir);

        self::assertSame([], $this->service()->list());
    }

    /* ── the path guard ───────────────────────────────────────────────────── */

    /**
     * `$name` reaches path() straight from the URL, so this is the boundary
     * between "download a dump" and "read any file on the server".
     */
    #[DataProvider('hostileNames')]
    public function testAHostileNameResolvesToNothing(string $name): void
    {
        self::assertNull($this->service()->path($name));
    }

    /** @return iterable<string, array{string}> */
    public static function hostileNames(): iterable
    {
        yield 'parent traversal' => ['../.env'];
        yield 'deep traversal' => ['../../../../etc/passwd'];
        yield 'traversal dressed as a dump' => ['../20260101-120000-aaaa-sql.dump'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'windows absolute' => ['C:\\Windows\\win.ini'];
        yield 'nested' => ['sub/20260101-120000-aaaa-sql.dump'];
        yield 'null byte' => ["20260101-120000-aaaa-sql.dump\0.txt"];
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'wrong extension' => ['20260101-120000-aaaa-sql.exe'];
        yield 'unknown kind' => ['20260101-120000-aaaa-tar.dump'];
    }

    public function testAKnownNameResolvesInsideTheDumpDirectory(): void
    {
        $this->seed('20260101-120000-aaaa-sql.dump');

        $path = $this->service()->path('20260101-120000-aaaa-sql.dump');

        self::assertNotNull($path);
        self::assertStringStartsWith(realpath($this->dir), $path);
    }

    /** A well-formed name for a file that isn't there is not a path. */
    public function testAWellFormedNameForAMissingFileResolvesToNothing(): void
    {
        self::assertNull($this->service()->path('20260101-120000-aaaa-sql.dump'));
    }

    /* ── deleting ─────────────────────────────────────────────────────────── */

    public function testDeletingRemovesTheFile(): void
    {
        $this->seed('20260101-120000-aaaa-sql.dump');
        $service = $this->service();

        self::assertTrue($service->delete('20260101-120000-aaaa-sql.dump'));
        self::assertSame([], $service->list());
    }

    public function testDeletingSomethingElseIsRefused(): void
    {
        self::assertFalse($this->service()->delete('../.env'));
        self::assertFalse($this->service()->delete('20260101-120000-aaaa-sql.dump'));
    }

    /* ── retention ────────────────────────────────────────────────────────── */

    /**
     * Nothing prunes these on a schedule, so an operator clicking the button
     * weekly for a year must not fill the disk.
     */
    public function testOnlyTheNewestTenOfAKindSurvive(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->seed(\sprintf('202601%02d-120000-aaaa-json.json', $i));
        }

        $this->service()->create(DumpKind::Json);

        $kept = $this->service()->list();
        self::assertCount(10, $kept);
        // The two oldest went; the freshly created one is at the top.
        $names = array_map(static fn ($d) => $d->name, $kept);
        self::assertNotContains('20260101-120000-aaaa-json.json', $names);
        self::assertNotContains('20260102-120000-aaaa-json.json', $names);
    }

    /**
     * Per kind, not overall: a run of JSON exports must not push out the SQL
     * backups, which are the only restorable ones.
     */
    public function testPruningOneKindLeavesTheOtherAlone(): void
    {
        for ($i = 1; $i <= 11; ++$i) {
            $this->seed(\sprintf('202601%02d-120000-aaaa-json.json', $i));
        }
        $this->seed('20250101-120000-bbbb-sql.dump');

        $this->service()->create(DumpKind::Json);

        $names = array_map(static fn ($d) => $d->name, $this->service()->list());
        self::assertContains('20250101-120000-bbbb-sql.dump', $names, 'The SQL backup must survive.');
        self::assertCount(11, $names);
    }

    private function seed(string $name): void
    {
        file_put_contents($this->dir . '/' . $name, 'x');
    }
}
