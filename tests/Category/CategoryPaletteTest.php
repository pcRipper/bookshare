<?php

namespace App\Tests\Category;

use App\Category\CategoryPalette;
use PHPUnit\Framework\TestCase;

class CategoryPaletteTest extends TestCase
{
    public function testColorsReturnsTheCuratedConstant(): void
    {
        self::assertSame(CategoryPalette::COLORS, CategoryPalette::colors());
        self::assertNotEmpty(CategoryPalette::colors());
    }

    public function testDefaultIsTheFirstColour(): void
    {
        self::assertSame(CategoryPalette::COLORS[0], CategoryPalette::default());
    }

    public function testEveryColourIsAHexValue(): void
    {
        foreach (CategoryPalette::colors() as $color) {
            self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $color);
        }
    }

    public function testColoursAreUnique(): void
    {
        $colors = CategoryPalette::colors();
        self::assertSameSize($colors, array_unique($colors));
    }
}
