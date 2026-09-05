<?php

namespace App\Command;

use App\Enum\DumpKind;
use App\Service\Admin\DumpService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The console twin of the admin panel's dump button.
 *
 * It exists so backups can be scheduled: the panel is for the operator who
 * wants one *now*, cron wants one every night, and both must produce the same
 * file under the same retention. Sharing DumpService is what guarantees that —
 * a separate shell script would drift the moment either side changed.
 *
 * Output here is CLI text, exempt from the translation catalogs by the same
 * rule as every other command (see TranslationCoverageTest).
 */
#[AsCommand(
    name: 'app:dump',
    description: 'Write a database dump to var/dumps (sql = restorable, json = readable rows)',
)]
class DumpCommand extends Command
{
    public function __construct(
        private readonly DumpService $dumps,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'sql or json', DumpKind::Sql->value)
            ->addOption('list', null, InputOption::VALUE_NONE, 'List existing dumps and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $io->table(
                ['Name', 'Kind', 'Size', 'Created'],
                array_map(
                    static fn ($d) => [$d->name, $d->kind->value, self::size($d->bytes), $d->createdAt->format('Y-m-d H:i')],
                    $this->dumps->list(),
                ),
            );

            return Command::SUCCESS;
        }

        $kind = DumpKind::tryFrom((string) $input->getOption('kind'));
        if ($kind === null) {
            $io->error('Unknown kind. Use --kind=sql or --kind=json.');

            return Command::INVALID;
        }

        if (!$this->dumps->supports($kind)) {
            $io->error('This machine cannot produce a ' . $kind->value . ' dump (is pg_dump installed?).');

            return Command::FAILURE;
        }

        try {
            $dump = $this->dumps->create($kind);
        } catch (\Throwable $e) {
            $io->error('Dump failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Wrote %s (%s).', $dump->name, self::size($dump->bytes)));

        if (!$kind->isRestorable()) {
            $io->note('A json dump carries rows only — no schema, no sequences. It is not a backup.');
        }

        return Command::SUCCESS;
    }

    private static function size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < \count($units) - 1) {
            $size /= 1024;
            ++$i;
        }

        return \sprintf('%.1f %s', $size, $units[$i]);
    }
}
