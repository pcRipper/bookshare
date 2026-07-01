<?php

namespace App\Tests\Service;

use App\Dto\BookInput;
use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\BookStatus;
use App\Repository\CategoryRepository;
use App\Service\ActivityRecorder;
use App\Service\BookService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class BookServiceTest extends TestCase
{
    public function testCreatePersistsBookSetsOwnerAndRecordsActivity(): void
    {
        $owner = new User();
        $category = new Category();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(Book::class));

        $categories = $this->createMock(CategoryRepository::class);
        $categories->expects($this->once())->method('findByIds')->with([7])->willReturn([$category]);

        $recorded = null;
        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->once())->method('record')->willReturnCallback(
            function (User $actor, ActivityType $type, ?Book $targetBook = null) use (&$recorded) {
                $recorded = ['actor' => $actor, 'type' => $type, 'book' => $targetBook];

                return new ActivityItem();
            },
        );

        $service = new BookService($em, $categories, $activity);

        $input = new BookInput();
        $input->title = '  Dune  ';
        $input->author = '  Frank Herbert ';
        $input->isbn = ' 978-0441013593 ';
        $input->coverPath = '   ';
        $input->description = '  A desert epic.  ';
        $input->status = BookStatus::Lent;
        $input->categoryIds = [7];

        $book = $service->create($owner, $input);

        self::assertSame($owner, $book->getOwner());
        // Strings are trimmed; an all-whitespace optional field becomes null.
        self::assertSame('Dune', $book->getTitle());
        self::assertSame('Frank Herbert', $book->getAuthor());
        self::assertSame('978-0441013593', $book->getIsbn());
        self::assertSame('A desert epic.', $book->getDescription());
        self::assertNull($book->getCoverPath());
        self::assertSame(BookStatus::Lent, $book->getStatus());
        self::assertTrue($book->getCategories()->contains($category));

        self::assertSame($owner, $recorded['actor']);
        self::assertSame(ActivityType::AddedBook, $recorded['type']);
        self::assertSame($book, $recorded['book']);
    }

    public function testCreateNormalisesBlankOptionalFieldsToNull(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $categories = $this->createStub(CategoryRepository::class);
        $categories->method('findByIds')->willReturn([]);
        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->once())->method('record')->willReturn(new ActivityItem());

        $service = new BookService($em, $categories, $activity);

        $input = new BookInput();
        $input->title = 'Title';
        $input->author = 'Author';
        $input->isbn = '';
        $input->coverPath = null;
        $input->description = '   ';

        $book = $service->create(new User(), $input);

        self::assertNull($book->getIsbn());
        self::assertNull($book->getCoverPath());
        self::assertNull($book->getDescription());
    }

    public function testUpdateAppliesInputWithoutPersistingOrRecording(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('remove');

        $categories = $this->createStub(CategoryRepository::class);
        $categories->method('findByIds')->willReturn([]);

        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->never())->method('record');

        $service = new BookService($em, $categories, $activity);

        $book = (new Book())->setOwner(new User())->setTitle('Old')->addCategory(new Category());

        $input = new BookInput();
        $input->title = 'New Title';
        $input->author = 'New Author';
        $input->categoryIds = [];

        $service->update($book, $input);

        self::assertSame('New Title', $book->getTitle());
        self::assertSame('New Author', $book->getAuthor());
        // Categories are rebuilt from the input — the previous one is cleared.
        self::assertCount(0, $book->getCategories());
    }

    public function testDeleteRemovesTheBook(): void
    {
        $book = (new Book())->setOwner(new User());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($book);

        $categories = $this->createMock(CategoryRepository::class);
        $categories->expects($this->never())->method('findByIds');

        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->never())->method('record');

        $service = new BookService($em, $categories, $activity);
        $service->delete($book);
    }
}
