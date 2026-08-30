<?php

namespace App\Tests\Service;

use App\Dto\BookInput;
use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\BookStatus;
use App\Enum\WishPriority;
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
        $input->isRead = true;
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
        self::assertTrue($book->isRead());
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

    public function testCreatingAWishListBookRecordsNoActivity(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $categories = $this->createStub(CategoryRepository::class);
        $categories->method('findByIds')->willReturn([]);
        // A book nobody can borrow has no business in everyone's feed.
        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->never())->method('record');

        $input = $this->wishInput(WishPriority::Urgent);

        $book = (new BookService($em, $categories, $activity))->create(new User(), $input);

        self::assertTrue($book->isWished());
        self::assertSame(WishPriority::Urgent, $book->getWishPriority());
    }

    public function testAPriorityOnANonWishedBookIsDiscarded(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $categories = $this->createStub(CategoryRepository::class);
        $categories->method('findByIds')->willReturn([]);
        $activity = $this->createStub(ActivityRecorder::class);

        $input = $this->wishInput(WishPriority::Urgent);
        $input->isWished = false;

        $book = (new BookService($em, $categories, $activity))->create(new User(), $input);

        self::assertFalse($book->isWished());
        self::assertNull($book->getWishPriority());
    }

    public function testAcquiringMovesTheBookToTheShelfAndAnnouncesItThen(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $categories = $this->createStub(CategoryRepository::class);
        $categories->method('findByIds')->willReturn([]);
        // create() withheld the "added a book" event; acquiring is when the
        // community actually gained it, so it fires here instead.
        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->once())->method('record')->willReturn(new ActivityItem());

        $service = new BookService($em, $categories, $activity);
        $book = $service->create(new User(), $this->wishInput());

        $service->acquire($book);

        self::assertFalse($book->isWished());
        self::assertNull($book->getWishPriority());
    }

    public function testAcquiringABookAlreadyOnTheShelfIsANoOp(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $categories = $this->createStub(CategoryRepository::class);
        $activity = $this->createMock(ActivityRecorder::class);
        $activity->expects($this->never())->method('record');

        $book = (new Book())->setOwner(new User());

        (new BookService($em, $categories, $activity))->acquire($book);

        self::assertFalse($book->isWished());
    }

    private function wishInput(?WishPriority $priority = null): BookInput
    {
        $input = new BookInput();
        $input->title = 'Dune';
        $input->author = 'Frank Herbert';
        $input->isWished = true;
        $input->wishPriority = $priority;

        return $input;
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
        $input->isRead = true;
        $input->categoryIds = [];

        $service->update($book, $input);

        self::assertSame('New Title', $book->getTitle());
        self::assertSame('New Author', $book->getAuthor());
        self::assertTrue($book->isRead());
        // Categories are rebuilt from the input — the previous one is cleared.
        self::assertCount(0, $book->getCategories());
    }

    public function testCreateRecordsTheCoverSourceUrl(): void
    {
        $service = $this->service();

        $input = $this->coverInput('/uploads/covers/a1b2.jpg');
        $book = $service->create(new User(), $input, 'https://covers.example/8225261-L.jpg');

        self::assertSame('/uploads/covers/a1b2.jpg', $book->getCoverPath());
        self::assertSame('https://covers.example/8225261-L.jpg', $book->getCoverSourceUrl());
    }

    /** The edit modal round-trips the localized path, so an unrelated edit must keep the link. */
    public function testUpdateKeepsTheSourceWhenTheCoverPathIsUnchanged(): void
    {
        $book = (new Book())->setOwner(new User())
            ->setCoverPath('/uploads/covers/a1b2.jpg')
            ->setCoverSourceUrl('https://covers.example/8225261-L.jpg');

        $this->service()->update($book, $this->coverInput('/uploads/covers/a1b2.jpg'));

        self::assertSame('https://covers.example/8225261-L.jpg', $book->getCoverSourceUrl());
    }

    public function testUpdateRepointsTheSourceWhenTheCoverChanges(): void
    {
        $book = (new Book())->setOwner(new User())
            ->setCoverPath('/uploads/covers/a1b2.jpg')
            ->setCoverSourceUrl('https://covers.example/old.jpg');

        $service = $this->service();

        // A new cover that localized: path and source move together.
        $service->update($book, $this->coverInput('/uploads/covers/c3d4.jpg'), 'https://covers.example/new.jpg');
        self::assertSame('https://covers.example/new.jpg', $book->getCoverSourceUrl());

        // A cover that didn't localize (or was cleared): no source to record.
        $service->update($book, $this->coverInput('https://covers.example/hotlink.jpg'));
        self::assertNull($book->getCoverSourceUrl());
    }

    private function service(): BookService
    {
        $categories = $this->createStub(CategoryRepository::class);
        $categories->method('findByIds')->willReturn([]);

        $activity = $this->createStub(ActivityRecorder::class);
        $activity->method('record')->willReturn(new ActivityItem());

        return new BookService($this->createStub(EntityManagerInterface::class), $categories, $activity);
    }

    private function coverInput(?string $coverPath): BookInput
    {
        $input = new BookInput();
        $input->title = 'Dune';
        $input->author = 'Herbert';
        $input->coverPath = $coverPath;

        return $input;
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
