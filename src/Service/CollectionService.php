<?php

namespace App\Service;

use App\Dto\CollectionInput;
use App\Entity\BookCollection;
use App\Entity\User;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CRUD for book collections. Methods persist but never flush — the controller
 * flushes once per request. Business-rule violations throw \DomainException
 * (controller maps to 409).
 */
class CollectionService
{
    /** A collection must group at least this many books. */
    public const MIN_BOOKS = 2;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookRepository $books,
    ) {}

    public function create(User $owner, CollectionInput $input): BookCollection
    {
        $collection = (new BookCollection())->setOwner($owner);
        $this->applyInput($collection, $input, $owner);

        $this->em->persist($collection);

        return $collection;
    }

    public function update(BookCollection $collection, CollectionInput $input, User $owner): void
    {
        $this->applyInput($collection, $input, $owner);
    }

    /**
     * Deletes a collection. The active-loan guard lives in {@see \App\Security\Voter\CollectionVoter}
     * (checked by the controller before this runs), mirroring how BookVoter guards
     * book deletion; the FK cascades any borrow-request history away.
     */
    public function delete(BookCollection $collection): void
    {
        $this->em->remove($collection);
    }

    private function applyInput(BookCollection $collection, CollectionInput $input, User $owner): void
    {
        $collection
            ->setName(trim($input->name))
            ->setDescription($input->description !== null && trim($input->description) !== '' ? trim($input->description) : null)
            ->setCoverUrl($input->coverUrl !== null && trim($input->coverUrl) !== '' ? trim($input->coverUrl) : null);

        // Only the owner's own books can be grouped; foreign/unknown ids (including
        // books merely borrowed-in, which the owner doesn't own) drop out here.
        // Membership accepts books of any status — a lent/unavailable/reading book
        // can sit in a collection; it just won't be borrowable until it's `own`
        // again (the borrow-side availability gate lives in CollectionRequestService).
        $resolved = $this->books->findByIdsForOwner($input->bookIds, $owner);

        if (\count($resolved) < self::MIN_BOOKS) {
            throw new \DomainException('A collection needs at least ' . self::MIN_BOOKS . ' of your books.');
        }

        $collection->clearBooks();
        foreach ($resolved as $book) {
            $collection->addBook($book);
        }
    }
}
