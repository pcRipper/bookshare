<?php

namespace App\Tests\Achievement;

use App\Achievement\AchievementCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The achievement vocabulary is duplicated across the two halves of the app —
 * the catalogue decides what a family *is*, the SPA decides what it looks like
 * and what it is called — so something has to fail when they drift. This reads
 * the frontend files directly, exactly as AnalyticsRoutesTest reads the router
 * and CategoryPaletteTest pins the palette.
 *
 * Only `en.json` is checked here: the other four catalogs are held to it by
 * key parity, which is a separate concern.
 */
class AchievementFrontendParityTest extends TestCase
{
    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /** @return string[] the keys of ACHIEVEMENT_ICONS in assets/src/utils/achievements.js */
    private function frontendIconKeys(): array
    {
        $path = $this->root() . '/assets/src/utils/achievements.js';
        self::assertFileExists($path);

        $source = file_get_contents($path);

        $start = strpos($source, 'ACHIEVEMENT_ICONS = {');
        self::assertNotFalse($start, 'ACHIEVEMENT_ICONS was renamed or removed.');
        $end = strpos($source, '}', $start);
        self::assertNotFalse($end);

        preg_match_all(
            '/^\s*([a-z_]+):\s*\'[a-z_]+\',/m',
            substr($source, $start, $end - $start),
            $matches,
        );

        return $matches[1];
    }

    /** @return array<string, mixed> the `achievements` namespace of the English catalog */
    private function englishCatalog(): array
    {
        $path = $this->root() . '/assets/src/i18n/locales/en.json';
        self::assertFileExists($path);

        $messages = json_decode(file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('achievements', $messages, 'The achievements i18n namespace is missing.');

        return $messages['achievements'];
    }

    public function testEveryFamilyHasAnIconAndNoIconIsOrphaned(): void
    {
        self::assertSame(
            AchievementCatalog::keys(),
            $this->frontendIconKeys(),
            'ACHIEVEMENT_ICONS must list exactly the catalogue keys, in catalogue order.',
        );
    }

    /**
     * Prose lives in the SPA, so a family the backend adds renders as a raw
     * key until it is named — visible, but only to whoever happens to look.
     */
    public function testEveryFamilyIsNamedAndDescribedInEnglish(): void
    {
        $items = $this->englishCatalog()['items'] ?? [];

        foreach (AchievementCatalog::keys() as $key) {
            self::assertArrayHasKey($key, $items, "achievements.items.{$key} is untranslated.");
            self::assertNotSame('', trim($items[$key]['name'] ?? ''), $key . ' has no name');
            self::assertNotSame('', trim($items[$key]['description'] ?? ''), $key . ' has no description');
        }

        self::assertSame(
            AchievementCatalog::keys(),
            array_keys($items),
            'achievements.items carries a family the catalogue does not define.',
        );
    }

    /** The chrome the shelf and the modal render around the families. */
    public function testTheNamespaceCarriesItsChromeKeys(): void
    {
        $chrome = $this->englishCatalog();

        foreach (['title', 'subtitleSelf', 'subtitleOther', 'viewAll', 'more', 'emptySelf', 'emptyOther', 'tierOf', 'progress', 'maxed', 'ladder'] as $key) {
            self::assertArrayHasKey($key, $chrome, "achievements.{$key} is missing.");
            self::assertIsString($chrome[$key]);
        }
    }

    /**
     * None of these is a counted message. Keeping them plural-free is why no
     * locale needed a plural branch — and Ukrainian would have needed three.
     */
    public function testNoAchievementMessageIsPluralised(): void
    {
        foreach (['en', 'de', 'es', 'fr', 'uk'] as $locale) {
            $path = $this->root() . "/assets/src/i18n/locales/{$locale}.json";
            $messages = json_decode(file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);

            array_walk_recursive(
                $messages['achievements'],
                static function (string $message, string $key) use ($locale): void {
                    self::assertStringNotContainsString('|', $message, "{$locale}: achievements.{$key} grew a plural branch.");
                },
            );
        }
    }
}
