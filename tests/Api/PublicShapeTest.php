<?php

namespace App\Tests\Api;

use App\Api\ResponseMapper;
use App\Entity\Book;
use App\Entity\BookCollection;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\BookStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Pins the exact key set the signed-out share page publishes.
 *
 * These assert a **whitelist** (`assertSame` on `array_keys`), not the absence
 * of today's sensitive fields, so a field added to `book()` or `collection()`
 * years from now fails here instead of quietly appearing on a page anyone with
 * a link can read.
 *
 * Nothing crashes if a public shape accidentally reuses the member shape:
 * AuthorizationChecker substitutes a NullToken and the voters return false, so
 * `canEdit: false` renders silently. That is exactly why this is a test and not
 * a code review note.
 */
class PublicShapeTest extends TestCase
{
    private const PUBLIC_BOOK_KEYS = [
        'id', 'title', 'author', 'description', 'isbn', 'coverPath', 'status',
        'language', 'languageName', 'isRead', 'createdAt', 'categories',
    ];

    private const PUBLIC_COLLECTION_KEYS = [
        'id', 'name', 'description', 'coverUrl', 'bookCount', 'availableCount',
        'createdAt', 'books',
    ];

    private const PUBLIC_PROFILE_KEYS = ['id', 'fullName', 'avatarUrl', 'bio'];

    /** Fields that must never reach a signed-out reader, wherever they nest. */
    private const FORBIDDEN = ['currentHolder', 'owner', 'canEdit', 'isHome', 'requested', 'email', 'location'];

    private function mapper(): ResponseMapper
    {
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);

        return new ResponseMapper($auth);
    }

    private function book(User $owner, string $title = 'Dune'): Book
    {
        $book = (new Book())->setOwner($owner)->setTitle($title)->setAuthor('Herbert')
            ->setStatus(BookStatus::Own)->setLanguage('en');
        $book->addCategory((new Category())->setName('Sci-Fi')->setColorHex('#E8F0EA'));

        return $book;
    }

    public function testPublicBookPublishesExactlyTheWhitelistedKeys(): void
    {
        $data = $this->mapper()->publicBook($this->book((new User())->setFullName('Jane')));

        self::assertSame(self::PUBLIC_BOOK_KEYS, array_keys($data));
    }

    public function testPublicBookStillCarriesWhatThePageNeeds(): void
    {
        $data = $this->mapper()->publicBook($this->book((new User())->setFullName('Jane')));

        self::assertSame('Dune', $data['title']);
        self::assertSame('Herbert', $data['author']);
        self::assertSame('own', $data['status']);
        self::assertSame('English', $data['languageName']);
        self::assertSame('Sci-Fi', $data['categories'][0]['name']);
    }

    public function testPublicProfilePublishesExactlyTheWhitelistedKeys(): void
    {
        $user = (new User())->setFullName('Jane')->setBio('Reader')->setLocation('Kyiv');

        self::assertSame(self::PUBLIC_PROFILE_KEYS, array_keys($this->mapper()->publicProfile($user)));
    }

    public function testPublicCollectionPublishesExactlyTheWhitelistedKeys(): void
    {
        $owner = (new User())->setFullName('Jane');
        $collection = (new BookCollection())->setOwner($owner)->setName('Dune saga');
        $collection->addBook($this->book($owner));

        self::assertSame(self::PUBLIC_COLLECTION_KEYS, array_keys($this->mapper()->publicCollection($collection)));
    }

    /**
     * The leak that hides one level down: collection() maps its members through
     * book(), so a public collection built on it would republish the borrower's
     * identity inside `books[]` while the top level looked clean.
     */
    public function testBooksNestedInAPublicCollectionUseThePublicShape(): void
    {
        $owner = (new User())->setFullName('Jane');
        $borrower = (new User())->setFullName('Sam');

        $lent = $this->book($owner, 'Messiah')->setStatus(BookStatus::Lent);
        $lent->setCurrentHolder($borrower);

        $collection = (new BookCollection())->setOwner($owner)->setName('Dune saga');
        $collection->addBook($this->book($owner))->addBook($lent);

        $data = $this->mapper()->publicCollection($collection);

        self::assertCount(2, $data['books']);
        foreach ($data['books'] as $book) {
            self::assertSame(self::PUBLIC_BOOK_KEYS, array_keys($book));
        }
    }

    /** Belt-and-braces: no forbidden key anywhere in the serialized tree. */
    public function testNoForbiddenFieldSurvivesAnywhereInThePublicTree(): void
    {
        $owner = (new User())->setFullName('Jane')->setLocation('Kyiv');
        $borrower = (new User())->setFullName('Sam');

        $lent = $this->book($owner, 'Messiah')->setStatus(BookStatus::Lent);
        $lent->setCurrentHolder($borrower);

        $collection = (new BookCollection())->setOwner($owner)->setName('Dune saga');
        $collection->addBook($lent);

        $json = json_encode([
            $this->mapper()->publicProfile($owner),
            $this->mapper()->publicBook($lent),
            $this->mapper()->publicCollection($collection),
        ], \JSON_THROW_ON_ERROR);

        foreach (self::FORBIDDEN as $field) {
            self::assertStringNotContainsString('"' . $field . '"', $json, "{$field} leaked into a public shape.");
        }
        // The borrower is the point of the currentHolder rule — name them too.
        self::assertStringNotContainsString('Sam', $json);
    }
}
