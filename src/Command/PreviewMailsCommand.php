<?php

namespace App\Command;

use App\I18n\LocaleCatalog;
use App\Mail\MailType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * Renders every mail to a file, from fixed sample data.
 *
 * Two jobs, both of which need the *same* fixtures:
 *
 *  1. **Design review.** Looking at a mail otherwise means provoking a real loan
 *     transition and digging the result out of Mailpit — for eight mails and
 *     five languages that is not a thing anyone does, which is how these
 *     templates shipped in the app's previous colour palette.
 *  2. **Visual regression.** tests/e2e/mail-visual.spec.js runs this command and
 *     screenshots what it produces. That only works because the output is
 *     deterministic: every date, name and title below is a literal, and nothing
 *     reads the clock. A `new DateTimeImmutable()` anywhere here would make the
 *     baselines rot within a day.
 *
 * Output is disposable (var/ is gitignored); only the baselines are committed.
 */
#[AsCommand(
    name: 'app:mail-preview',
    description: 'Render every mail to HTML/text files from fixed sample data',
)]
class PreviewMailsCommand extends Command
{
    private const DEFAULT_DIR = 'var/mail-preview';

    /** Fixed so a screenshot taken today still matches one taken next month. */
    private const DUE_DATE = '2026-12-24';

    public function __construct(
        private readonly BodyRendererInterface $renderer,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Where to write the files, relative to the project root.', self::DEFAULT_DIR)
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Comma-separated locales to render.', LocaleCatalog::DEFAULT)
            ->addOption('all-locales', null, InputOption::VALUE_NONE, 'Render every supported locale.');
    }

    /**
     * One entry per mail *as a reader can receive it* — which is more than one
     * per MailType: a loan mail differs for a collection, a decline with and
     * without a note are different layouts, and the reminder has two states.
     * These variants are exactly what a visual baseline needs to cover.
     *
     * @return array<string, array{MailType, array<string, mixed>}>
     */
    private function variants(): array
    {
        $book = [
            'item'            => 'The Left Hand of Darkness',
            'author'          => 'Ursula K. Le Guin',
            'isCollection'    => false,
            'bookCount'       => null,
            'dueDate'         => new \DateTimeImmutable(self::DUE_DATE),
            'message'         => null,
            'counterpart'     => 'Ada Lovelace',
            'counterpartRole' => 'requester',
        ];
        $fromOwner = ['counterpart' => 'Iris Murdoch', 'counterpartRole' => 'owner'] + $book;
        $collection = ['item' => 'The Earthsea Cycle', 'author' => null, 'isCollection' => true, 'bookCount' => 4] + $book;

        return [
            'loan.requested'            => [MailType::LoanRequested, $book],
            'loan.requested.collection' => [MailType::LoanRequested, $collection],
            'loan.approved'             => [MailType::LoanApproved, $fromOwner],
            'loan.declined'             => [MailType::LoanDeclined, ['message' => 'Sorry — it is promised to someone else until spring.'] + $fromOwner],
            'loan.declined.no-note'     => [MailType::LoanDeclined, $fromOwner],
            'loan.return_requested'     => [MailType::LoanReturnRequested, $book],
            'loan.return_confirmed'     => [MailType::LoanReturnConfirmed, $fromOwner],
            'loan.reminder.due_soon'    => [MailType::LoanReminder, ['state' => 'due_soon'] + $fromOwner],
            'loan.reminder.overdue'     => [MailType::LoanReminder, ['state' => 'overdue'] + $fromOwner],
            'account.welcome'           => [MailType::AccountWelcome, []],
            'social.new_follower'       => [MailType::SocialNewFollower, ['follower' => 'Ada Lovelace', 'followerId' => 42]],
        ];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $files = new Filesystem();

        $dir = rtrim($this->projectDir.'/'.trim((string) $input->getOption('dir'), '/'), '/');
        $locales = $input->getOption('all-locales')
            ? LocaleCatalog::codes()
            : array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('locale')))));

        foreach ($locales as $locale) {
            if (LocaleCatalog::negotiate($locale) === null) {
                $io->error(sprintf('Unsupported locale "%s". Supported: %s.', $locale, implode(', ', LocaleCatalog::codes())));

                return Command::INVALID;
            }
        }

        // Old output is cleared, not merged: a renamed variant would otherwise
        // leave its file behind and a visual baseline would keep passing against
        // a mail that no longer exists.
        //
        // The *files* are removed rather than the directory: on a bind mount
        // (the docker stack shares this tree with a Windows host) Filesystem's
        // remove() renames-then-rmdirs and fails with "Directory not empty".
        $files->mkdir($dir);
        // Two globs rather than one GLOB_BRACE pattern: the constant is a glibc
        // extension and the runtime image is Alpine (musl), where it is undefined.
        $files->remove([...(glob("{$dir}/*.html") ?: []), ...(glob("{$dir}/*.txt") ?: [])]);

        $written = [];
        foreach ($locales as $locale) {
            foreach ($this->variants() as $name => [$type, $context]) {
                $email = (new TemplatedEmail())
                    ->from(new Address('noreply@folioshare.example', 'FolioShare'))
                    ->to(new Address('reader@folioshare.example', 'Ada Reader'))
                    ->subject($type->subject())
                    ->htmlTemplate($type->template('html'))
                    ->textTemplate($type->template('txt'))
                    ->locale($locale)
                    ->context($context + [
                        // A literal host, not DEFAULT_URI: the preview must not
                        // change shape because someone's .env differs.
                        'appUrl'      => 'https://folioshare.example',
                        'settingsUrl' => 'https://folioshare.example/settings',
                        'locale'      => $locale,
                        'recipient'   => 'Ada Reader',
                    ]);

                $this->renderer->render($email);

                $stem = sprintf('%s.%s', $name, $locale);
                $files->dumpFile("{$dir}/{$stem}.html", (string) $email->getHtmlBody());
                $files->dumpFile("{$dir}/{$stem}.txt", (string) $email->getTextBody());
                $written[] = $stem;
            }
        }

        $files->dumpFile("{$dir}/index.html", $this->index($written));

        $io->success(sprintf('Wrote %d mails to %s (open index.html).', \count($written), $input->getOption('dir')));

        return Command::SUCCESS;
    }

    /** A plain contact sheet, so a design review is one file to open. */
    private function index(array $stems): string
    {
        $rows = '';
        foreach ($stems as $stem) {
            $rows .= sprintf(
                '<li><a href="%1$s.html">%1$s</a> &middot; <a href="%1$s.txt">text</a></li>'."\n",
                htmlspecialchars($stem, \ENT_QUOTES),
            );
        }

        return "<!doctype html>\n<meta charset=\"utf-8\">\n<title>Mail preview</title>\n"
            ."<style>body{font:16px/1.6 system-ui;margin:40px;max-width:60ch}li{margin:4px 0}</style>\n"
            ."<h1>Mail preview</h1>\n<ul>\n{$rows}</ul>\n";
    }
}
