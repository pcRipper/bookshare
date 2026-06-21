<?php

namespace App\Tests\Dto;

use App\Category\CategoryPalette;
use App\Dto\CategoryInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CategoryInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testValidInputHasNoViolations(): void
    {
        $input = new CategoryInput();
        $input->name = 'Mystery';
        $input->colorHex = CategoryPalette::default();

        self::assertCount(0, $this->validator->validate($input));
    }

    public function testBlankNameIsRejected(): void
    {
        $input = new CategoryInput();
        $input->name = '';
        $input->colorHex = CategoryPalette::default();

        self::assertGreaterThan(0, $this->validator->validate($input)->count());
    }

    public function testColourOutsideThePaletteIsRejected(): void
    {
        $input = new CategoryInput();
        $input->name = 'Mystery';
        $input->colorHex = '#123456'; // not in the curated palette

        $messages = [];
        foreach ($this->validator->validate($input) as $v) {
            $messages[] = $v->getMessage();
        }

        self::assertContains('Unsupported colour.', $messages);
    }

    public function testBlankColourIsRejected(): void
    {
        $input = new CategoryInput();
        $input->name = 'Mystery';
        $input->colorHex = '';

        self::assertGreaterThan(0, $this->validator->validate($input)->count());
    }

    public function testEveryPaletteColourIsAccepted(): void
    {
        foreach (CategoryPalette::colors() as $color) {
            $input = new CategoryInput();
            $input->name = 'Name';
            $input->colorHex = $color;

            self::assertCount(0, $this->validator->validate($input), "palette colour $color should validate");
        }
    }
}
