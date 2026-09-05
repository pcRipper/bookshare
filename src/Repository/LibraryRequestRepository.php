<?php

namespace App\Repository;

use App\Dto\PaginatedResult;
use App\Dto\Pagination;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\RequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class LibraryRequestRepository extends ServiceEntityRepository
{
    /**
     * What counts as a loan for the dashboard's rankings: a request that was
     * actually approved and handed over. A pending or declined request is an
     * intention, not a loan, and counting it would let a book nobody ever lent
     * top the "most borrowed" list.
     */
    private const LOANED_STATUSES = [
        RequestStatus::Approved,
        RequestStatus::ReturnPending,
        RequestStatus::Returned,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LibraryRequest::class);
    }

    /**
     * Book ids by loan count, most-borrowed first.
     *
     * Returns ids rather than books so the caller can hydrate the whole page in
     * one findBy() — resolving each row with find() would be the N+1 this shape
     * exists to avoid, the same two-query pattern UserStatsProvider::forUsers()
     * uses.
     *
     * Deliberately unwindowed: "most borrowed" reads as all-time, and at this
     * table's size the scan is cheap. Adding a date bound later means adding an
     * index on requested_at in the same change — a windowed filter without one
     * is strictly worse than no window.
     *
     * @return array<int, int> book id => loans, ordered
     */
    public function mostBorrowedBookIds(int $limit): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.book) AS bookId, COUNT(r.id) AS total')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', self::LOANED_STATUSES)
            ->groupBy('r.book')
            ->orderBy('total', 'DESC')
            ->addOrderBy('bookId', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['bookId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Owner ids by how many of their books were lent out, most-active first.
     * Same hydrate-in-one-query contract as mostBorrowedBookIds().
     *
     * @return array<int, int> user id => loans, ordered
     */
    public function topLenderIds(int $limit): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(b.owner) AS ownerId, COUNT(r.id) AS total')
            ->join('r.book', 'b')
            ->where('r.status IN (:statuses)')
            ->setParameter('statuses', self::LOANED_STATUSES)
            ->groupBy('b.owner')
            ->orderBy('total', 'DESC')
            ->addOrderBy('ownerId', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['ownerId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Incoming requests for books owned by $owner, filtered by status, newest first.
     *
     * @param RequestStatus[] $statuses
     * @return LibraryRequest[]
     */
    public function findIncoming(User $owner, array $statuses): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.book', 'b')
            // Eager-load the lifecycle events (and their actors) so the timeline
            // renders without an N+1 per request.
            ->leftJoin('r.events', 'e')->addSelect('e')
            ->leftJoin('e.actor', 'ea')->addSelect('ea')
            ->andWhere('b.owner = :owner')
            ->andWhere('r.status IN (:statuses)')
            // Collection borrows are surfaced grouped by their parent request, so
            // exclude the per-book children from the individual request lists.
            ->andWhere('r.parentRequest IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('e.createdAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Outgoing requests made by $requester (the borrower's side), filtered by
     * status, newest first.
     *
     * @param RequestStatus[] $statuses
     * @return LibraryRequest[]
     */
    public function findOutgoing(User $requester, array $statuses): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.events', 'e')->addSelect('e')
            ->leftJoin('e.actor', 'ea')->addSelect('ea')
            ->andWhere('r.requester = :requester')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.parentRequest IS NULL')
            ->setParameter('requester', $requester)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('e.createdAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of incoming requests (owner side) for the History view, newest
     * first, with the total matching count.
     *
     * Events are a to-many collection, which the Paginator can't page over while
     * fetch-joined. So the page query selects only to-one associations (fetch
     * joins that never multiply rows), then hydrateEvents() loads each request's
     * events in a single follow-up query — no N+1, correct paging.
     *
     * @param RequestStatus[] $statuses
     * @return PaginatedResult<LibraryRequest>
     */
    public function findIncomingPaginated(User $owner, array $statuses, Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('r')
            ->join('r.book', 'b')->addSelect('b')
            ->join('b.owner', 'bo')->addSelect('bo')
            ->join('r.requester', 'rq')->addSelect('rq')
            ->andWhere('b.owner = :owner')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.parentRequest IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        return $this->paginateWithEvents($query, $pagination);
    }

    /**
     * One page of outgoing requests (borrower side) for the History view.
     *
     * @param RequestStatus[] $statuses
     * @return PaginatedResult<LibraryRequest>
     */
    public function findOutgoingPaginated(User $requester, array $statuses, Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('r')
            ->join('r.book', 'b')->addSelect('b')
            ->join('b.owner', 'bo')->addSelect('bo')
            ->join('r.requester', 'rq')->addSelect('rq')
            ->andWhere('r.requester = :requester')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.parentRequest IS NULL')
            ->setParameter('requester', $requester)
            ->setParameter('statuses', $statuses)
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        return $this->paginateWithEvents($query, $pagination);
    }

    /**
     * Runs a to-one-only page query, then eagerly loads the events (and their
     * actors) for exactly that page so the timeline renders without an N+1.
     *
     * @return PaginatedResult<LibraryRequest>
     */
    private function paginateWithEvents(\Doctrine\ORM\Query $query, Pagination $pagination): PaginatedResult
    {
        $paginator = new Paginator($query, fetchJoinCollection: false);
        $requests = iterator_to_array($paginator);

        if ($requests !== []) {
            // Populates the events collection (ordered by its #[ORM\OrderBy]) on
            // the already-managed request entities via the Unit of Work.
            $this->createQueryBuilder('r')
                ->leftJoin('r.events', 'e')->addSelect('e')
                ->leftJoin('e.actor', 'ea')->addSelect('ea')
                ->andWhere('r IN (:requests)')
                ->setParameter('requests', $requests)
                ->getQuery()
                ->getResult();
        }

        return new PaginatedResult($requests, \count($paginator));
    }

    /**
     * Is this member on either side of a loan that is still in flight — a book
     * they lent out, or one they are holding?
     *
     * The admin panel's delete guard. Deleting an account destroys its shelf, so
     * doing it mid-loan would take the counterpart's live loan with it and leave
     * them holding (or missing) a book with no record of why. Settled history is
     * a different matter: it survives the deletion, attributed to an unnamed
     * account.
     *
     * Pending requests are not "in flight" for this purpose — nobody has parted
     * with a book yet, and UserPurger deletes those outright.
     */
    public function hasActiveLoanInvolving(User $user): bool
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.book', 'b')
            ->andWhere('r.requester = :user OR b.owner = :user')
            ->andWhere('r.status IN (:active)')
            ->setParameter('user', $user)
            ->setParameter('active', [RequestStatus::Approved, RequestStatus::ReturnPending])
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countPendingForOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->join('r.book', 'b')
            ->andWhere('b.owner = :owner')
            ->andWhere('r.status = :pending')
            ->andWhere('r.parentRequest IS NULL')
            ->setParameter('owner', $owner)
            ->setParameter('pending', RequestStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Ids of books the requester currently has a pending request for. Lets the
     * public profile mark already-requested books so the borrow button reflects
     * reality across reloads.
     *
     * @return int[]
     */
    public function findPendingBookIdsForRequester(User $requester): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.book) AS bookId')
            ->andWhere('r.requester = :requester')
            ->andWhere('r.status = :pending')
            ->setParameter('requester', $requester)
            ->setParameter('pending', RequestStatus::Pending)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (int) $row['bookId'], $rows);
    }

    public function findPendingForBookAndRequester(int $bookId, User $requester): ?LibraryRequest
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.book = :book')
            ->andWhere('r.requester = :requester')
            ->andWhere('r.status = :pending')
            ->setParameter('book', $bookId)
            ->setParameter('requester', $requester)
            ->setParameter('pending', RequestStatus::Pending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Approved loans that need a reminder mailed to the borrower, for the given
     * calendar day boundaries. Two shapes of one query (see
     * App\Command\SendLoanRemindersCommand):
     *
     *  - due-soon: due inside [$from, $to) and not yet reminded;
     *  - overdue:  due before $from and not yet chased.
     *
     * Three filters carry the design:
     *  - `status = Approved` only. A loan already in ReturnPending has had the
     *    borrower act; nagging them about it would be noise.
     *  - `parentRequest IS NULL` — a collection borrow is reminded once, through
     *    its parent CollectionRequest. Without this a five-book collection would
     *    send six mails for one loan.
     *  - the sent-at column IS NULL, so "already reminded" is part of the query
     *    rather than something the command has to remember. A cron that runs
     *    twice sends once.
     *
     * @return LibraryRequest[]
     */
    public function findNeedingReminder(
        \DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        string $sentAtField,
    ): array {
        $qb = $this->createQueryBuilder('r')
            ->join('r.book', 'b')->addSelect('b')
            ->join('b.owner', 'o')->addSelect('o')
            ->join('r.requester', 'u')->addSelect('u')
            ->andWhere('r.status = :approved')
            ->andWhere('r.parentRequest IS NULL')
            ->andWhere(sprintf('r.%s IS NULL', $sentAtField))
            ->setParameter('approved', RequestStatus::Approved)
            ->orderBy('r.dueDate', 'ASC');

        if ($to === null) {
            $qb->andWhere('r.dueDate < :from')->setParameter('from', $from);
        } else {
            $qb->andWhere('r.dueDate >= :from AND r.dueDate < :to')
                ->setParameter('from', $from)
                ->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }
}
