<?php

namespace App\Tests\Entity;

use App\Entity\Category;
use PHPUnit\Framework\TestCase;

class CategoryTest extends TestCase
{
    public function testSettersAreFluentAndStore(): void
    {
        $category = (new Category())
            ->setName('Science Fiction')
            ->setColorHex('#E8F0EA');

        self::assertNull($category->getId());
        self::assertSame('Science Fiction', $category->getName());
        self::assertSame('#E8F0EA', $category->getColorHex());
    }
}
