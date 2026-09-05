<?php

namespace App\Tests\Service\Admin;

use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\UserSettings;
use App\Enum\ActivityType;
use App\Enum\BookStatus;
use App\Enum\RequestStatus;
use App\Service\Admin\UserPurger;
use App\Tests\Repository\RepositoryTestCase;

/**
 * DB-backed rather than stubbed, for the reason StatsProviderTest gives about
 * itself: what can actually go wrong here is not the PHP.
 *
 * The purger's whole job is to take rows out in an order the foreign keys
 * tolerate, leaning on three database-level ON DELETE CASCADEs (a book's
 * requests, a collection's request history, a collection request's children) and
 * working around the four columns that have no rule at all. Stubbed repositories
 * would assert that we called remove() the number of times we told them to
 * expect, and would go on passing after a schema change turned the real thing
 * into a constraint violation.
 */
class UserPurgerTest extends RepositoryTestCase
{
    private function purger(): UserPurger
    {
        return self::getContainer()->get(UserPurger::class);
    }

    /** Commits the staged unit of work — the purger itself never flushes. */
    private function purge(User $user): void
    {
        $this->purger()->purge($user);
        $this->em->flush();
    }

    public function testTheRowSurvivesButNothingIdentifyingDoes(): void
    {
        $user = $this->makeUser();
        $user->setBio('Reads a lot')->setLocation('Kyiv')->setAvatarUrl('/uploads/avatars/a.jpg');
        $this->em->flush();

        $id = $user->getId();
        $this->purge($user);

        self::assertTrue($user->isDeleted());
        self::assertSame('Deleted member', $user->getFullName());
        self::assertNull($user->getBio());
        self::assertNull($user->getLocation());
        self::assertNull($user->getAvatarUrl());
        self::assertTrue($user->isPrivate());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame($id, $user->getId(), 'The row itself must survive.');
    }

    /**
     * Both are UNIQUE lookup keys. Left intact, findOrCreateFromGoogle() would
     * hand the returning member their own anonymized row back — an account with
     * no books and somebody else's loan history attached.
     */
    public function testTheLoginKeysAreRewrittenSoTheAccountCannotBeResurrected(): void
    {
        $user = $this->makeUser();
        $this->em->flush();
        $id = $user->getId();

        $this->purge($user);

        self::assertSame("deleted-{$id}@deleted.invalid", $user->getEmail());
        self::assertSame("deleted-{$id}", $user->getGoogleId());
    }

    public function testBothShelvesAndEveryCollectionAreDestroyed(): void
    {
        $user = $this->makeUser();
        $owned = $this->makeBook($user);
        $second = $this->makeBook($user);
        $wanted = $this->makeBook($user)->setWish(true);
        $collection = $this->makeCollection($user, [$owned, $second]);
        $this->em->flush();

        [$ownedId, $wantedId, $collectionId] = [$owned->getId(), $wanted->getId(), $collection->getId()];

        $this->purge($user);
        $this->em->clear();

        self::assertNull($this->em->find(Book::class, $ownedId));
        self::assertNull($this->em->find(Book::class, $wantedId), 'The wish list is a shelf too.');
        self::assertNull($this->em->find(\App\Entity\BookCollection::class, $collectionId));
    }

    /**
     * Without this the lender is left with a book stuck in `lent`, held by an
     * account that no longer answers for it — a loan whose return they can never
     * confirm.
     */
    public function testABookTheyWereBorrowingGoesHome(): void
    {
        $lender = $this->makeUser();
        $borrower = $this->makeUser();
        $book = $this->makeBook($lender, BookStatus::Lent, holder: $borrower);
        $this->makeRequest($book, $borrower, RequestStatus::Returned);
        $this->em->flush();

        $this->purge($borrower);

        self::assertSame($lender, $book->getCurrentHolder());
        self::assertSame(BookStatus::Own, $book->getStatus());
        self::assertTrue($book->isHome());
    }

    public function testTheirOwnRequestsGoButTheCounterpartsBookStays(): void
    {
        $lender = $this->makeUser();
        $borrower = $this->makeUser();
        $book = $this->makeBook($lender);
        $request = $this->makeRequest($book, $borrower, RequestStatus::Returned);
        $this->em->flush();

        [$bookId, $requestId] = [$book->getId(), $request->getId()];

        $this->purge($borrower);
        $this->em->clear();

        self::assertNull($this->em->find(\App\Entity\LibraryRequest::class, $requestId));
        self::assertNotNull($this->em->find(Book::class, $bookId), 'The lender keeps their book.');
    }

    /**
     * The parent goes, and library_request.parent_request_id ON DELETE CASCADE
     * takes the children with it. This is the ordering the DB has to tolerate.
     */
    public function testACollectionBorrowIsRemovedParentAndChildrenTogether(): void
    {
        $lender = $this->makeUser();
        $borrower = $this->makeUser();
        $collection = $this->makeCollection($lender, [$this->makeBook($lender), $this->makeBook($lender)]);
        $parent = $this->makeCollectionBorrow($collection, $borrower, RequestStatus::Returned);
        $this->em->flush();

        $parentId = $parent->getId();
        $childIds = array_map(static fn ($c) => $c->getId(), $parent->getChildren()->toArray());

        $this->purge($borrower);
        $this->em->clear();

        self::assertNull($this->em->find(\App\Entity\CollectionRequest::class, $parentId));
        foreach ($childIds as $childId) {
            self::assertNull($this->em->find(\App\Entity\LibraryRequest::class, $childId));
        }
    }

    public function testFollowsGoInBothDirections(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $this->em->persist((new Subscription())->setSubscriber($user)->setSubscribedTo($other));
        $this->em->persist((new Subscription())->setSubscriber($other)->setSubscribedTo($user));
        $this->em->flush();

        $this->purge($user);
        $this->em->clear();

        $remaining = $this->em->getRepository(Subscription::class)->count([]);
        self::assertSame(0, $remaining);
    }

    /**
     * ActivityItem.target_user is ON DELETE SET NULL, which never fires here
     * because the row survives — so somebody else's "followed X" entry has to be
     * unlinked by hand or it goes on rendering as a link to the dead account.
     */
    public function testSomebodyElsesActivityIsUnlinkedRatherThanDeleted(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        $theirs = (new ActivityItem())->setActor($user)->setActionType(ActivityType::Followed);
        $about = (new ActivityItem())->setActor($other)->setActionType(ActivityType::Followed)->setTargetUser($user);
        $this->em->persist($theirs);
        $this->em->persist($about);
        $this->em->flush();

        [$theirsId, $aboutId] = [$theirs->getId(), $about->getId()];

        $this->purge($user);
        $this->em->clear();

        self::assertNull($this->em->find(ActivityItem::class, $theirsId));

        $survivor = $this->em->find(ActivityItem::class, $aboutId);
        self::assertNotNull($survivor, "Another member's feed entry is theirs, not ours to delete.");
        self::assertNull($survivor->getTargetUser());
    }

    public function testTheSettingsRowGoesWithTheAccount(): void
    {
        $user = $this->makeUser();
        $settings = (new UserSettings())->setUser($user);
        $user->setSettings($settings);
        $this->em->persist($settings);
        $this->em->flush();

        $settingsId = $settings->getId();

        $this->purge($user);
        $this->em->clear();

        self::assertNull($this->em->find(UserSettings::class, $settingsId));
    }

    /**
     * A double-clicked button or a retried request must not make a second,
     * differently-anonymized pass — which would move deletedAt and rewrite an
     * already-rewritten email into a still stranger one.
     */
    public function testPurgingTwiceIsANoOp(): void
    {
        $user = $this->makeUser();
        $this->em->flush();

        $this->purge($user);
        $email = $user->getEmail();
        $deletedAt = $user->getDeletedAt();

        $this->purge($user);

        self::assertSame($email, $user->getEmail());
        self::assertSame($deletedAt, $user->getDeletedAt());
    }
}
