<?php

namespace App\Repository;

use App\Dto\PaginatedResult;
use App\Dto\Pagination;
use App\Entity\Book;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\BookStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * Books owned by a user, optionally filtered by status, newest first.
     *
     * @return Book[]
     */
    public function findByOwner(User $owner, ?BookStatus $status = null): array
    {
        $criteria = ['owner' => $owner];
        if ($status !== null) {
            $criteria['status'] = $status;
        }

        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /**
     * One page of a user's books, optionally filtered by status, newest first.
     * Categories stay lazy (loaded per book by the mapper), matching findByOwner.
     *
     * @return PaginatedResult<Book>
     */
    public function findByOwnerPaginated(User $owner, ?BookStatus $status, Pagination $pagination): PaginatedResult
    {
        $criteria = ['owner' => $owner];
        if ($status !== null) {
            $criteria['status'] = $status;
        }

        return new PaginatedResult(
            $this->findBy($criteria, ['createdAt' => 'DESC'], $pagination->perPage, $pagination->offset()),
            $this->count($criteria),
        );
    }

    public function countByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner]);
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
        return $this->createQueryBuilder('b')
            ->andWhere('b.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByOwnerAndStatus(User $owner, BookStatus $status): int
    {
        return $this->count(['owner' => $owner, 'status' => $status]);
    }

    /**
     * Books whose title or ISBN matches the query, newest first, capped. Powers
     * the "Add New Book" template search: it spans *every* library (private
     * included) because only bibliographic metadata is copied out — never the
     * owner — so it can't reveal who holds a book. De-duplication of identical
     * copies happens in the provider (needs the full row to compare).
     *
     * @return Book[]
     */
    public function searchTemplates(string $query, int $limit): array
    {
        $like = '%' . $this->escapeLike(mb_strtolower($query)) . '%';

        return $this->createQueryBuilder('b')
            ->where('LOWER(b.title) LIKE :q OR LOWER(b.isbn) LIKE :q')
            ->setParameter('q', $like)
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
        return $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.owner = :owner')
            ->andWhere('b.status != :unavailable')
            ->setParameter('owner', $owner)
            ->setParameter('unavailable', BookStatus::Unavailable)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Escapes LIKE wildcards so user input is matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
