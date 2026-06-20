<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CategoryRepository extends ServiceEntityRepository
{
    /** Muted earth-tone palette assigned to newly created categories. */
    private const PALETTE = [
        '#E8F0EA', '#F4EAE0', '#dae4ed', '#F0E8ED', '#E8EEF0', '#f5f0e8',
    ];

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
     * Resolves a list of category names to Category entities, creating any that
     * don't exist yet. New categories are persisted (caller flushes).
     *
     * @param string[] $names
     * @return Category[]
     */
    public function findOrCreateByNames(array $names): array
    {
        $result = [];
        foreach ($names as $rawName) {
            $name = trim($rawName);
            if ($name === '') {
                continue;
            }

            $category = $this->findOneBy(['name' => $name]);
            if (!$category) {
                $category = (new Category())
                    ->setName($name)
                    ->setColorHex(self::PALETTE[abs(crc32($name)) % count(self::PALETTE)]);
                $this->getEntityManager()->persist($category);
            }
            $result[$name] = $category;
        }

        return array_values($result);
    }
}
