<?php

namespace App\Command;

use App\Entity\Book;
use App\Entity\User;
use App\Service\ImageLocalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills images that predate (or slipped past) on-write localization:
 * book covers and user avatars still pointing at a remote CDN are downloaded to
 * our own origin via ImageLocalizer, so they end up cached and hotlink-free like
 * newly-created ones. Run manually — it makes outbound HTTP requests.
 *
 * Idempotent: an image we already host is skipped (the store owns its URL), so
 * re-running only picks up what's still remote. A fetch failure leaves the row's
 * remote URL untouched (best-effort), to be retried on the next run.
 */
#[AsCommand(
    name: 'app:localize-images',
    description: 'Download remote book covers and avatars to our own origin',
)]
class LocalizeImagesCommand extends Command
{
    /** Flush after this many mutations to keep the unit of work bounded. */
    private const BATCH = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImageLocalizer $images,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be localized without downloading or writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run — no downloads, no changes.');
        }

        $covers = $this->process(
            $io,
            'Book covers',
            $this->em->getRepository(Book::class)->findAll(),
            static fn (Book $b) => $b->getCoverPath(),
            fn (Book $b, string $url, string $source) => $b->setCoverPath($url)->setCoverSourceUrl($source),
            ImageLocalizer::COVERS,
            $dryRun,
        );

        $avatars = $this->process(
            $io,
            'Avatars',
            $this->em->getRepository(User::class)->findAll(),
            static fn (User $u) => $u->getAvatarUrl(),
            fn (User $u, string $url, string $source) => $u->setAvatarUrl($url)->setAvatarSourceUrl($source),
            ImageLocalizer::AVATARS,
            $dryRun,
        );

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf('Done. Covers: %s. Avatars: %s.', $covers, $avatars));

        return Command::SUCCESS;
    }

    /**
     * @param iterable<object>       $entities
     * @param callable(object):?string $get
     * @param callable(object,string,string):mixed $set receives the localized URL and the remote one it replaced
     */
    private function process(
        SymfonyStyle $io,
        string $label,
        iterable $entities,
        callable $get,
        callable $set,
        string $category,
        bool $dryRun,
    ): string {
        $localized = $failed = $pending = 0;

        foreach ($entities as $entity) {
            $url = $get($entity);

            // Only remote HTTP(S) URLs we don't already host are candidates.
            if ($url === null || $url === '' || $this->images->owns($url)
                || (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://'))) {
                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('  would localize: %s', $url));
                ++$localized;
                continue;
            }

            $result = $this->images->localize($url, $category);
            if ($result === $url) {
                // Unchanged ⇒ the fetch failed; the warning is already logged.
                ++$failed;
                continue;
            }

            // The fetch succeeded, so $url is the source this copy came from.
            $set($entity, $result, $url);
            ++$localized;

            if (++$pending >= self::BATCH) {
                $this->em->flush();
                $pending = 0;
            }
        }

        $summary = sprintf('%d localized', $localized);
        if ($failed > 0) {
            $summary .= sprintf(', %d failed', $failed);
        }
        $io->section(sprintf('%s: %s', $label, $summary));

        return $summary;
    }
}
