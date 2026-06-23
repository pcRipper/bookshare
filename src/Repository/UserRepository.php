<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOrCreateFromGoogle(
        string $googleId,
        string $email,
        string $fullName,
    ): User {
        $user = $this->findOneBy(['googleId' => $googleId]);
        if ($user) {
            return $user;
        }

        // User exists with this email but registered via a different method
        $user = $this->findOneBy(['email' => $email]);
        if ($user) {
            $user->setGoogleId($googleId);
            return $user;
        }

        $user = (new User())
            ->setGoogleId($googleId)
            ->setEmail($email)
            ->setFullName($fullName);

        $this->getEntityManager()->persist($user);

        return $user;
    }

    /**
     * Public members matching a free-text name query, for the Discover "Accounts"
     * search: other users (never the viewer) whose profile is public, name a
     * case-insensitive substring of the query. Alphabetical, capped.
     *
     * @return User[]
     */
    public function findPublicForDiscover(User $viewer, string $query, int $limit = 20): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.id != :viewer')
            ->andWhere('u.isPrivate = false')
            ->andWhere('LOWER(u.fullName) LIKE :q')
            ->setParameter('viewer', $viewer->getId())
            ->setParameter('q', '%' . $this->escapeLike(mb_strtolower($query)) . '%')
            ->orderBy('u.fullName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Escapes LIKE wildcards so user input is matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
