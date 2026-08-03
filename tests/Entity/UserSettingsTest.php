<?php

namespace App\Tests\Entity;

use App\Entity\UserSettings;
use PHPUnit\Framework\TestCase;

class UserSettingsTest extends TestCase
{
    public function testDefaultsMatchTheDocumentedOptIns(): void
    {
        $settings = new UserSettings();

        self::assertTrue($settings->allowsRequests());
        self::assertTrue($settings->showsLocation());
        self::assertTrue($settings->notifiesBorrowRequests());
        self::assertTrue($settings->notifiesRequestUpdates());
        self::assertFalse($settings->notifiesActivity());
        self::assertFalse($settings->notifiesNewsletter());
        self::assertSame('en', $settings->getLocale());
    }

    public function testLocaleAcceptsAShippedLanguage(): void
    {
        self::assertSame('uk', (new UserSettings())->setLocale('uk')->getLocale());
    }

    public function testARegionalTagIsStoredAsItsBaseLanguage(): void
    {
        self::assertSame('de', (new UserSettings())->setLocale('de-AT')->getLocale());
    }

    public function testAnUnsupportedOrNullLocaleFallsBackToTheDefaultRatherThanPersisting(): void
    {
        // The DB column is never allowed to hold a locale we can't render.
        self::assertSame('en', (new UserSettings())->setLocale('pl')->getLocale());
        self::assertSame('en', (new UserSettings())->setLocale(null)->getLocale());
    }
}
