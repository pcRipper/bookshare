<?php

namespace App\Tests\Service;

use App\Dto\CollectionInput;
use App\Entity\Book;
use App\Entity\BookCollection;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
use App\Service\CollectionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers collection CRUD: create gates on ≥2 *available* owned books, update keeps
 * the ≥2 rule but deliberately does NOT re-check availability (members may be on
 * loan), and delete simply removes (the active-loan guard lives in CollectionVoter).
 * The owner's books are resolved through a stubbed BookRepository.
 */
class CollectionServiceTest extends TestCase
{
    /* ───────────────────────── create ───────────────────────── */

    public function testCreatePersistsCollectionAndAppliesInput(): void
    {
        $owner = new User();
        $books = [$this->book($owner, BookStatus::Own), $this->book($owner, BookStatus::Own)];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(BookCollection::class));

        $input = $this->input('The Expanse', 'Space opera.', 'https://ex.test/c.jpg');
        $collection = $this->service($em, $books)->create($owner, $input);

        self::assertSame('The Expanse', $collection->getName());
        self::assertSame('Space opera.', $collection->getDescription());
        self::assertSame('https://ex.test/c.jpg', $collection->getCoverUrl());
        self::assertSame($owner, $collection->getOwner());
        self::assertCount(2, $collection->getBooks());
    }

    public function testCreateRejectsFewerThanTwoResolvedBooks(): void
    {
        $owner = new User();
        // Only one owned book resolves (e.g. a foreign id was dropped by the repo).
        $books = [$this->book($owner, BookStatus::Own)];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('at least');
        $this->service(null, $books)->create($owner, $this->input('C'));
    }

    public function testCreateRejectsWhenFewerThanTwoBooksAreAvailable(): void
    {
        $owner = new User();
        // Two resolve, but one is on loan → fewer than two available to create with.
        $books = [$this->book($owner, BookStatus::Own), $this->book($owner, BookStatus::Lent)];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('available');
        $this->service(null, $books)->create($owner, $this->input('C'));
    }

    public function testCreateNormalizesBlankOptionalFieldsToNullAndTrimsName(): void
    {
        $owner = new User();
        $books = [$this->book($owner, BookStatus::Own), $this->book($owner, BookStatus::Own)];

        $collection = $this->service(null, $books)->create($owner, $this->input('  Trimmed  ', '   ', '   '));

        self::assertSame('Trimmed', $collection->getName());
        self::assertNull($collection->getDescription());
        self::assertNull($collection->getCoverUrl());
    }

    /* ───────────────────────── update ───────────────────────── */

    public function testUpdateAllowsMembersThatAreOnLoan(): void
    {
        $owner = new User();
        // One member is on loan — update must NOT re-check availability (unlike create).
        $books = [$this->book($owner, BookStatus::Own), $this->book($owner, BookStatus::Lent)];
        $collection = (new BookCollection())->setOwner($owner)->setName('Old');

        $this->service(null, $books)->update($collection, $this->input('New'), $owner);

        self::assertSame('New', $collection->getName());
        self::assertCount(2, $collection->getBooks());
    }

    public function testUpdateRejectsFewerThanTwoResolvedBooks(): void
    {
        $owner = new User();
        $books = [$this->book($owner, BookStatus::Own)];
        $collection = (new BookCollection())->setOwner($owner)->setName('Old');

        $this->expectException(\DomainException::class);
        $this->service(null, $books)->update($collection, $this->input('New'), $owner);
    }

    public function testUpdateReplacesMembership(): void
    {
        $owner = new User();
        $old = $this->book($owner, BookStatus::Own);
        $collection = (new BookCollection())->setOwner($owner)->setName('C')->addBook($old);

        $fresh = [$this->book($owner, BookStatus::Own), $this->book($owner, BookStatus::Own)];
        $this->service(null, $fresh)->update($collection, $this->input('C'), $owner);

        self::assertCount(2, $collection->getBooks());
        self::assertFalse($collection->getBooks()->contains($old));
    }

    /* ───────────────────────── delete ───────────────────────── */

    public function testDeleteRemovesTheCollection(): void
    {
        $collection = (new BookCollection())->setOwner(new User())->setName('C');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($collection);

        $this->service($em)->delete($collection);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /** @param Book[] $resolvedBooks what BookRepository::findByIdsForOwner returns */
    private function service(?EntityManagerInterface $em = null, array $resolvedBooks = []): CollectionService
    {
        $em ??= $this->createStub(EntityManagerInterface::class);
        $books = $this->createStub(BookRepository::class);
        $books->method('findByIdsForOwner')->willReturn($resolvedBooks);

        return new CollectionService($em, $books);
    }

    private function book(User $owner, BookStatus $status): Book
    {
        return (new Book())->setOwner($owner)->setStatus($status);
    }

    private function input(string $name, ?string $description = null, ?string $coverUrl = null): CollectionInput
    {
        $input = new CollectionInput();
        $input->name = $name;
        $input->description = $description;
        $input->coverUrl = $coverUrl;
        $input->bookIds = [1, 2];

        return $input;
    }
}
