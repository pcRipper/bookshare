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
        ?string $avatarUrl,
    ): User {
        $user = $this->findOneBy(['googleId' => $googleId]);
        if ($user) {
            return $user;
        }

        // User exists with this email but registered via a different method
        $user = $this->findOneBy(['email' => $email]);
        if ($user) {
            $user->setGoogleId($googleId)->setAvatarUrl($avatarUrl);
            $this->getEntityManager()->flush();
            return $user;
        }

        $user = (new User())
            ->setGoogleId($googleId)
            ->setEmail($email)
            ->setFullName($fullName)
            ->setAvatarUrl($avatarUrl);

        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();

        return $user;
    }
}
