<?php

namespace App\Repository;

use App\Dto\PaginatedResult;
use App\Dto\Pagination;
use App\Entity\Book;
use App\Entity\Category;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Enum\RequestStatus;
use App\Enum\WishPriority;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class BookRepository extends ServiceEntityRepository
{
    use CountsCreatedByDay;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * Books actually held by someone. Wish-list rows live in the same table but
     * are not part of anybody's library — see Book::$isWished — so every query
     * in this class states which shelf it means rather than inheriting one.
     */
    public function countAll(): int
    {
        return $this->count(['isWished' => false]);
    }

    /** How many books the community *wants*, across every wish list. */
    public function countWishedAll(): int
    {
        return $this->count(['isWished' => true]);
    }

    /**
     * The titles most wish lists agree on, matched on title+author (the same
     * book catalogued by two members is two rows). The one genuinely new thing
     * the wish list tells an operator: what the community can't get hold of.
     *
     * @return list<array{title: string, author: string, wanted: int}>
     */
    public function countMostWanted(int $limit): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.title AS title, b.author AS author, COUNT(b.id) AS total')
            ->where('b.isWished = true')
            ->groupBy('b.title')
            ->addGroupBy('b.author')
            ->orderBy('total', 'DESC')
            ->addOrderBy('b.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row) => [
                'title'  => (string) $row['title'],
                'author' => (string) $row['author'],
                'wanted' => (int) $row['total'],
            ],
            $rows,
        );
    }

    /**
     * How the community's wish lists are distributed across the three levels,
     * zero-filled for the same reason countByStatus() is.
     *
     * @return array<int, int> keyed by WishPriority backing value
     */
    public function countByWishPriority(): array
    {
        $counts = [];
        foreach (WishPriority::cases() as $case) {
            $counts[$case->value] = 0;
        }

        $rows = $this->createQueryBuilder('b')
            ->select('b.wishPriority AS priority, COUNT(b.id) AS total')
            ->where('b.isWished = true')
            ->andWhere('b.wishPriority IS NOT NULL')
            ->groupBy('b.wishPriority')
            ->getQuery()
            ->getScalarResult();

        foreach ($rows as $row) {
            // An enumType column comes back as its raw backing value here.
            $counts[(int) $row['priority']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Restricts a query to one shelf. Every caller goes through this rather than
     * writing the predicate inline, so "which shelf?" is a decision each query is
     * forced to make and a new query can't silently inherit the wrong one.
     */
    private function onShelf(QueryBuilder $qb, bool $wished, string $alias = 'b'): QueryBuilder
    {
        return $qb->andWhere(\sprintf('%s.isWished = :wished', $alias))
            ->setParameter('wished', $wished);
    }

    /** Books the community gained per day — wish-list rows are not a gain. */
    protected function scopeCreatedByDay(QueryBuilder $qb): void
    {
        $this->onShelf($qb, false, 'e');
    }

    /**
     * How the whole community's shelves are distributed across statuses.
     *
     * Returns every case, zero-filled: a status nobody currently holds should
     * read as 0 rather than vanish from the chart, which would silently change
     * what the segments mean.
     *
     * @return array<string, int> keyed by BookStatus value
     */
    public function countByStatus(): array
    {
        $counts = [];
        foreach (BookStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        $qb = $this->createQueryBuilder('b')
            ->select('b.status AS status, COUNT(b.id) AS total')
            ->groupBy('b.status');

        $rows = $this->onShelf($qb, false)->getQuery()->getScalarResult();

        foreach ($rows as $row) {
            // An enumType column comes back as its raw backing value here.
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * The most-used categories across every library.
     *
     * Queried from the owning side because Book→Category is unidirectional —
     * Category has no books collection to count. Everything the card renders
     * comes back in this one query, so there is no follow-up hydration.
     *
     * @return list<array{id: int, name: string, colorHex: string, books: int}>
     */
    public function countByCategory(int $limit): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('c.id AS id, c.name AS name, c.colorHex AS colorHex, COUNT(b.id) AS total')
            ->join('b.categories', 'c')
            ->groupBy('c.id')
            ->addGroupBy('c.name')
            ->addGroupBy('c.colorHex')
            ->orderBy('total', 'DESC')
            ->addOrderBy('c.name', 'ASC')
            ->setMaxResults($limit);

        $rows = $this->onShelf($qb, false)->getQuery()->getScalarResult();

        return array_map(
            static fn (array $row) => [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['name'],
                'colorHex' => (string) $row['colorHex'],
                'books'    => (int) $row['total'],
            ],
            $rows,
        );
    }

    /**
     * The most-catalogued languages. Books with no language set are excluded
     * rather than bucketed as "unknown" — a null there means "nobody said", which
     * is not a language and would dominate the list without meaning anything.
     *
     * @return list<array{code: string, books: int}>
     */
    public function countByLanguage(int $limit): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b.language AS code, COUNT(b.id) AS total')
            ->where('b.language IS NOT NULL')
            ->groupBy('b.language')
            ->orderBy('total', 'DESC')
            ->addOrderBy('b.language', 'ASC')
            ->setMaxResults($limit);

        $rows = $this->onShelf($qb, false)->getQuery()->getScalarResult();

        return array_map(
            static fn (array $row) => ['code' => (string) $row['code'], 'books' => (int) $row['total']],
            $rows,
        );
    }

    /**
     * Books owned by a user, optionally filtered by status, newest first.
     *
     * $wished picks the shelf: false (the default) the books they hold, true the
     * ones they want, null both — which only the CSV export asks for, since a
     * round-trip that dropped someone's wish list would lose data silently.
     *
     * @return Book[]
     */
    public function findByOwner(User $owner, ?BookStatus $status = null, ?bool $wished = false): array
    {
        $criteria = ['owner' => $owner];
        if ($status !== null) {
            $criteria['status'] = $status;
        }
        if ($wished !== null) {
            $criteria['isWished'] = $wished;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * Resolves a set of book ids to the ones actually owned by $owner — used to
     * build/borrow collections without trusting client-supplied ids. Order is
     * not guaranteed; unknown or foreign ids simply yield no match.
     *
     * Wish-list ids yield no match either: a collection groups books its owner
     * has, and a wanted one can't be lent to anybody.
     *
     * @param int[] $ids
     * @return Book[]
     */
    public function findByIdsForOwner(array $ids, User $owner): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.id IN (:ids)')
            ->andWhere('b.owner = :owner')
            ->setParameter('ids', $ids)
            ->setParameter('owner', $owner);

        return $this->onShelf($qb, false)->getQuery()->getResult();
    }

    /**
     * One page of a user's books, optionally filtered by status and/or narrowed
     * by a free-text query (title, author or ISBN), newest first. Categories stay
     * lazy (loaded per book by the mapper), matching findByOwner.
     *
     * $wished switches shelves. The wish list leads with the most-wanted books
     * (priority descending, newest first within a level) because that is the
     * question the tab exists to answer; $priority narrows it to one level and
     * $byPriority=false sorts it newest-first instead. All three are ignored on
     * the owned shelf, which has none of those fields.
     *
     * @return PaginatedResult<Book>
     */
    public function findByOwnerPaginated(
        User $owner,
        ?BookStatus $status,
        Pagination $pagination,
        ?string $query = null,
        bool $excludeCollectionHeld = false,
        bool $wished = false,
        ?WishPriority $priority = null,
        bool $byPriority = true,
    ): PaginatedResult {
        $qb = $this->createQueryBuilder('b')
            ->where('b.owner = :owner')
            ->setParameter('owner', $owner);

        $this->onShelf($qb, $wished);

        if ($wished) {
            // Integer-backed priority, so DESC is the ranking itself. $byPriority
            // false falls back to the shelf's ordering (newest first), which is
            // the other way anyone reads a wish list.
            if ($byPriority) {
                $qb->orderBy('b.wishPriority', 'DESC')->addOrderBy('b.createdAt', 'DESC');
            } else {
                $qb->orderBy('b.createdAt', 'DESC');
            }
            if ($priority !== null) {
                $qb->andWhere('b.wishPriority = :priority')->setParameter('priority', $priority);
            }
        } else {
            $qb->orderBy('b.createdAt', 'DESC');
        }
        $qb->addOrderBy('b.id', 'DESC');

        if ($status !== null) {
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        }

        // Exclude books out on loan as part of a collection borrow, so the owner's
        // Lending list shows them only grouped (in the collection card), never also
        // as individual books — mirroring how the request lists exclude children.
        if ($excludeCollectionHeld) {
            $sub = $this->getEntityManager()->createQueryBuilder()
                ->select('IDENTITY(lr.book)')
                ->from(LibraryRequest::class, 'lr')
                ->where('lr.parentRequest IS NOT NULL')
                ->andWhere('lr.status IN (:collectionActive)')
                ->getDQL();
            $qb->andWhere($qb->expr()->notIn('b.id', $sub))
                ->setParameter('collectionActive', [RequestStatus::Approved, RequestStatus::ReturnPending]);
        }

        if ($query !== null && $query !== '') {
            // A book matches when the query is a substring of its title, author or ISBN.
            $qb->andWhere('LOWER(b.title) LIKE :q OR LOWER(b.author) LIKE :q OR LOWER(b.isbn) LIKE :q')
                ->setParameter('q', '%' . $this->escapeLike(mb_strtolower($query)) . '%');
        }

        $qb->setFirstResult($pagination->offset())->setMaxResults($pagination->perPage);

        // No to-many fetch join → no collection to page; DISTINCT count stays exact.
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);

        return new PaginatedResult(iterator_to_array($paginator), \count($paginator));
    }

    public function countByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner, 'isWished' => false]);
    }

    /** How many books this user wants — the Wish List tab's counter. */
    public function countWishedByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner, 'isWished' => true]);
    }

    /**
     * A user's most recently catalogued books, capped. Powers each row of the
     * subscription feed. Categories stay lazy (fetch-joining a to-many alongside
     * setMaxResults would force in-memory limiting); the mapper loads them per book.
     *
     * @return Book[]
     */
    public function findRecentByOwner(User $owner, int $limit = 15): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults($limit);

        return $this->onShelf($qb, false)->getQuery()->getResult();
    }

    public function countByOwnerAndStatus(User $owner, BookStatus $status): int
    {
        return $this->count(['owner' => $owner, 'status' => $status, 'isWished' => false]);
    }

    /**
     * The per-owner variants of the three counters above, for a whole page of
     * users at once: a Discover page of reader cards would otherwise fire three
     * COUNTs per card. One grouped query each, keyed by owner id.
     *
     * @param  User[]         $owners
     * @return array<int,int> owner id => count (owners with none are absent)
     */
    public function countByOwners(array $owners): array
    {
        return $this->groupedCountByOwners($owners);
    }

    /** @param User[] $owners @return array<int,int> */
    public function countShareableByOwners(array $owners): array
    {
        return $this->groupedCountByOwners($owners, static function (QueryBuilder $qb): void {
            $qb->andWhere('b.status != :unavailable')
                ->setParameter('unavailable', BookStatus::Unavailable);
        });
    }

    /** @param User[] $owners @return array<int,int> */
    public function countByOwnersAndStatus(array $owners, BookStatus $status): array
    {
        return $this->groupedCountByOwners($owners, static function (QueryBuilder $qb) use ($status): void {
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        });
    }

    /** @param User[] $owners @return array<int,int> */
    public function countWishedByOwners(array $owners): array
    {
        return $this->groupedCountByOwners($owners, wished: true);
    }

    /**
     * @param  User[]              $owners
     * @param  ?callable(QueryBuilder):void $filter extra constraints on the counted books
     * @return array<int,int>
     */
    private function groupedCountByOwners(array $owners, ?callable $filter = null, bool $wished = false): array
    {
        if ($owners === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('b')
            ->select('IDENTITY(b.owner) AS ownerId, COUNT(b.id) AS total')
            ->where('b.owner IN (:owners)')
            ->setParameter('owners', $owners)
            ->groupBy('b.owner');

        $this->onShelf($qb, $wished);

        if ($filter !== null) {
            $filter($qb);
        }

        $counts = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            $counts[(int) $row['ownerId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Books whose title or ISBN matches the query, newest first, capped. Powers
     * the "Add New Book" template search: it spans *every* library (private
     * included) because only bibliographic metadata is copied out — never the
     * owner — so it can't reveal who holds a book. De-duplication of identical
     * copies happens in the provider (needs the full row to compare).
     *
     * Wish-list rows are excluded: the source is named "templates on the site",
     * and a wanted book's metadata is whatever its would-be owner typed from a
     * shop listing rather than something anyone has verified against a copy.
     *
     * @return Book[]
     */
    public function searchTemplates(string $query, int $limit): array
    {
        $like = '%' . $this->escapeLike(mb_strtolower($query)) . '%';

        $qb = $this->createQueryBuilder('b')
            ->innerJoin('b.owner', 'o')
            ->where('LOWER(b.title) LIKE :q OR LOWER(b.isbn) LIKE :q')
            ->setParameter('q', $like)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults($limit);

        // The owner is joined for this predicate alone and never selected: the
        // whole point of the template shape is that it copies bibliographic
        // fields and never names who holds the book.
        VisibleUsers::scope($qb, 'o');

        return $this->onShelf($qb, false)->getQuery()->getResult();
    }

    /**
     * Community books for Discover: shareable books (status != unavailable) owned
     * by *other* members whose profile is public. Optionally narrowed by a
     * free-text query (title or author) and/or a category. Newest first, capped.
     *
     * The owner is eager-loaded (to-one) so the mapper can attribute each book
     * without an N+1. Categories stay lazy — fetch-joining a to-many alongside
     * setMaxResults would force in-memory limiting.
     *
     * @return Book[]
     */
    public function findForDiscover(
        User $viewer,
        ?string $query = null,
        ?Category $category = null,
        ?string $language = null,
        int $limit = 60,
    ): array {
        return $this->discoverQuery($viewer, $query, $category, $language)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * One page of Discover results, with the total matching count. A book
     * references a given category at most once, so the optional category join
     * never multiplies rows — Paginator's DISTINCT count stays exact.
     *
     * @return PaginatedResult<Book>
     */
    public function findForDiscoverPaginated(
        User $viewer,
        ?string $query,
        ?Category $category,
        ?string $language,
        Pagination $pagination,
    ): PaginatedResult {
        $query = $this->discoverQuery($viewer, $query, $category, $language)
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        // owner is a to-one fetch join; categories stay lazy → no collection to page.
        $paginator = new Paginator($query, fetchJoinCollection: false);

        return new PaginatedResult(iterator_to_array($paginator), \count($paginator));
    }

    /**
     * Builds the shared Discover filter query (community books from public
     * members, excluding the viewer and unavailable books), newest first.
     */
    private function discoverQuery(
        User $viewer,
        ?string $query,
        ?Category $category,
        ?string $language,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('b')
            ->innerJoin('b.owner', 'o')->addSelect('o')
            ->where('o.id != :viewer')
            ->andWhere('o.isPrivate = false')
            ->andWhere('b.status != :unavailable')
            ->setParameter('viewer', $viewer->getId())
            ->setParameter('unavailable', BookStatus::Unavailable)
            ->orderBy('b.createdAt', 'DESC');

        // A suspended or deleted member's shelf leaves Discover with them.
        VisibleUsers::scope($qb, 'o');

        // Discover is what you could borrow; nobody can borrow a wanted book.
        $this->onShelf($qb, false);

        if ($language !== null && $language !== '') {
            $qb->andWhere('b.language = :language')->setParameter('language', $language);
        }

        if ($query !== null && $query !== '') {
            // A book matches when the query is a substring of its title or author.
            $qb->andWhere('LOWER(b.title) LIKE :q OR LOWER(b.author) LIKE :q')
                ->setParameter('q', '%' . $this->escapeLike(mb_strtolower($query)) . '%');
        }

        if ($category !== null) {
            $qb->innerJoin('b.categories', 'c')
                ->andWhere('c = :category')
                ->setParameter('category', $category);
        }

        return $qb;
    }

    /** Books owned by the user that are shareable (status != unavailable). */
    public function countShareableByOwner(User $owner): int
    {
        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.owner = :owner')
            ->andWhere('b.status != :unavailable')
            ->setParameter('owner', $owner)
            ->setParameter('unavailable', BookStatus::Unavailable);

        return $this->onShelf($qb, false)->getQuery()->getSingleScalarResult();
    }

    /** Escapes LIKE wildcards so user input is matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
