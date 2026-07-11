<?php

namespace App\Repository;

use App\Dto\Pagination;
use App\Dto\PaginatedResult;
use App\Entity\BookCollection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookCollection>
 */
class CollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookCollection::class);
    }

    /**
     * A user's collections, newest first, paginated. We page on the root entity
     * only (so the count stays correct), then eagerly hydrate each collection's
     * books — and their categories/holder/owner — in one follow-up query, so the
     * mapper doesn't N+1 per collection and per book.
     *
     * @return PaginatedResult<BookCollection>
     */
    public function findByOwnerPaginated(User $owner, Pagination $pagination): PaginatedResult
    {
        $query = $this->createQueryBuilder('c')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        $paginator = new Paginator($query, fetchJoinCollection: false);
        $collections = iterator_to_array($paginator);

        return new PaginatedResult($this->hydrateBooks($collections), count($paginator));
    }

    /**
     * Populates the books collection (+ each book's categories, current holder and
     * owner) on already-managed BookCollection entities via the Unit of Work, in a
     * single query — so mapping the page never lazy-loads per collection/book.
     *
     * @param BookCollection[] $collections
     * @return BookCollection[]
     */
    private function hydrateBooks(array $collections): array
    {
        if ($collections !== []) {
            $this->createQueryBuilder('c')
                ->leftJoin('c.books', 'b')->addSelect('b')
                ->leftJoin('b.categories', 'cat')->addSelect('cat')
                ->leftJoin('b.currentHolder', 'ch')->addSelect('ch')
                ->leftJoin('b.owner', 'o')->addSelect('o')
                ->andWhere('c IN (:collections)')
                ->setParameter('collections', $collections)
                ->getQuery()
                ->getResult();
        }

        return $collections;
    }

    /** How many collections a user owns — powers the profile tab counter. */
    public function countByOwner(User $owner): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.owner = :owner')
            ->setParameter('owner', $owner)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
