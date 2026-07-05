<?php

namespace App\Tests\Language;

use App\Language\LanguageGuesser;
use PHPUnit\Framework\TestCase;

class LanguageGuesserTest extends TestCase
{
    public function testNullAndBlankAreUndecidable(): void
    {
        self::assertNull(LanguageGuesser::guess(null));
        self::assertNull(LanguageGuesser::guess(''));
        self::assertNull(LanguageGuesser::guess('   '));
    }

    public function testLatinScriptIsLeftUnguessed(): void
    {
        // The Latin alphabet is shared by too many languages to call.
        self::assertNull(LanguageGuesser::guess('The Great Gatsby'));
        self::assertNull(LanguageGuesser::guess('Les Misérables'));
        self::assertNull(LanguageGuesser::guess('Die Verwandlung'));
    }

    public function testCyrillicDefaultsToUkrainian(): void
    {
        // No letters unique to either alphabet — Ukrainian is the default here.
        self::assertSame('uk', LanguageGuesser::guess('Тарас'));
    }

    public function testUkrainianUniqueLettersResolveToUkrainian(): void
    {
        // і, ї, є, ґ do not exist in the Russian alphabet.
        self::assertSame('uk', LanguageGuesser::guess('Кобзар: вибрані твори'));
        self::assertSame('uk', LanguageGuesser::guess('Їжак'));
    }

    public function testRussianUniqueLettersResolveToRussian(): void
    {
        // ы, э, ъ, ё do not exist in the Ukrainian alphabet.
        self::assertSame('ru', LanguageGuesser::guess('Мы')); // ы
        self::assertSame('ru', LanguageGuesser::guess('Объявление')); // ъ
        self::assertSame('ru', LanguageGuesser::guess('Это')); // э
    }

    public function testUkrainianWinsWhenBothMarkersAppear(): void
    {
        // A Ukrainian marker present alongside a Russian one still reads Ukrainian.
        self::assertSame('uk', LanguageGuesser::guess('їхав экран'));
    }

    public function testOtherScriptsAreNamed(): void
    {
        self::assertSame('ja', LanguageGuesser::guess('こころ'));           // Hiragana
        self::assertSame('ja', LanguageGuesser::guess('カタカナ'));         // Katakana
        self::assertSame('ko', LanguageGuesser::guess('채식주의자'));       // Hangul
        self::assertSame('zh', LanguageGuesser::guess('紅樓夢'));           // Han only
        self::assertSame('el', LanguageGuesser::guess('Οδύσσεια'));         // Greek
        self::assertSame('he', LanguageGuesser::guess('בראשית'));          // Hebrew
        self::assertSame('ar', LanguageGuesser::guess('ألف ليلة وليلة')); // Arabic
        self::assertSame('th', LanguageGuesser::guess('สี่แผ่นดิน'));      // Thai
        self::assertSame('hi', LanguageGuesser::guess('गोदान'));            // Devanagari
        self::assertSame('ka', LanguageGuesser::guess('ვეფხისტყაოსანი')); // Georgian
        self::assertSame('hy', LanguageGuesser::guess('Սասունցի Դավիթ'));  // Armenian
    }

    public function testKanaWinsOverSharedHan(): void
    {
        // Japanese uses Han ideographs too; the kana disambiguates it from Chinese.
        self::assertSame('ja', LanguageGuesser::guess('吾輩は猫である'));
    }
}
