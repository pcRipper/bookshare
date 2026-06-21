<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSettings::class);
    }

    /**
     * The user's settings row, creating one (with default values) the first time
     * it's needed. Persists but never flushes — the controller owns the
     * transaction, per the project convention.
     */
    public function findOrCreateFor(User $user): UserSettings
    {
        $settings = $user->getSettings();
        if ($settings !== null) {
            return $settings;
        }

        $settings = (new UserSettings())->setUser($user);
        $user->setSettings($settings);
        $this->getEntityManager()->persist($settings);

        return $settings;
    }
}
