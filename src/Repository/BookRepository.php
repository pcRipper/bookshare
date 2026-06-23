<?php

namespace App\Repository;

use App\Entity\Book;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\BookStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        int $limit = 60,
    ): array {
        $qb = $this->createQueryBuilder('b')
            ->innerJoin('b.owner', 'o')->addSelect('o')
            ->where('o.id != :viewer')
            ->andWhere('o.isPrivate = false')
            ->andWhere('b.status != :unavailable')
            ->setParameter('viewer', $viewer->getId())
            ->setParameter('unavailable', BookStatus::Unavailable)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($query !== null && $query !== '') {
            // A book matches when the query is a substring of its title or author.
            $qb->andWhere('LOWER(b.title) LIKE :q OR LOWER(b.author) LIKE :q')
                ->setParameter('q', '%' . $this->escapeLike(mb_strtolower($query)) . '%');
        }

        if ($category !== null) {
            // No duplicate rows: a book references a given category at most once.
            $qb->innerJoin('b.categories', 'c')
                ->andWhere('c = :category')
                ->setParameter('category', $category);
        }

        return $qb->getQuery()->getResult();
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
