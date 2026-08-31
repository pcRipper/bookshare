<?php

namespace App\Tests\Repository;

use App\Enum\RequestStatus;
use App\Repository\CollectionRequestRepository;
use App\Repository\LibraryRequestRepository;

/**
 * The reminder queries behind app:send-loan-reminders, against the real DQL.
 *
 * Everything that keeps the reminder mails sane is in these WHERE clauses:
 * a cron that fires twice must mail once, a five-book collection must produce
 * one mail rather than six, and a borrower who already started the return must
 * not be chased. None of that can be observed in a unit test.
 */
class LoanReminderQueryTest extends RepositoryTestCase
{
    private LibraryRequestRepository $requests;
    private CollectionRequestRepository $collectionRequests;

    private \DateTimeImmutable $today;
    private \DateTimeImmutable $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests = self::getContainer()->get(LibraryRequestRepository::class);
        $this->collectionRequests = self::getContainer()->get(CollectionRequestRepository::class);
        $this->today = new \DateTimeImmutable('today');
        $this->tomorrow = $this->today->modify('+1 day');
    }

    /** @return array{\DateTimeImmutable, ?\DateTimeImmutable} */
    private function dueSoonWindow(): array
    {
        return [$this->tomorrow, $this->tomorrow->modify('+1 day')];
    }

    private function approvedLoanDue(\DateTimeImmutable $dueDate): \App\Entity\LibraryRequest
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $book = $this->makeBook($owner, \App\Enum\BookStatus::Lent, $borrower);

        return $this->makeRequest($book, $borrower, RequestStatus::Approved)->setDueDate($dueDate);
    }

    public function testALoanDueTomorrowIsPickedUp(): void
    {
        $loan = $this->approvedLoanDue($this->tomorrow);
        $this->em->flush();

        [$from, $to] = $this->dueSoonWindow();
        $found = $this->requests->findNeedingReminder($from, $to, 'dueReminderSentAt');

        self::assertSame([$loan], $found);
    }

    /** The window is exactly one day: today and the day after are not "tomorrow". */
    public function testLoansOutsideTomorrowAreNotPickedUp(): void
    {
        $this->approvedLoanDue($this->today);
        $this->approvedLoanDue($this->tomorrow->modify('+1 day'));
        $this->em->flush();

        [$from, $to] = $this->dueSoonWindow();

        self::assertSame([], $this->requests->findNeedingReminder($from, $to, 'dueReminderSentAt'));
    }

    /**
     * Idempotency is a query filter, not something the command remembers — which
     * is what makes a second cron run on the same day harmless.
     */
    public function testAnAlreadyRemindedLoanIsExcluded(): void
    {
        $this->approvedLoanDue($this->tomorrow)->setDueReminderSentAt(new \DateTimeImmutable());
        $this->em->flush();

        [$from, $to] = $this->dueSoonWindow();

        self::assertSame([], $this->requests->findNeedingReminder($from, $to, 'dueReminderSentAt'));
    }

    /** The two reminder kinds are tracked separately. */
    public function testTheDueStampDoesNotSuppressTheOverdueChase(): void
    {
        $loan = $this->approvedLoanDue($this->today->modify('-3 days'))
            ->setDueReminderSentAt(new \DateTimeImmutable());
        $this->em->flush();

        self::assertSame([$loan], $this->requests->findNeedingReminder($this->today, null, 'overdueReminderSentAt'));
    }

    public function testOverduePicksUpEverythingDueBeforeToday(): void
    {
        $yesterday = $this->approvedLoanDue($this->today->modify('-1 day'));
        $lastMonth = $this->approvedLoanDue($this->today->modify('-30 days'));
        $this->approvedLoanDue($this->tomorrow); // not yet due
        $this->em->flush();

        $found = $this->requests->findNeedingReminder($this->today, null, 'overdueReminderSentAt');

        // Oldest first — the query orders by due date.
        self::assertSame([$lastMonth, $yesterday], $found);
    }

    /**
     * A loan with no due date can't be late: the owner never set one, so there
     * is nothing to remind about.
     */
    public function testALoanWithNoDueDateIsNeverReminded(): void
    {
        $this->approvedLoanDue($this->tomorrow)->setDueDate(null);
        $this->em->flush();

        [$from, $to] = $this->dueSoonWindow();

        self::assertSame([], $this->requests->findNeedingReminder($from, $to, 'dueReminderSentAt'));
        self::assertSame([], $this->requests->findNeedingReminder($this->today, null, 'overdueReminderSentAt'));
    }

    /**
     * Only Approved. A borrower already in ReturnPending has acted; a pending or
     * settled request isn't a loan at all.
     */
    public function testOnlyApprovedLoansAreReminded(): void
    {
        foreach ([RequestStatus::Pending, RequestStatus::ReturnPending, RequestStatus::Returned, RequestStatus::Declined] as $status) {
            $owner = $this->makeUser();
            $borrower = $this->makeUser();
            $book = $this->makeBook($owner, \App\Enum\BookStatus::Lent, $borrower);
            $this->makeRequest($book, $borrower, $status)->setDueDate($this->tomorrow);
        }
        $this->em->flush();

        [$from, $to] = $this->dueSoonWindow();

        self::assertSame([], $this->requests->findNeedingReminder($from, $to, 'dueReminderSentAt'));
    }

    /**
     * The whole point of the child exclusion: a collection borrow is one loan to
     * the borrower, so it must produce one reminder from the parent and none from
     * its member books.
     */
    public function testACollectionBorrowIsRemindedOnceThroughItsParent(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $books = [
            $this->makeBook($owner, \App\Enum\BookStatus::Lent, $borrower),
            $this->makeBook($owner, \App\Enum\BookStatus::Lent, $borrower),
            $this->makeBook($owner, \App\Enum\BookStatus::Lent, $borrower),
        ];
        $collection = $this->makeCollection($owner, $books);
        $parent = $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Approved)
            ->setDueDate($this->tomorrow);
        foreach ($parent->getChildren() as $child) {
            $child->setDueDate($this->tomorrow);
        }
        $this->em->flush();

        [$from, $to] = $this->dueSoonWindow();

        self::assertSame([], $this->requests->findNeedingReminder($from, $to, 'dueReminderSentAt'), 'A member book reminded on its own.');
        self::assertSame([$parent], $this->collectionRequests->findNeedingReminder($from, $to, 'dueReminderSentAt'));
    }

    public function testAnAlreadyRemindedCollectionBorrowIsExcluded(): void
    {
        $owner = $this->makeUser();
        $borrower = $this->makeUser();
        $books = [$this->makeBook($owner), $this->makeBook($owner)];
        $collection = $this->makeCollection($owner, $books);
        $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Approved)
            ->setDueDate($this->tomorrow)
            ->setDueReminderSentAt(new \DateTimeImmutable());
        $this->em->flush();

        [$from, $to] = $this->dueSoonWindow();

        self::assertSame([], $this->collectionRequests->findNeedingReminder($from, $to, 'dueReminderSentAt'));
    }
}
