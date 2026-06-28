<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return Category[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * Case-insensitive substring search by name, alphabetical, capped.
     *
     * @return Category[]
     */
    public function search(string $query, int $limit = 10): array
    {
        // Wildcards in the input are escaped with a backslash, which is also
        // PostgreSQL's default LIKE escape character — no ESCAPE clause needed.
        return $this->createQueryBuilder('c')
            ->where('LOWER(c.name) LIKE LOWER(:q)')
            ->setParameter('q', '%' . $this->escapeLike($query) . '%')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Exact (case-insensitive) name lookup — used to guard against duplicates. */
    public function findOneByNameInsensitive(string $name): ?Category
    {
        return $this->createQueryBuilder('c')
            ->where('LOWER(c.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Exact (case-insensitive) lookup of multiple categories by name. Used by
     * CSV import to resolve category columns against the existing vocabulary —
     * unknown names simply yield no match.
     *
     * @param string[] $names
     * @return Category[]
     */
    public function findByNames(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $lowered = array_map(static fn (string $n) => mb_strtolower($n), $names);

        return $this->createQueryBuilder('c')
            ->where('LOWER(c.name) IN (:names)')
            ->setParameter('names', $lowered)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param int[] $ids
     * @return Category[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /** Escapes LIKE wildcards so user input is matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
