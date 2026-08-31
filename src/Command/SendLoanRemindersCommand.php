<?php

namespace App\Command;

use App\Entity\CollectionRequest;
use App\Entity\LibraryRequest;
use App\Mail\LoanMailer;
use App\Repository\CollectionRequestRepository;
use App\Repository\LibraryRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mails the borrower about a loan that is due back tomorrow, or already overdue.
 *
 * Run it from cron once a day (see the Mail section of DEPLOY.md). It is the one
 * mail nothing in the app can trigger: every other notification hangs off an
 * action somebody took, while "you still have this" is a fact about the passage
 * of time.
 *
 * Idempotent by construction, not by care: each loan carries a sent-at timestamp
 * per reminder kind and the repository query filters on it being null, so a cron
 * that fires twice — or a hand-run after a failed one — mails once. The stamp is
 * written only when the mail was actually queued, so an opted-out or
 * address-less borrower doesn't burn the one reminder that loan will ever get,
 * and a queue outage leaves it to be retried tomorrow.
 *
 * Reminders cover per-book loans and collection borrows through their parent
 * (one mail for the group, never one per member book — the child query excludes
 * them).
 */
#[AsCommand(
    name: 'app:send-loan-reminders',
    description: 'Mail borrowers about loans due tomorrow or already overdue',
)]
class SendLoanRemindersCommand extends Command
{
    private const DUE_SOON = 'due_soon';
    private const OVERDUE = 'overdue';

    public function __construct(
        private readonly LibraryRequestRepository $requests,
        private readonly CollectionRequestRepository $collectionRequests,
        private readonly LoanMailer $mails,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the reminders that would be sent, without sending or recording them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Calendar-day boundaries, matching how a due date is set: the owner
        // picks a day (`!Y-m-d`), so the loan is due at the start of it.
        $today = new \DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');
        $dayAfter = $tomorrow->modify('+1 day');

        $batches = [
            // Due tomorrow: the window is exactly that one day.
            [self::DUE_SOON, 'dueReminderSentAt', $tomorrow, $dayAfter],
            // Overdue: anything due before today. Sent once — this is a nudge,
            // not a dunning cycle.
            [self::OVERDUE, 'overdueReminderSentAt', $today, null],
        ];

        $sent = 0;
        $skipped = 0;

        foreach ($batches as [$state, $field, $from, $to]) {
            $loans = [
                ...$this->requests->findNeedingReminder($from, $to, $field),
                ...$this->collectionRequests->findNeedingReminder($from, $to, $field),
            ];

            foreach ($loans as $loan) {
                $label = $this->describe($loan, $state);

                if ($dryRun) {
                    $io->writeln("would send: {$label}");
                    ++$sent;
                    continue;
                }

                // Stamp only what actually went out: an opt-out must not consume
                // the single reminder this loan gets, and a queue failure should
                // be retried on tomorrow's run.
                if ($this->mails->remindBorrower($loan, $state)) {
                    $this->stamp($loan, $field);
                    $io->writeln("sent: {$label}", OutputInterface::VERBOSITY_VERBOSE);
                    ++$sent;
                } else {
                    $io->writeln("not sent (opted out or undeliverable): {$label}", OutputInterface::VERBOSITY_VERBOSE);
                    ++$skipped;
                }
            }
        }

        if (!$dryRun) {
            // One unit of work for the whole run, like a controller's single flush.
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s %d reminder(s)%s.',
            $dryRun ? 'Would send' : 'Sent',
            $sent,
            $skipped > 0 ? sprintf(', skipped %d', $skipped) : '',
        ));

        return Command::SUCCESS;
    }

    private function stamp(LibraryRequest|CollectionRequest $loan, string $field): void
    {
        $now = new \DateTimeImmutable();

        match ($field) {
            'dueReminderSentAt'     => $loan->setDueReminderSentAt($now),
            'overdueReminderSentAt' => $loan->setOverdueReminderSentAt($now),
        };
    }

    private function describe(LibraryRequest|CollectionRequest $loan, string $state): string
    {
        $item = $loan instanceof CollectionRequest
            ? 'collection "'.$loan->getCollection()->getName().'"'
            : '"'.$loan->getBook()->getTitle().'"';

        return sprintf(
            '%s %s to %s (due %s)',
            $state,
            $item,
            (string) $loan->getRequester()->getEmail(),
            $loan->getDueDate()?->format('Y-m-d') ?? 'n/a',
        );
    }
}
