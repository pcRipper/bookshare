<?php

namespace App\Repository;

use App\Dto\PaginatedResult;
use App\Dto\Pagination;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    use CountsCreatedByDay;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** Total members, for the dashboard's growth tile. */
    public function countAll(): int
    {
        return $this->count([]);
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
     * One page of Discover "Accounts" readers, with the total matching count.
     * A null/blank query browses instead of searching (see discoverQuery).
     *
     * @return PaginatedResult<User>
     */
    public function findPublicForDiscoverPaginated(User $viewer, ?string $query, Pagination $pagination): PaginatedResult
    {
        $query = $this->discoverQuery($viewer, $query)
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery();

        $paginator = new Paginator($query, fetchJoinCollection: false);

        return new PaginatedResult(iterator_to_array($paginator), \count($paginator));
    }

    /**
     * Other users (never the viewer) whose profile is public. With a query it is a
     * case-insensitive substring search on the name, ordered alphabetically; with a
     * blank one it browses the whole public membership newest-first, mirroring the
     * books feed (BookRepository::findForDiscover) so the tab isn't empty by default.
     */
    private function discoverQuery(User $viewer, ?string $query): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('u')
            ->where('u.id != :viewer')
            ->andWhere('u.isPrivate = false')
            ->setParameter('viewer', $viewer->getId());

        if ($query === null || $query === '') {
            // id breaks ties so paging stays stable when several members were
            // created in the same instant (fixtures, bulk imports).
            return $qb->orderBy('u.createdAt', 'DESC')->addOrderBy('u.id', 'DESC');
        }

        return $qb
            ->andWhere('LOWER(u.fullName) LIKE :q')
            ->setParameter('q', '%' . $this->escapeLike(mb_strtolower($query)) . '%')
            ->orderBy('u.fullName', 'ASC');
    }

    /** Escapes LIKE wildcards so user input is matched literally. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
