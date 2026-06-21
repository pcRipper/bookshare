<?php

namespace App\Tests\Dto;

use App\Dto\ProfileInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ProfileInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testAllNullIsValidBecauseEveryFieldIsOptional(): void
    {
        // A partial PATCH may send no editable fields at all.
        self::assertCount(0, $this->validator->validate(new ProfileInput()));
    }

    public function testTypicalEditIsValid(): void
    {
        $input = new ProfileInput();
        $input->fullName = 'Jane Doe';
        $input->bio = 'Reader and lender of books.';
        $input->location = 'Lviv';
        $input->avatarUrl = 'https://example.test/a.png';
        $input->isPrivate = true;

        self::assertCount(0, $this->validator->validate($input));
    }

    public function testOverlongFieldsAreRejected(): void
    {
        $input = new ProfileInput();
        $input->fullName = str_repeat('a', 256);
        $input->bio = str_repeat('b', 301);
        $input->location = str_repeat('c', 256);
        $input->avatarUrl = str_repeat('d', 501);

        $paths = [];
        foreach ($this->validator->validate($input) as $v) {
            $paths[] = $v->getPropertyPath();
        }

        self::assertContains('fullName', $paths);
        self::assertContains('bio', $paths);
        self::assertContains('location', $paths);
        self::assertContains('avatarUrl', $paths);
    }

    public function testBioAtThreeHundredCharsIsAccepted(): void
    {
        $input = new ProfileInput();
        $input->bio = str_repeat('x', 300);

        self::assertCount(0, $this->validator->validate($input));
    }
}
