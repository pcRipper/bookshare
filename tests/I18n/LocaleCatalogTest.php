<?php

namespace App\Tests\I18n;

use App\I18n\LocaleCatalog;
use PHPUnit\Framework\TestCase;

class LocaleCatalogTest extends TestCase
{
    public function testShipsTheFiveDocumentedUiLanguages(): void
    {
        self::assertSame(['en', 'de', 'es', 'fr', 'uk'], LocaleCatalog::codes());
    }

    public function testDefaultIsSupported(): void
    {
        self::assertTrue(LocaleCatalog::isSupported(LocaleCatalog::DEFAULT));
    }

    public function testIsSupportedRejectsUnknownAndNull(): void
    {
        self::assertTrue(LocaleCatalog::isSupported('uk'));
        self::assertFalse(LocaleCatalog::isSupported('pl'));
        self::assertFalse(LocaleCatalog::isSupported(''));
        self::assertFalse(LocaleCatalog::isSupported(null));
    }

    public function testNegotiateResolvesRegionalTagsToTheirLanguage(): void
    {
        self::assertSame('uk', LocaleCatalog::negotiate('uk-UA'));
        self::assertSame('de', LocaleCatalog::negotiate('de_AT'));
        self::assertSame('fr', LocaleCatalog::negotiate('FR'));
    }

    public function testNegotiateReturnsNullForUnsupportedOrEmptyTags(): void
    {
        self::assertNull(LocaleCatalog::negotiate('pl-PL'));
        self::assertNull(LocaleCatalog::negotiate(''));
        self::assertNull(LocaleCatalog::negotiate(null));
    }

    public function testEveryLocaleHasAnEndonym(): void
    {
        foreach (LocaleCatalog::LOCALES as $code => $label) {
            self::assertNotSame('', $label, "Locale {$code} needs a label.");
        }
    }
}
