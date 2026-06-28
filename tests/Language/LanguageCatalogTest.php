<?php

namespace App\Tests\Language;

use App\Language\LanguageCatalog;
use PHPUnit\Framework\TestCase;

class LanguageCatalogTest extends TestCase
{
    public function testCodesAreUniqueAndComprehensive(): void
    {
        $codes = LanguageCatalog::codes();

        self::assertGreaterThan(100, count($codes), 'Expected a broad language vocabulary.');
        self::assertSame(count($codes), count(array_unique($codes)), 'Codes must be unique.');
    }

    public function testIsValidRecognisesKnownAndRejectsUnknownCodes(): void
    {
        self::assertTrue(LanguageCatalog::isValid('en'));
        self::assertTrue(LanguageCatalog::isValid('uk'));
        self::assertFalse(LanguageCatalog::isValid('xx'));
        self::assertFalse(LanguageCatalog::isValid(''));
    }

    public function testNameResolvesCodesAndToleratesNullOrUnknown(): void
    {
        self::assertSame('English', LanguageCatalog::name('en'));
        self::assertNull(LanguageCatalog::name(null));
        self::assertNull(LanguageCatalog::name('xx'));
    }

    public function testAllReturnsCodeNameRowsSortedByName(): void
    {
        $rows = LanguageCatalog::all();

        self::assertCount(count(LanguageCatalog::codes()), $rows);
        self::assertArrayHasKey('code', $rows[0]);
        self::assertArrayHasKey('name', $rows[0]);

        $names = array_column($rows, 'name');
        $sorted = $names;
        usort($sorted, 'strcmp');
        self::assertSame($sorted, $names, 'Rows must be alphabetically sorted by name.');
    }
}
