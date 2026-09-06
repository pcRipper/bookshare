<?php

namespace App\Tests\Dto;

use App\Dto\IntercomLetterInput;
use App\Dto\IntercomLetterItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The caps matter more here than in any other DTO: this is the only payload that
 * fans out into mail to every opted-in member, so an unbounded one would be an
 * unbounded send.
 */
class IntercomLetterInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /** @return string[] */
    private function violations(IntercomLetterInput $input): array
    {
        $paths = [];
        foreach ($this->validator->validate($input) as $v) {
            $paths[] = $v->getPropertyPath();
        }

        return $paths;
    }

    private function item(string $version = '1.28.0', array $lines = ['Ratings arrived.']): IntercomLetterItem
    {
        $item = new IntercomLetterItem();
        $item->version = $version;
        $item->lines = $lines;

        return $item;
    }

    private function letter(?array $items = null): IntercomLetterInput
    {
        $letter = new IntercomLetterInput();
        $letter->subject = 'What is new in FolioShare';
        $letter->items = $items ?? [$this->item()];

        return $letter;
    }

    public function testAComposedLetterIsValid(): void
    {
        self::assertCount(0, $this->validator->validate($this->letter()));
    }

    public function testASubjectIsRequired(): void
    {
        $letter = $this->letter();
        $letter->subject = '  ';

        self::assertContains('subject', $this->violations($letter));
    }

    /** A letter with nothing selected would mail everyone an empty page. */
    public function testAnEmptySelectionIsRejected(): void
    {
        $letter = $this->letter([]);

        self::assertContains('items', $this->violations($letter));
    }

    public function testTooManyVersionsAreRejected(): void
    {
        $letter = $this->letter(array_fill(0, 21, $this->item()));

        self::assertContains('items', $this->violations($letter));
    }

    public function testAVersionWithNoLinesIsRejected(): void
    {
        $letter = $this->letter([$this->item(lines: [])]);

        self::assertNotEmpty($this->violations($letter));
    }

    public function testTooManyLinesOnOneVersionAreRejected(): void
    {
        $letter = $this->letter([$this->item(lines: array_fill(0, 11, 'A line.'))]);

        self::assertNotEmpty($this->violations($letter));
    }

    /** The whole point of the composer is brevity; 200 chars is the ceiling. */
    public function testAnOverlongLineIsRejected(): void
    {
        $letter = $this->letter([$this->item(lines: [str_repeat('a', 201)])]);

        self::assertNotEmpty($this->violations($letter));
    }

    public function testAnOverlongIntroIsRejected(): void
    {
        $letter = $this->letter();
        $letter->intro = str_repeat('a', 301);

        self::assertContains('intro', $this->violations($letter));
    }
}
