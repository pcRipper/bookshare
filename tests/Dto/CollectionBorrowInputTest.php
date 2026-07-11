<?php

namespace App\Tests\Dto;

use App\Dto\CollectionBorrowInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CollectionBorrowInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /** @return string[] property paths that produced a violation */
    private function violations(CollectionBorrowInput $input): array
    {
        $paths = [];
        foreach ($this->validator->validate($input) as $v) {
            $paths[] = $v->getPropertyPath();
        }

        return $paths;
    }

    public function testValidInputHasNoViolations(): void
    {
        $input = new CollectionBorrowInput();
        $input->collectionId = 7;
        $input->bookIds = [1, 2];

        self::assertCount(0, $this->validator->validate($input));
    }

    public function testFewerThanTwoBooksIsRejected(): void
    {
        $input = new CollectionBorrowInput();
        $input->collectionId = 7;
        $input->bookIds = [1];

        self::assertContains('bookIds', $this->violations($input));
    }

    public function testMissingCollectionIdIsRejected(): void
    {
        $input = new CollectionBorrowInput();
        $input->bookIds = [1, 2];

        self::assertContains('collectionId', $this->violations($input));
    }
}
