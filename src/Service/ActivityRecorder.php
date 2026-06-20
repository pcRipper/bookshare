<?php

namespace App\Service;

use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\ActivityType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists ActivityItem records for the social feed. Does not flush —
 * the controller owns the transaction boundary.
 */
class ActivityRecorder
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function record(
        User $actor,
        ActivityType $type,
        ?Book $targetBook = null,
        ?User $targetUser = null,
        ?string $commentText = null,
    ): ActivityItem {
        $item = (new ActivityItem())
            ->setActor($actor)
            ->setActionType($type)
            ->setTargetBook($targetBook)
            ->setTargetUser($targetUser)
            ->setCommentText($commentText);

        $this->em->persist($item);

        return $item;
    }
}
