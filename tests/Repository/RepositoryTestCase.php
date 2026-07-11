<?php

namespace App\Tests\Repository;

use App\Entity\Book;
use App\Entity\BookCollection;
use App\Entity\CollectionRequest;
use App\Entity\LibraryRequest;
use App\Entity\User;
use App\Enum\BookStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base for repository integration tests. Unlike the rest of the suite (pure unit,
 * no DB), these exercise the actual DQL against the test database, since that's
 * where query bugs hide — the exclusion of collection children from the individual
 * request lists, the collection-request status filters, etc.
 *
 * Each test runs inside a transaction that's rolled back afterwards, so the DB
 * stays clean without re-creating the schema. If the test database isn't reachable
 * (a machine that never provisioned it) the test is skipped, not failed, so the
 * default `php bin/phpunit` stays green everywhere.
 *
 * Provision once:
 *   php bin/console doctrine:database:create --env=test
 *   php bin/console doctrine:migrations:migrate --env=test -n
 */
abstract class RepositoryTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    private int $seq = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Test database unavailable: ' . $e->getMessage());
        }

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        if ($conn->isTransactionActive()) {
            $conn->rollBack();
        }
        parent::tearDown();
    }

    /* ── fixture helpers ─────────────────────────────────────────────────── */

    protected function makeUser(bool $private = false): User
    {
        $n = ++$this->seq;
        $user = (new User())
            ->setGoogleId("g-{$n}")
            ->setEmail("user{$n}@test.local")
            ->setFullName("User {$n}")
            ->setIsPrivate($private);
        $this->em->persist($user);

        return $user;
    }

    protected function makeBook(User $owner, BookStatus $status = BookStatus::Own, ?User $holder = null): Book
    {
        $n = ++$this->seq;
        $book = (new Book())
            ->setTitle("Book {$n}")
            ->setAuthor('Author')
            ->setOwner($owner)
            ->setCurrentHolder($holder ?? $owner)
            ->setStatus($status);
        $this->em->persist($book);

        return $book;
    }

    /** @param Book[] $books */
    protected function makeCollection(User $owner, array $books): BookCollection
    {
        $collection = (new BookCollection())->setOwner($owner)->setName('Series');
        foreach ($books as $book) {
            $collection->addBook($book);
        }
        $this->em->persist($collection);

        return $collection;
    }

    /** An individual (non-collection) borrow request. */
    protected function makeRequest(Book $book, User $requester, \App\Enum\RequestStatus $status): LibraryRequest
    {
        $request = (new LibraryRequest())->setBook($book)->setRequester($requester)->setStatus($status);
        $this->em->persist($request);

        return $request;
    }

    /**
     * A collection borrow: a parent CollectionRequest plus one child LibraryRequest
     * per book, each linked back to the parent (parentRequest set via addChild).
     *
     * @param Book[] $books
     */
    protected function makeCollectionBorrow(
        BookCollection $collection,
        User $requester,
        \App\Enum\RequestStatus $status,
    ): CollectionRequest {
        $parent = (new CollectionRequest())
            ->setCollection($collection)
            ->setRequester($requester)
            ->setStatus($status);

        foreach ($collection->getBooks() as $book) {
            $child = (new LibraryRequest())->setBook($book)->setRequester($requester)->setStatus($status);
            $parent->addChild($child);
            $this->em->persist($child);
        }
        $this->em->persist($parent);

        return $parent;
    }
}
