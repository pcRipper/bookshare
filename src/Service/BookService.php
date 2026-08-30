<?php

namespace App\Service;

use App\Dto\BookInput;
use App\Entity\Book;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Business logic for the book catalog. Methods persist but never flush —
 * the controller flushes once per request.
 */
class BookService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categories,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * $coverSourceUrl is the remote URL the cover was localized from, when it was —
     * the controller resolves it; CSV import (which never localizes) passes none.
     */
    public function create(User $owner, BookInput $input, ?string $coverSourceUrl = null): Book
    {
        $book = (new Book())->setOwner($owner);
        $this->applyInput($book, $input, $coverSourceUrl);

        $this->em->persist($book);
        // A wish-list book is something the owner wants, not something the
        // community gained — announcing it to followers as "added a book" would
        // put a book nobody can borrow in everyone's feed.
        if (!$book->isWished()) {
            $this->activity->record($owner, ActivityType::AddedBook, targetBook: $book);
        }

        return $book;
    }

    public function update(Book $book, BookInput $input, ?string $coverSourceUrl = null): void
    {
        $this->applyInput($book, $input, $coverSourceUrl);
    }

    /**
     * The owner finally got the book: it leaves the wish list and lands on the
     * shelf, keeping everything already catalogued about it (cover, categories,
     * language). Its own status is left alone — a wish-list book carries the
     * `own` default, so it is immediately shareable — and the "added a book"
     * activity that create() withheld is recorded now, since this is the moment
     * the community actually gained it.
     */
    public function acquire(Book $book): void
    {
        if (!$book->isWished()) {
            return;
        }

        $book->acquire();
        $this->activity->record($book->getOwner(), ActivityType::AddedBook, targetBook: $book);
    }

    public function delete(Book $book): void
    {
        $this->em->remove($book);
    }

    private function applyInput(Book $book, BookInput $input, ?string $coverSourceUrl = null): void
    {
        $coverPath = $input->coverPath !== null && trim($input->coverPath) !== '' ? trim($input->coverPath) : null;

        // Only re-point the recorded source when the cover itself moves. The edit
        // modal round-trips the localized /uploads path, so an unrelated edit
        // arrives with an unchanged path and no source — it must not erase the link.
        if ($coverPath !== $book->getCoverPath()) {
            $book->setCoverSourceUrl($coverSourceUrl);
        }

        $book
            ->setTitle(trim($input->title))
            ->setAuthor(trim($input->author))
            ->setIsbn($input->isbn !== null && trim($input->isbn) !== '' ? trim($input->isbn) : null)
            ->setDescription($input->description !== null && trim($input->description) !== '' ? trim($input->description) : null)
            ->setCoverPath($coverPath)
            ->setStatus($input->status)
            ->setLanguage($input->language !== null && trim($input->language) !== '' ? trim($input->language) : null)
            ->setIsRead($input->isRead)
            // setWish keeps the pair coherent: a priority is stored only for a
            // wanted book, and a wanted book always ends up with a level.
            ->setWish($input->isWished, $input->wishPriority);

        $book->clearCategories();
        foreach ($this->categories->findByIds($input->categoryIds) as $category) {
            $book->addCategory($category);
        }
    }
}
