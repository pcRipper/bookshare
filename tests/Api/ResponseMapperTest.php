<?php

namespace App\Tests\Api;

use App\Api\ResponseMapper;
use App\Dto\Pagination;
use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\Category;
use App\Entity\LibraryRequest;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\BookStatus;
use App\Enum\LibraryRequestEventType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class ResponseMapperTest extends TestCase
{
    private function mapper(bool $canEdit = true): ResponseMapper
    {
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn($canEdit);

        return new ResponseMapper($auth);
    }

    public function testBookShapeIncludesHolderAndCanEdit(): void
    {
        $owner = (new User())->setFullName('Jane');
        $book = (new Book())->setOwner($owner)->setTitle('Dune')->setAuthor('Herbert')->setStatus(BookStatus::Own);
        $book->addCategory((new Category())->setName('Sci-Fi')->setColorHex('#E8F0EA'));

        $data = $this->mapper(true)->book($book);

        self::assertSame('Dune', $data['title']);
        self::assertSame('Herbert', $data['author']);
        self::assertSame('own', $data['status']);
        self::assertTrue($data['isHome']);
        self::assertTrue($data['canEdit']);
        self::assertSame('Jane', $data['currentHolder']['fullName']);
        self::assertCount(1, $data['categories']);
        self::assertSame('Sci-Fi', $data['categories'][0]['name']);
        self::assertSame('#E8F0EA', $data['categories'][0]['colorHex']);
    }

    public function testBookLanguageIsEmittedAsCodeAndName(): void
    {
        $book = (new Book())->setOwner((new User())->setFullName('Jane'))
            ->setTitle('T')->setAuthor('A')->setLanguage('en');

        $data = $this->mapper()->book($book);

        self::assertSame('en', $data['language']);
        self::assertSame('English', $data['languageName']);
    }

    public function testBookTemplateShapeCarriesMetadataWithResolvedLanguage(): void
    {
        $template = new \App\Dto\BookTemplate('Dune', 'Frank Herbert', '978-1', 'http://c/1.jpg', 'en');

        $data = $this->mapper()->bookTemplate($template);

        self::assertSame(
            ['title', 'author', 'isbn', 'coverPath', 'language', 'languageName'],
            array_keys($data),
        );
        self::assertSame('Dune', $data['title']);
        self::assertSame('en', $data['language']);
        self::assertSame('English', $data['languageName']);
        // A template is metadata only — no owner or id leaks through.
        self::assertArrayNotHasKey('owner', $data);
        self::assertArrayNotHasKey('id', $data);
    }

    public function testBookLanguageIsNullWhenUnset(): void
    {
        $book = (new Book())->setOwner((new User())->setFullName('Jane'))->setTitle('T')->setAuthor('A');

        $data = $this->mapper()->book($book);

        self::assertNull($data['language']);
        self::assertNull($data['languageName']);
    }

    public function testBookCanEditReflectsTheAuthorizationChecker(): void
    {
        $book = (new Book())->setOwner((new User())->setFullName('Owner'))->setTitle('T')->setAuthor('A');

        self::assertFalse($this->mapper(false)->book($book)['canEdit']);
    }

    public function testUserSummaryShape(): void
    {
        $user = (new User())->setFullName('Bob')->setAvatarUrl('/a.png');

        self::assertSame(
            ['id' => null, 'fullName' => 'Bob', 'avatarUrl' => '/a.png'],
            $this->mapper()->userSummary($user),
        );
    }

    public function testRequestShapeIncludesEventsRequesterAndBookOwner(): void
    {
        $owner = (new User())->setFullName('Owner');
        $requester = (new User())->setFullName('Borrower');
        $book = (new Book())->setOwner($owner)->setTitle('Book')->setAuthor('Auth');
        $request = (new LibraryRequest())->setBook($book)->setRequester($requester);
        $request->addEvent(LibraryRequestEventType::Requested, $requester);

        $data = $this->mapper()->request($request);

        self::assertSame('pending', $data['status']);
        self::assertSame('Borrower', $data['requester']['fullName']);
        self::assertSame('Book', $data['book']['title']);
        self::assertSame('Owner', $data['book']['owner']['fullName']);
        self::assertCount(1, $data['events']);
        self::assertSame('requested', $data['events'][0]['type']);
        // Timestamps are serialised as ISO-8601.
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['requestedAt']));
    }

    public function testRequestEventShape(): void
    {
        $actor = (new User())->setFullName('Actor');
        $request = new LibraryRequest();
        $due = new \DateTimeImmutable('2030-01-01');
        $event = $request->addEvent(LibraryRequestEventType::Approved, $actor, $due);

        $data = $this->mapper()->requestEvent($event);

        self::assertSame('approved', $data['type']);
        self::assertSame('Actor', $data['actor']['fullName']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['createdAt']));
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['dueDate']));
    }

    public function testRequestEventDueDateIsNullForNonApproval(): void
    {
        $request = new LibraryRequest();
        $event = $request->addEvent(LibraryRequestEventType::Declined, (new User())->setFullName('Owner'));

        self::assertNull($this->mapper()->requestEvent($event)['dueDate']);
    }

    public function testDiscoverBookAddsOwner(): void
    {
        $owner = (new User())->setFullName('Owner');
        $book = (new Book())->setOwner($owner)->setTitle('T')->setAuthor('A');

        $data = $this->mapper()->discoverBook($book);

        self::assertArrayHasKey('owner', $data);
        self::assertSame('Owner', $data['owner']['fullName']);
        self::assertArrayHasKey('canEdit', $data);
    }

    public function testProfileShape(): void
    {
        $user = (new User())->setFullName('Jane')->setBio('Bio')->setLocation('Lviv');
        $stats = ['totalBooks' => 3, 'shared' => 2, 'loaned' => 1];

        $data = $this->mapper()->profile($user, $stats, true);

        self::assertSame('Jane', $data['fullName']);
        self::assertSame('Bio', $data['bio']);
        self::assertSame('Lviv', $data['location']);
        self::assertTrue($data['isSelf']);
        self::assertSame($stats, $data['stats']);
    }

    public function testProfileIncludesIsSubscribed(): void
    {
        $user = (new User())->setFullName('Jane');
        $stats = ['totalBooks' => 0, 'shared' => 0, 'loaned' => 0];

        self::assertFalse($this->mapper()->profile($user, $stats, true)['isSubscribed']);
        self::assertTrue($this->mapper()->profile($user, $stats, false, isSubscribed: true)['isSubscribed']);
    }

    public function testUserCardShape(): void
    {
        $user = (new User())->setFullName('Jane')->setBio('Reads sci-fi')->setAvatarUrl('/a.png');
        $stats = ['totalBooks' => 5, 'shared' => 3, 'loaned' => 1];

        $data = $this->mapper()->userCard($user, $stats, true);

        self::assertSame('Jane', $data['fullName']);
        self::assertSame('Reads sci-fi', $data['bio']);
        self::assertSame('/a.png', $data['avatarUrl']);
        self::assertSame($stats, $data['stats']);
        self::assertTrue($data['isSubscribed']);
        // The card is privacy-conscious: no location.
        self::assertArrayNotHasKey('location', $data);
    }

    public function testSubscriptionShape(): void
    {
        $followed = (new User())->setFullName('Followed');
        $subscription = (new Subscription())->setSubscriber(new User())->setSubscribedTo($followed);

        $data = $this->mapper()->subscription($subscription);

        self::assertSame('Followed', $data['user']['fullName']);
        self::assertArrayHasKey('id', $data);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['createdAt']));
    }

    public function testSubscriptionFeedGroupsUserAndDiscoverShapedBooks(): void
    {
        $followed = (new User())->setFullName('Followed');
        $book = (new Book())->setOwner($followed)->setTitle('T')->setAuthor('A');

        $data = $this->mapper()->subscriptionFeed($followed, [$book]);

        self::assertSame('Followed', $data['user']['fullName']);
        self::assertCount(1, $data['books']);
        // Discover shape carries the owner so the feed reuses DiscoverBookCard.
        self::assertSame('Followed', $data['books'][0]['owner']['fullName']);
        // No pending requests passed → not flagged as requested.
        self::assertFalse($data['books'][0]['requested']);
    }

    public function testSubscriptionFeedFlagsAlreadyRequestedBooks(): void
    {
        $followed = (new User())->setFullName('Followed');
        $book = (new Book())->setOwner($followed)->setTitle('T')->setAuthor('A');

        // Book id is null here, so use a lookup keyed on null to match getId().
        $data = $this->mapper()->subscriptionFeed($followed, [$book], [$book->getId() => true]);

        self::assertTrue($data['books'][0]['requested']);
    }

    public function testProfileHidesLocationWhenNotVisible(): void
    {
        $user = (new User())->setFullName('Jane')->setLocation('Lviv');
        $stats = ['totalBooks' => 0, 'shared' => 0, 'loaned' => 0];

        $data = $this->mapper()->profile($user, $stats, false, showLocation: false);

        self::assertNull($data['location']);
        self::assertSame('Jane', $data['fullName']);
    }

    public function testSettingsShape(): void
    {
        $settings = (new \App\Entity\UserSettings())
            ->setAllowRequests(false)
            ->setNotifyActivity(true);

        $data = $this->mapper()->settings($settings);

        self::assertFalse($data['allowRequests']);
        self::assertTrue($data['showLocation']);
        self::assertTrue($data['notifyActivity']);
        self::assertFalse($data['notifyNewsletter']);
    }

    public function testMeShapeIncludesEmailAndPrivacy(): void
    {
        $user = (new User())->setEmail('me@example.test')->setFullName('Me')->setIsPrivate(true);
        $stats = ['totalBooks' => 0, 'shared' => 0, 'loaned' => 0];

        $data = $this->mapper()->me($user, $stats);

        self::assertSame('me@example.test', $data['email']);
        self::assertTrue($data['isPrivate']);
        self::assertSame($stats, $data['stats']);
    }

    public function testCategoryShape(): void
    {
        $category = (new Category())->setName('Poetry')->setColorHex('#dae4ed');

        self::assertSame(
            ['id' => null, 'name' => 'Poetry', 'colorHex' => '#dae4ed'],
            $this->mapper()->category($category),
        );
    }

    public function testActivityShapeWithBookAndUser(): void
    {
        $actor = (new User())->setFullName('Actor');
        $targetUser = (new User())->setFullName('Target');
        $book = (new Book())->setOwner($actor)->setTitle('B')->setAuthor('A');

        $item = (new ActivityItem())
            ->setActor($actor)
            ->setActionType(ActivityType::Borrowed)
            ->setTargetBook($book)
            ->setTargetUser($targetUser);

        $data = $this->mapper()->activity($item);

        self::assertSame('borrowed', $data['actionType']);
        self::assertSame('Actor', $data['actor']['fullName']);
        self::assertSame('B', $data['targetBook']['title']);
        self::assertSame('Target', $data['targetUser']['fullName']);
    }

    public function testPaginatedWrapsItemsAndComputesMetadata(): void
    {
        // 25 total rows, showing page 2 of a 10-per-page slice.
        $page = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];

        $data = $this->mapper()->paginated(
            $page,
            25,
            new Pagination(page: 2, perPage: 10),
            static fn (string $s) => ['value' => $s],
        );

        self::assertCount(10, $data['items']);
        self::assertSame(['value' => 'a'], $data['items'][0]);
        self::assertSame(2, $data['pagination']['page']);
        self::assertSame(10, $data['pagination']['perPage']);
        self::assertSame(25, $data['pagination']['total']);
        self::assertSame(3, $data['pagination']['totalPages']);
        // offset 10 + 10 shown = 20 < 25 → more remain.
        self::assertTrue($data['pagination']['hasMore']);
    }

    public function testPaginatedLastPageHasNoMore(): void
    {
        // Page 3 of 25 rows @ 10/page holds the final 5.
        $data = $this->mapper()->paginated(
            ['x', 'y', 'z', 'p', 'q'],
            25,
            new Pagination(page: 3, perPage: 10),
            static fn (string $s) => $s,
        );

        self::assertFalse($data['pagination']['hasMore']);
        self::assertSame(3, $data['pagination']['totalPages']);
    }

    public function testPaginatedEmptyResult(): void
    {
        $data = $this->mapper()->paginated([], 0, new Pagination(page: 1, perPage: 20), static fn ($x) => $x);

        self::assertSame([], $data['items']);
        self::assertSame(0, $data['pagination']['total']);
        self::assertSame(0, $data['pagination']['totalPages']);
        self::assertFalse($data['pagination']['hasMore']);
    }

    public function testActivityShapeWithNullTargets(): void
    {
        $item = (new ActivityItem())
            ->setActor((new User())->setFullName('Actor'))
            ->setActionType(ActivityType::Followed);

        $data = $this->mapper()->activity($item);

        self::assertNull($data['targetBook']);
        self::assertNull($data['targetUser']);
    }
}
