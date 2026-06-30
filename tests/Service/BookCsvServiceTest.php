<?php

namespace App\Tests\Service;

use App\Entity\ActivityItem;
use App\Entity\Book;
use App\Entity\Category;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\BookRepository;
use App\Repository\CategoryRepository;
use App\Service\ActivityRecorder;
use App\Service\BookCsvService;
use App\Service\BookService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class BookCsvServiceTest extends TestCase
{
    private function service(
        ?EntityManagerInterface $em = null,
        ?BookRepository $bookRepo = null,
        ?CategoryRepository $categories = null,
    ): BookCsvService {
        $em ??= $this->createStub(EntityManagerInterface::class);
        $bookRepo ??= $this->createStub(BookRepository::class);
        if ($categories === null) {
            $categories = $this->createStub(CategoryRepository::class);
            $categories->method('findByNames')->willReturn([]);
            $categories->method('findByIds')->willReturn([]);
        }

        $recorder = $this->createStub(ActivityRecorder::class);
        $recorder->method('record')->willReturn(new ActivityItem());

        $books = new BookService($em, $categories, $recorder);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        return new BookCsvService($books, $bookRepo, $categories, $em, $validator);
    }

    /** Stamp an id onto an entity (DB normally does this) so it survives validation. */
    private function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);

        return $entity;
    }

    public function testExportProducesHeaderAndRows(): void
    {
        $owner = new User();
        $book = (new Book())->setOwner($owner)->setTitle('Dune')->setAuthor('Herbert')
            ->setIsbn('978-0441013593')->setCoverPath('/covers/dune.jpg')->setLanguage('en');
        $book->addCategory((new Category())->setName('Sci-Fi')->setColorHex('#E8F0EA'));

        $csv = $this->service()->export([$book]);

        $lines = preg_split('/\r\n|\n/', trim($csv));
        self::assertSame('title,author,isbn,cover,language,status,categories', $lines[0]);
        self::assertStringContainsString('Dune', $lines[1]);
        self::assertStringContainsString('Herbert', $lines[1]);
        self::assertStringContainsString('/covers/dune.jpg', $lines[1]);
        self::assertStringContainsString('en', $lines[1]);
        self::assertStringContainsString('Sci-Fi', $lines[1]);
    }

    public function testImportSetsCoverFromCoverColumn(): void
    {
        $created = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (Book $b) use (&$created) { $created[] = $b; });

        $csv = "title,author,cover\nDune,Herbert,/covers/dune.jpg\n";

        $summary = $this->service($em)->import(new User(), $csv, replace: false, abortOnError: false);

        self::assertSame(1, $summary['imported']);
        self::assertCount(1, $created);
        self::assertSame('/covers/dune.jpg', $created[0]->getCoverPath());
    }

    public function testAppendSkipsDuplicateOfExistingBook(): void
    {
        $owner = new User();
        $existing = (new Book())->setOwner($owner)->setTitle('Dune')->setAuthor('Herbert');

        $bookRepo = $this->createStub(BookRepository::class);
        $bookRepo->method('findByOwner')->willReturn([$existing]);

        $em = $this->createMock(EntityManagerInterface::class);
        // Only the genuinely-new row reaches persist; the duplicate is skipped.
        $em->expects($this->once())->method('persist');
        $em->expects($this->never())->method('remove');

        // Case/whitespace-insensitive: "  dune ,  herbert" still matches "Dune,Herbert".
        $csv = "title,author\n  dune ,  herbert\n1984,Orwell\n";

        $summary = $this->service($em, $bookRepo)->import($owner, $csv, replace: false, abortOnError: false);

        self::assertSame(1, $summary['imported']);
        self::assertSame(1, $summary['skipped']);
        self::assertSame(2, $summary['errors'][0]['line']); // the duplicate row
        self::assertStringContainsString('Duplicate', $summary['errors'][0]['errors'][0]);
    }

    public function testAppendSkipsDuplicateRowsWithinTheSameFile(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // Three identical rows → one persist; the two repeats are skipped.
        $em->expects($this->once())->method('persist');

        $csv = "title,author\nDune,Herbert\nDune,Herbert\nDune,Herbert\n";

        $summary = $this->service($em)->import(new User(), $csv, replace: false, abortOnError: false);

        self::assertSame(1, $summary['imported']);
        self::assertSame(2, $summary['skipped']);
    }

    public function testDuplicatesDoNotTriggerAbort(): void
    {
        $owner = new User();
        $existing = (new Book())->setOwner($owner)->setTitle('Dune')->setAuthor('Herbert');

        $bookRepo = $this->createStub(BookRepository::class);
        $bookRepo->method('findByOwner')->willReturn([$existing]);

        // A duplicate is redundant, not malformed, so abortOnError must not abort.
        $csv = "title,author\nDune,Herbert\n1984,Orwell\n";

        $summary = $this->service(null, $bookRepo)->import($owner, $csv, replace: false, abortOnError: true);

        self::assertFalse($summary['aborted']);
        self::assertSame(1, $summary['imported']);
        self::assertSame(1, $summary['skipped']);
    }

    public function testImportAppendsValidRowsAndSkipsInvalid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // Two valid rows → two persists; the invalid row never reaches persist.
        $em->expects($this->exactly(2))->method('persist');
        $em->expects($this->never())->method('remove');

        $csv = "title,author,language\n"
            . "Dune,Herbert,en\n"
            . ",NoTitle,en\n"           // missing title → invalid
            . "1984,Orwell,en\n";

        $summary = $this->service($em)->import(new User(), $csv, replace: false, abortOnError: false);

        self::assertSame(2, $summary['imported']);
        self::assertSame(1, $summary['skipped']);
        self::assertFalse($summary['aborted']);
        self::assertSame(3, $summary['errors'][0]['line']); // header=1, Dune=2, bad row=3
    }

    public function testAbortOnErrorImportsNothingWhenARowIsInvalid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('remove');

        $csv = "title,author\nDune,Herbert\n,Bad\n";

        $summary = $this->service($em)->import(new User(), $csv, replace: false, abortOnError: true);

        self::assertTrue($summary['aborted']);
        self::assertSame(0, $summary['imported']);
        self::assertNotEmpty($summary['errors']);
    }

    public function testReplaceRemovesOnlyHomeBooks(): void
    {
        $owner = new User();
        $homeBook = (new Book())->setOwner($owner)->setTitle('Home')->setAuthor('A');
        $lentBook = (new Book())->setOwner($owner)->setTitle('Lent')->setAuthor('B');
        $lentBook->setCurrentHolder(new User()); // out on loan — must be kept

        $bookRepo = $this->createStub(BookRepository::class);
        $bookRepo->method('findByOwner')->willReturn([$homeBook, $lentBook]);

        $removed = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (Book $b) use (&$removed) { $removed[] = $b; });

        $csv = "title,author\nNew Book,Author\n";

        $summary = $this->service($em, $bookRepo)->import($owner, $csv, replace: true, abortOnError: false);

        self::assertSame([$homeBook], $removed, 'Only the home book is removed; the lent one is kept.');
        self::assertSame(1, $summary['imported']);
    }

    public function testInvalidStatusIsRejected(): void
    {
        $csv = "title,author,status\nDune,Herbert,lent\n";

        $summary = $this->service()->import(new User(), $csv, replace: false, abortOnError: false);

        self::assertSame(0, $summary['imported']);
        self::assertSame(1, $summary['skipped']);
    }

    public function testCurrentlyReadingStatusIsImportable(): void
    {
        $created = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (Book $b) use (&$created) { $created[] = $b; });

        $csv = "title,author,status\nDune,Herbert,currently_reading\n";

        $summary = $this->service($em)->import(new User(), $csv, replace: false, abortOnError: false);

        self::assertSame(1, $summary['imported']);
        self::assertSame(0, $summary['skipped']);
        self::assertSame(BookStatus::CurrentlyReading, $created[0]->getStatus());
    }

    public function testMissingRequiredColumnIsFatal(): void
    {
        $csv = "title,language\nDune,en\n"; // no author column

        $summary = $this->service()->import(new User(), $csv, replace: false, abortOnError: false);

        self::assertTrue($summary['aborted']);
        self::assertSame(0, $summary['imported']);
        self::assertStringContainsString('author', $summary['errors'][0]['errors'][0]);
    }

    public function testCategoryNamesAreResolvedAgainstExistingVocabulary(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $existing = $this->withId((new Category())->setName('Fiction')->setColorHex('#E8F0EA'), 5);
        $categories = $this->createMock(CategoryRepository::class);
        // The import looks up the row's category names against existing ones…
        $categories->expects($this->once())->method('findByNames')
            ->with(['Fiction', 'Unknown'])->willReturn([$existing]);
        // …then BookService resolves the matched ids back to entities.
        $categories->method('findByIds')->willReturn([$existing]);

        $csv = "title,author,categories\nDune,Herbert,Fiction; Unknown\n";

        $summary = $this->service($em, null, $categories)->import(new User(), $csv, replace: false, abortOnError: false);

        self::assertSame(1, $summary['imported']);
    }
}
