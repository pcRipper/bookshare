<?php

namespace App\Service\Admin;

use App\Entity\Book;
use App\Entity\User;
use App\Enum\BookStatus;
use App\Repository\ActivityItemRepository;
use App\Repository\BookRepository;
use App\Repository\CollectionRepository;
use App\Repository\CollectionRequestRepository;
use App\Repository\LibraryRequestRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a member's account by anonymizing the row and destroying everything
 * hanging off it.
 *
 * **Why the row survives.** book.owner_id, book_collection.owner_id,
 * library_request.requester_id and collection_request.requester_id all reference
 * `user` with no ON DELETE rule, so a hard DELETE either fails or — had we added
 * cascades — would take the *other* party's loan history with it. A member who
 * lent thirty books would erase thirty borrowers' records of having returned
 * them. Anonymizing keeps the counterpart's history intact and truthful while
 * leaving nothing that identifies the person who left.
 *
 * **What survives deliberately.** The `_audit` tables: an operator needs to be
 * able to answer "what happened to this account" afterwards, and audit rows are
 * the only place that answer lives. Loan history belonging to *other* members
 * likewise stays, now attributed to an unnamed account.
 *
 * **One accepted loss.** A book of theirs that is out on loan is deleted along
 * with the rest of their shelf, so the borrower's record of that specific loan
 * goes too. The alternative — orphaning books whose owner no longer exists —
 * puts an unownable row in every borrower's Sharing tab with no way to resolve
 * it. AdminUserService refuses the deletion while such a loan is live, so this
 * only bites for loans already settled.
 *
 * Persist-never-flush, per the project convention: the controller owns the
 * transaction boundary and flushes exactly once.
 */
class UserPurger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BookRepository $books,
        private readonly CollectionRepository $collections,
        private readonly LibraryRequestRepository $requests,
        private readonly CollectionRequestRepository $collectionRequests,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ActivityItemRepository $activity,
    ) {}

    /**
     * Idempotent: purging an already-deleted account is a no-op rather than a
     * failure, so a double-clicked button or a retried request can't produce a
     * second, differently-anonymized pass over the same row.
     */
    public function purge(User $user): void
    {
        if ($user->isDeleted()) {
            return;
        }

        $this->releaseBorrowedBooks($user);
        $this->removeLoanHistory($user);
        $this->removeLibrary($user);
        $this->removeSocialGraph($user);
        $this->anonymize($user);
    }

    /**
     * Books they were holding on loan go home. Without this the lender is left
     * with a book stuck in `lent`, held by an account that no longer answers for
     * it — a loan whose return the owner can never confirm, because the other
     * half of the state machine is gone.
     */
    private function releaseBorrowedBooks(User $user): void
    {
        /** @var Book $book */
        foreach ($this->books->findBy(['currentHolder' => $user]) as $book) {
            if ($book->getOwner() === $user) {
                continue; // Their own shelf — removeLibrary() deletes it outright.
            }

            $book->setCurrentHolder($book->getOwner())->setStatus(BookStatus::Own);
        }
    }

    /**
     * Their side of the lending machine. Requests they *received* are attached to
     * their books and go with them in removeLibrary(); these are the ones they
     * filed, which reference books that will outlive them.
     *
     * Collection parents go first, so the ON DELETE CASCADE on
     * library_request.parent_request_id has already taken the children by the
     * time the per-book pass looks for them.
     */
    private function removeLoanHistory(User $user): void
    {
        foreach ($this->collectionRequests->findBy(['requester' => $user]) as $request) {
            $this->em->remove($request);
        }

        foreach ($this->requests->findBy(['requester' => $user]) as $request) {
            $this->em->remove($request);
        }
    }

    /**
     * Both shelves and every collection. `wished: null` is what pulls the wish
     * list in alongside the owned books — the same argument the CSV export makes
     * for taking both.
     *
     * Removing a book cascades its library_request rows at the database level;
     * removing a collection cascades its collection_request history the same way.
     */
    private function removeLibrary(User $user): void
    {
        foreach ($this->collections->findBy(['owner' => $user]) as $collection) {
            $this->em->remove($collection);
        }

        foreach ($this->books->findByOwner($user, null, null) as $book) {
            $this->em->remove($book);
        }
    }

    /**
     * Follows in both directions, the activity feed, and their settings row.
     *
     * ActivityItem.target_user is ON DELETE SET NULL, which never fires here
     * because the row survives — so somebody else's "followed X" entry has to be
     * unlinked by hand, or it would go on pointing at the anonymized account and
     * render as a link to it.
     */
    private function removeSocialGraph(User $user): void
    {
        foreach ($this->subscriptions->findBy(['subscriber' => $user]) as $edge) {
            $this->em->remove($edge);
        }

        foreach ($this->subscriptions->findBy(['subscribedTo' => $user]) as $edge) {
            $this->em->remove($edge);
        }

        foreach ($this->activity->findBy(['actor' => $user]) as $item) {
            $this->em->remove($item);
        }

        foreach ($this->activity->findBy(['targetUser' => $user]) as $item) {
            $item->setTargetUser(null);
        }

        if ($settings = $user->getSettings()) {
            $this->em->remove($settings);
        }
    }

    /**
     * Overwrites every identifying column.
     *
     * email and googleId are rewritten rather than blanked because both are
     * UNIQUE and both are lookup keys: leaving the real values would let
     * findOrCreateFromGoogle() resurrect the anonymized row on the next sign-in,
     * handing the returning member an account with none of their books and
     * somebody else's loan history attached. Rewritten, that same sign-in makes a
     * clean new account, which is what "deleted" ought to mean.
     *
     * The .invalid TLD is reserved by RFC 2606 precisely so that it can never
     * route: whatever else goes wrong, no mail is ever addressed to a deleted
     * member.
     */
    private function anonymize(User $user): void
    {
        $id = $user->getId();

        $user->setDeletedAt(new \DateTimeImmutable())
            ->setEmail(\sprintf('deleted-%d@deleted.invalid', $id))
            ->setGoogleId(\sprintf('deleted-%d', $id))
            ->setFullName('Deleted member')
            ->setBio(null)
            ->setLocation(null)
            ->setAvatarUrl(null)
            ->setAvatarSourceUrl(null)
            // Private and role-less: belt and braces behind the visibility
            // predicate, so a query that forgets VisibleUsers still cannot put
            // this row on a community surface or hand it an operator's grants.
            ->setIsPrivate(true)
            ->setRoles([]);
    }
}
