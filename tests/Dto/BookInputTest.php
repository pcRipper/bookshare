<?php

namespace App\Tests\Dto;

use App\Dto\BookInput;
use App\Enum\BookStatus;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class BookInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /** @return string[] property paths that produced a violation */
    private function violations(BookInput $input): array
    {
        $paths = [];
        foreach ($this->validator->validate($input) as $v) {
            $paths[] = $v->getPropertyPath();
        }

        return $paths;
    }

    public function testValidInputHasNoViolations(): void
    {
        $input = new BookInput();
        $input->title = 'Dune';
        $input->author = 'Frank Herbert';
        $input->isbn = '978-0441013593';
        $input->categoryIds = [1, 2, 3];

        self::assertCount(0, $this->validator->validate($input));
    }

    public function testBlankTitleAndAuthorAreRejected(): void
    {
        $input = new BookInput(); // title/author default to ''

        $paths = $this->violations($input);
        self::assertContains('title', $paths);
        self::assertContains('author', $paths);
    }

    public function testOverlongFieldsAreRejected(): void
    {
        $input = new BookInput();
        $input->title = str_repeat('a', 256);
        $input->author = str_repeat('b', 256);
        $input->isbn = str_repeat('1', 33);
        $input->coverPath = str_repeat('c', 501);

        $paths = $this->violations($input);
        self::assertContains('title', $paths);
        self::assertContains('author', $paths);
        self::assertContains('isbn', $paths);
        self::assertContains('coverPath', $paths);
    }

    public function testCategoryIdsMustBePositiveIntegers(): void
    {
        $input = new BookInput();
        $input->title = 'T';
        $input->author = 'A';
        $input->categoryIds = [-1, 0];

        self::assertNotEmpty($this->validator->validate($input));
    }

    public function testCategoryIdsRejectsNonIntegerElements(): void
    {
        $input = new BookInput();
        $input->title = 'T';
        $input->author = 'A';
        $input->categoryIds = ['not-an-int'];

        self::assertNotEmpty($this->validator->validate($input));
    }

    public function testNullLanguageIsAccepted(): void
    {
        $input = new BookInput();
        $input->title = 'T';
        $input->author = 'A';
        $input->language = null;

        self::assertNotContains('language', $this->violations($input));
    }

    public function testKnownLanguageCodeIsAccepted(): void
    {
        $input = new BookInput();
        $input->title = 'T';
        $input->author = 'A';
        $input->language = 'en';

        self::assertNotContains('language', $this->violations($input));
    }

    public function testUnknownLanguageCodeIsRejected(): void
    {
        $input = new BookInput();
        $input->title = 'T';
        $input->author = 'A';
        $input->language = 'xx';

        self::assertContains('language', $this->violations($input));
    }

    public function testCurrentlyReadingStatusIsAccepted(): void
    {
        $input = new BookInput();
        $input->title = 'T';
        $input->author = 'A';
        $input->status = BookStatus::CurrentlyReading;

        self::assertNotContains('status', $this->violations($input));
    }

    public function testLentStatusCannotBeSetDirectly(): void
    {
        $input = new BookInput();
        $input->title = 'T';
        $input->author = 'A';
        $input->status = BookStatus::Lent;

        self::assertContains('status', $this->violations($input));
    }

    public function testIsReadDefaultsToFalse(): void
    {
        self::assertFalse((new BookInput())->isRead);
    }

    public function testIsReadTrueIsAccepted(): void
    {
        $input = new BookInput();
        $input->title = 'T';
        $input->author = 'A';
        $input->isRead = true;

        self::assertCount(0, $this->validator->validate($input));
    }

    public function testNullRatingIsValid(): void
    {
        $input = $this->validBook();
        $input->rating = null;

        self::assertNotContains('rating', $this->violations($input));
    }

    #[TestWith([1])]
    #[TestWith([3])]
    #[TestWith([5])]
    public function testRatingsOnTheScaleAreAccepted(int $stars): void
    {
        $input = $this->validBook();
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
        $input = $this->validBook();
        $input->rating = $stars;

        self::assertContains('rating', $this->violations($input));
    }

    private function validBook(): BookInput
    {
        $input = new BookInput();
        $input->title = 'Dune';
        $input->author = 'Frank Herbert';

        return $input;
    }
}
