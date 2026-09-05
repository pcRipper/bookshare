<?php

namespace App\Service\Admin;

use App\Dto\DumpFile;
use App\Enum\DumpKind;
use Psr\Log\LoggerInterface;

/**
 * Manual database dumps: make one, list them, hand one back, delete one.
 *
 * **The filesystem is the record.** There is no `dump` table, because the files
 * already are one and a second source of truth goes wrong the first time
 * somebody removes a file over SSH. Listing therefore scans the directory, and
 * a file that isn't there simply isn't listed.
 *
 * **They live under `var/`, never `public/`.** A dump contains every member's
 * email address and their whole library; it must not be reachable except
 * through the admin firewall. `/var/` is already gitignored, so nothing here
 * can be committed by accident either.
 */
class DumpService
{
    /**
     * How many of each kind survive. The cap exists because these are big and
     * nothing prunes them on a schedule — an operator who clicks the button
     * weekly for a year should not fill the droplet's disk. Per kind rather
     * than overall, so a run of JSON exports can't push out every SQL backup.
     */
    private const KEEP_PER_KIND = 10;

    /**
     * The shape this service creates, and — far more importantly — the only
     * shape it will ever open. The name arrives from a URL, so anything looser
     * is a file-read primitive: `..%2f..%2f.env` is the whole attack, and it is
     * spelled out here rather than left to a route requirement, which guards
     * one caller instead of all of them.
     */
    private const NAME_PATTERN = '/^\d{8}-\d{6}-[0-9a-f]{4}-(sql|json)\.(dump|json)$/';

    public function __construct(
        private readonly PgDump $pgDump,
        private readonly JsonExporter $json,
        private readonly LoggerInterface $logger,
        private readonly string $dumpDir,
    ) {}

    /** Whether the SQL kind can be produced here at all (i.e. pg_dump exists). */
    public function supports(DumpKind $kind): bool
    {
        return $kind === DumpKind::Json || $this->pgDump->isAvailable();
    }

    /**
     * @return DumpFile[] newest first
     */
    public function list(): array
    {
        if (!is_dir($this->dumpDir)) {
            return [];
        }

        $dumps = [];
        foreach (scandir($this->dumpDir) ?: [] as $name) {
            $file = $this->describe($name);
            if ($file !== null) {
                $dumps[] = $file;
            }
        }

        usort($dumps, static fn (DumpFile $a, DumpFile $b) => $b->name <=> $a->name);

        return $dumps;
    }

    /**
     * Produces a dump and returns it, pruning older ones of the same kind.
     *
     * A failed writer leaves a partial file behind — pg_dump writes as it goes —
     * so it is removed before rethrowing. A half-written archive that still
     * looks like a backup in the list is the worst thing this could leave.
     *
     * @throws \RuntimeException when the dump could not be produced
     */
    public function create(DumpKind $kind): DumpFile
    {
        if (!is_dir($this->dumpDir) && !@mkdir($this->dumpDir, 0775, true) && !is_dir($this->dumpDir)) {
            throw new \RuntimeException('Could not create ' . $this->dumpDir);
        }

        $name = $this->newName($kind);
        $path = $this->dumpDir . '/' . $name;

        try {
            match ($kind) {
                DumpKind::Sql => $this->pgDump->dumpTo($path),
                DumpKind::Json => $this->json->exportTo($path),
            };
        } catch (\Throwable $e) {
            if (is_file($path)) {
                @unlink($path);
            }
            $this->logger->error('Dump failed', ['kind' => $kind->value, 'error' => $e->getMessage()]);

            throw $e;
        }

        $this->prune($kind);

        $file = $this->describe($name);
        if ($file === null) {
            throw new \RuntimeException('The dump was written but could not be read back.');
        }

        $this->logger->info('Dump created', ['kind' => $kind->value, 'name' => $name, 'bytes' => $file->bytes]);

        return $file;
    }

    /**
     * The absolute path of a dump, or null when $name does not name one.
     *
     * Two independent checks, because they fail differently: the pattern refuses
     * a name that could traverse, and the realpath comparison refuses a name
     * that traverses *anyway* — through a symlink the pattern cannot see.
     */
    public function path(string $name): ?string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        $base = realpath($this->dumpDir);
        $real = realpath($this->dumpDir . '/' . $name);

        if ($base === false || $real === false) {
            return null;
        }

        return str_starts_with($real, $base . \DIRECTORY_SEPARATOR) && is_file($real) ? $real : null;
    }

    /** True when the dump existed and is now gone. */
    public function delete(string $name): bool
    {
        $path = $this->path($name);
        if ($path === null) {
            return false;
        }

        $this->logger->info('Dump deleted', ['name' => $name]);

        return @unlink($path);
    }

    /**
     * A file on disk as a DumpFile, or null when it is not one of ours.
     *
     * Everything is derived from the name, so a stray file in the directory is
     * ignored rather than listed as a dump nobody can account for.
     */
    private function describe(string $name): ?DumpFile
    {
        if (!preg_match(self::NAME_PATTERN, $name, $m)) {
            return null;
        }

        $path = $this->dumpDir . '/' . $name;
        if (!is_file($path)) {
            return null;
        }

        return new DumpFile(
            $name,
            DumpKind::from($m[1]),
            (int) (filesize($path) ?: 0),
            // mtime, not the timestamp in the name: the name is written before
            // the dump runs, and for a long one those differ by minutes.
            (new \DateTimeImmutable())->setTimestamp(filemtime($path) ?: time()),
        );
    }

    /**
     * `{Ymd}-{His}-{rand}-{kind}.{ext}`.
     *
     * Sortable by name, which is what lets list() and prune() order by string
     * without stat-ing anything. The four random hex characters are collision
     * insurance, not secrecy — two dumps started in the same second would
     * otherwise be the same file, and the second would overwrite the first.
     */
    private function newName(DumpKind $kind): string
    {
        return \sprintf(
            '%s-%s-%s.%s',
            (new \DateTimeImmutable())->format('Ymd-His'),
            bin2hex(random_bytes(2)),
            $kind->value,
            $kind->extension(),
        );
    }

    private function prune(DumpKind $kind): void
    {
        $ofKind = array_values(array_filter(
            $this->list(),
            static fn (DumpFile $f) => $f->kind === $kind,
        ));

        foreach (\array_slice($ofKind, self::KEEP_PER_KIND) as $old) {
            $path = $this->path($old->name);
            if ($path !== null) {
                @unlink($path);
                $this->logger->info('Dump pruned', ['name' => $old->name]);
            }
        }
    }
}
