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

    public function create(User $owner, BookInput $input): Book
    {
        $book = (new Book())->setOwner($owner);
        $this->applyInput($book, $input);

        $this->em->persist($book);
        $this->activity->record($owner, ActivityType::AddedBook, targetBook: $book);

        return $book;
    }

    public function update(Book $book, BookInput $input): void
    {
        $this->applyInput($book, $input);
    }

    public function delete(Book $book): void
    {
        $this->em->remove($book);
    }

    private function applyInput(Book $book, BookInput $input): void
    {
        $book
            ->setTitle(trim($input->title))
            ->setAuthor(trim($input->author))
            ->setIsbn($input->isbn !== null && trim($input->isbn) !== '' ? trim($input->isbn) : null)
            ->setDescription($input->description !== null && trim($input->description) !== '' ? trim($input->description) : null)
            ->setCoverPath($input->coverPath !== null && trim($input->coverPath) !== '' ? trim($input->coverPath) : null)
            ->setStatus($input->status)
            ->setLanguage($input->language !== null && trim($input->language) !== '' ? trim($input->language) : null);

        $book->clearCategories();
        foreach ($this->categories->findByIds($input->categoryIds) as $category) {
            $book->addCategory($category);
        }
    }
}
