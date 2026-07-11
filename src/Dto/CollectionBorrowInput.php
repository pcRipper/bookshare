<?php

namespace App\Dto;

use App\Service\CollectionRequestService;
use Symfony\Component\Validator\Constraints as Assert;

class CollectionBorrowInput
{
    /**
     * IDs of the collection's books the borrower selected. At least two must be
     * chosen; availability and membership are re-validated server-side.
     *
     * @var int[]
     */
    #[Assert\Count(min: CollectionRequestService::MIN_BOOKS, minMessage: 'Select at least {{ limit }} books to borrow.')]
    #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
    public array $bookIds = [];
}
