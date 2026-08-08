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
        // No language chosen yet — distinct from having chosen English, so the
        // SPA keeps whatever the browser negotiated.
        self::assertNull($settings->getLocale());
    }

    public function testLocaleAcceptsAShippedLanguage(): void
    {
        self::assertSame('uk', (new UserSettings())->setLocale('uk')->getLocale());
    }

    public function testARegionalTagIsStoredAsItsBaseLanguage(): void
    {
        self::assertSame('de', (new UserSettings())->setLocale('de-AT')->getLocale());
    }

    public function testAnUnsupportedOrNullLocaleIsDiscardedRatherThanPersisted(): void
    {
        // The column is never allowed to hold a locale we can't render, and a
        // rejected one reads back as "no choice", not as the default.
        self::assertNull((new UserSettings())->setLocale('pl')->getLocale());
        self::assertNull((new UserSettings())->setLocale(null)->getLocale());
    }

    public function testAChosenLocaleCanBeClearedBackToNoChoice(): void
    {
        $settings = (new UserSettings())->setLocale('uk');

        self::assertNull($settings->setLocale(null)->getLocale());
    }
}
