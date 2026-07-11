<?php

namespace App\Tests\Dto;

use App\Dto\CollectionInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CollectionInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /** @return string[] property paths that produced a violation */
    private function violations(CollectionInput $input): array
    {
        $paths = [];
        foreach ($this->validator->validate($input) as $v) {
            $paths[] = $v->getPropertyPath();
        }

        return $paths;
    }

    public function testValidInputHasNoViolations(): void
    {
        $input = new CollectionInput();
        $input->name = 'The Expanse';
        $input->description = 'Space opera series.';
        $input->coverUrl = 'https://example.test/cover.jpg';
        $input->bookIds = [1, 2, 3];

        self::assertCount(0, $this->validator->validate($input));
    }

    public function testBlankNameIsRejected(): void
    {
        $input = new CollectionInput();
        $input->bookIds = [1, 2];

        self::assertContains('name', $this->violations($input));
    }

    public function testFewerThanTwoBooksIsRejected(): void
    {
        $input = new CollectionInput();
        $input->name = 'Solo';
        $input->bookIds = [1];

        self::assertContains('bookIds', $this->violations($input));
    }

    public function testOverlongFieldsAreRejected(): void
    {
        $input = new CollectionInput();
        $input->name = str_repeat('a', 256);
        $input->description = str_repeat('b', 501);
        $input->bookIds = [1, 2];

        $paths = $this->violations($input);
        self::assertContains('name', $paths);
        self::assertContains('description', $paths);
    }

    public function testInvalidCoverUrlIsRejected(): void
    {
        $input = new CollectionInput();
        $input->name = 'C';
        $input->coverUrl = 'not-a-url';
        $input->bookIds = [1, 2];

        self::assertContains('coverUrl', $this->violations($input));
    }

    public function testOverlongCoverUrlIsRejected(): void
    {
        $input = new CollectionInput();
        $input->name = 'C';
        // Valid URL shape but past the 500-char cap.
        $input->coverUrl = 'https://example.test/' . str_repeat('a', 500) . '.jpg';
        $input->bookIds = [1, 2];

        self::assertContains('coverUrl', $this->violations($input));
    }

    public function testNullCoverUrlIsAccepted(): void
    {
        $input = new CollectionInput();
        $input->name = 'C';
        $input->coverUrl = null;
        $input->bookIds = [1, 2];

        self::assertNotContains('coverUrl', $this->violations($input));
    }

    public function testBookIdsMustBePositiveIntegers(): void
    {
        $input = new CollectionInput();
        $input->name = 'C';
        $input->bookIds = [-1, 0];

        self::assertNotEmpty($this->validator->validate($input));
    }
}
