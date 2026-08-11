<?php

namespace App\Command;

use App\Dto\StatsWindow;
use App\Repository\PageViewVisitorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops old visitor rows.
 *
 * Only page_view_visitor is pruned. It is the one analytics table that grows
 * with the audience — one row per person per day — and the only one holding
 * anything visitor-derived, even in salted-and-rotated form. page_view_daily is
 * a few thousand rows a year of pure counters, so keeping it forever costs less
 * than the code to delete it, and it is what the long-horizon view is made of.
 *
 * The default retention sits comfortably past the dashboard's largest window, so
 * nothing the operator can actually ask for is ever pruned away. Run it from
 * cron; it makes no outbound requests and is safe to repeat.
 */
#[AsCommand(
    name: 'app:prune-analytics',
    description: 'Delete analytics visitor rows older than the retention window',
)]
class PruneAnalyticsCommand extends Command
{
    /**
     * Comfortably beyond StatsWindow's 90-day maximum. The gap is deliberate
     * slack: pruning at exactly 90 would mean the oldest day of the largest
     * window disappearing mid-view.
     */
    private const DEFAULT_DAYS = 120;

    public function __construct(private readonly PageViewVisitorRepository $visitors)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Retention in days. Rows older than this are deleted.',
            (string) self::DEFAULT_DAYS,
        );
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted without deleting it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $raw = (string) $input->getOption('days');

        if (!ctype_digit($raw) || (int) $raw < 1) {
            $io->error('The --days option must be a positive whole number');

            return Command::INVALID;
        }

        $days = (int) $raw;
        $largestWindow = max(StatsWindow::ALLOWED);
        if ($days < $largestWindow) {
            // Not fatal — an operator may deliberately want a shorter retention —
            // but it silently empties the left-hand end of the 90-day charts.
            $io->warning(sprintf(
                'Retention of %d days is shorter than the dashboard\'s %d-day window, so the longest view will be incomplete',
                $days,
                $largestWindow,
            ));
        }

        $cutoff = new \DateTimeImmutable('today')->modify(sprintf('-%d days', $days));
        $affected = $this->visitors->countOlderThan($cutoff);

        if ($affected === 0) {
            $io->success(sprintf('Nothing to prune before %s', $cutoff->format('Y-m-d')));

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('Dry run — %d rows before %s would be deleted', $affected, $cutoff->format('Y-m-d')));

            return Command::SUCCESS;
        }

        $deleted = $this->visitors->deleteOlderThan($cutoff);
        $io->success(sprintf('Pruned %d visitor rows before %s', $deleted, $cutoff->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
