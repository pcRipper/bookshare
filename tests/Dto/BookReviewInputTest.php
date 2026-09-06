<?php

namespace App\Tests\Dto;

use App\Dto\BookReviewInput;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class BookReviewInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /** @return string[] property paths that produced a violation */
    private function violations(BookReviewInput $input): array
    {
        $paths = [];
        foreach ($this->validator->validate($input) as $v) {
            $paths[] = $v->getPropertyPath();
        }

        return $paths;
    }

    /** Clearing a review is sending nothing — it must not be an error. */
    public function testAnEmptyReviewIsValid(): void
    {
        self::assertCount(0, $this->validator->validate(new BookReviewInput()));
    }

    #[TestWith([1])]
    #[TestWith([3])]
    #[TestWith([5])]
    public function testRatingsOnTheScaleAreAccepted(int $stars): void
    {
        $input = new BookReviewInput();
        $input->rating = $stars;

        self::assertNotContains('rating', $this->violations($input));
    }

    /**
     * Rejected rather than clamped: a value off the scale means the client is
     * counting something else, and silently storing 5 for a 10 hides that.
     */
    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestWith([6])]
    #[TestWith([10])]
    public function testRatingsOffTheScaleAreRejected(int $stars): void
    {
        $input = new BookReviewInput();
        $input->rating = $stars;

        self::assertContains('rating', $this->violations($input));
    }

    public function testAReviewAtTheCapIsAccepted(): void
    {
        $input = new BookReviewInput();
        $input->review = str_repeat('a', 1000);

        self::assertNotContains('review', $this->violations($input));
    }

    public function testALongerReviewIsRejected(): void
    {
        $input = new BookReviewInput();
        $input->review = str_repeat('a', 1001);

        self::assertContains('review', $this->violations($input));
    }

    /** Words without stars, and stars without words, are both whole reviews. */
    public function testEitherHalfAloneIsValid(): void
    {
        $wordsOnly = new BookReviewInput();
        $wordsOnly->review = 'Kept me up all night.';
        self::assertCount(0, $this->validator->validate($wordsOnly));

        $starsOnly = new BookReviewInput();
        $starsOnly->rating = 4;
        self::assertCount(0, $this->validator->validate($starsOnly));
    }
}
