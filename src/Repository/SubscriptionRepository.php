<?php

namespace App\Repository;

use App\Dto\PaginatedResult;
use App\Dto\Pagination;
use App\Entity\Subscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findOneFor(User $subscriber, User $subscribedTo): ?Subscription
    {
        return $this->findOneBy(['subscriber' => $subscriber, 'subscribedTo' => $subscribedTo]);
    }

    public function isSubscribed(User $subscriber, User $subscribedTo): bool
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.subscriber = :subscriber')
            ->andWhere('s.subscribedTo = :subscribedTo')
            ->setParameter('subscriber', $subscriber)
            ->setParameter('subscribedTo', $subscribedTo)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * The people a subscriber follows, most recently followed first. The followed
     * user is eager-loaded so the mapper can shape each row without an N+1.
     *
     * @return Subscription[]
     */
    public function findFollowing(User $subscriber): array
    {
        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.subscribedTo', 'u')->addSelect('u')
            ->andWhere('s.subscriber = :subscriber')
            ->setParameter('subscriber', $subscriber)
            ->orderBy('s.createdAt', 'DESC');

        return VisibleUsers::scope($qb, 'u')->getQuery()->getResult();
    }

    /**
     * One page of the people a subscriber follows, most recently followed first,
     * with the total follow count. The followed user is eager-loaded (to-one).
     *
     * @return PaginatedResult<Subscription>
     */
    public function findFollowingPaginated(User $subscriber, Pagination $pagination): PaginatedResult
    {
        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.subscribedTo', 'u')->addSelect('u')
            ->andWhere('s.subscriber = :subscriber')
            ->setParameter('subscriber', $subscriber)
            ->orderBy('s.createdAt', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage);

        // The edge survives a suspension so a lifted ban restores the follow,
        // but the person drops out of the list while it stands — the row would
        // otherwise be the one place their state was visible to a member.
        $query = VisibleUsers::scope($qb, 'u')->getQuery();

        $paginator = new Paginator($query, fetchJoinCollection: false);

        return new PaginatedResult(iterator_to_array($paginator), \count($paginator));
    }

    /**
     * The non-private users a subscriber follows — the feed only surfaces readers
     * whose library is public (a followed user who later goes private drops out).
     *
     * @return User[]
     */
    public function findFollowedUsers(User $subscriber): array
    {
        // Select the root subscription (Doctrine forbids selecting only a joined
        // alias) and the followed user, then map to the User entities.
        $qb = $this->createQueryBuilder('s')
            ->innerJoin('s.subscribedTo', 'u')->addSelect('u')
            ->andWhere('s.subscriber = :subscriber')
            ->andWhere('u.isPrivate = false')
            ->setParameter('subscriber', $subscriber)
            ->orderBy('s.createdAt', 'DESC');

        $subscriptions = VisibleUsers::scope($qb, 'u')->getQuery()->getResult();

        return array_map(static fn (Subscription $s) => $s->getSubscribedTo(), $subscriptions);
    }
}
