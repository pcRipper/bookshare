<?php

namespace App\Language;

/**
 * Best-effort guess of a book's language from its title, used only as a fallback
 * when an external template source supplies no language of its own (e.g.
 * bookfinder.com.ua never does, and Open Library's MARC code can be missing or
 * unmapped). Detection is purely script-based: it reliably names non-Latin
 * scripts (Cyrillic, Greek, Hebrew, Arabic, CJK, …) but leaves Latin-script text
 * unguessed — the Latin alphabet is shared by too many languages to call from
 * letters alone.
 *
 * Cyrillic resolves to Ukrainian by default: the platform targets the Ukrainian
 * market, so when a title can't be told apart from Russian we assume Ukrainian.
 * Only letters unique to Russian (absent from the Ukrainian alphabet) tip it to
 * Russian; letters unique to Ukrainian confirm Ukrainian.
 *
 * Every code returned is a member of {@see LanguageCatalog}, so a guessed value
 * always passes `BookInput`'s language validation when the template pre-fills the
 * manual create form.
 */
final class LanguageGuesser
{
    /** Resolve a book title to a catalogued ISO 639-1 code, or null if undecidable. */
    public static function guess(?string $text): ?string
    {
        $code = self::detect($text);

        return $code !== null && LanguageCatalog::isValid($code) ? $code : null;
    }

    private static function detect(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        // Cyrillic — Ukrainian by default (the market we target). Letters unique
        // to one alphabet tip the balance; anything else stays Ukrainian.
        if (preg_match('/\p{Cyrillic}/u', $text) === 1) {
            if (preg_match('/[іїєґ]/iu', $text) === 1) {
                return 'uk'; // letters absent from Russian
            }
            if (preg_match('/[ыэъё]/iu', $text) === 1) {
                return 'ru'; // letters absent from Ukrainian
            }

            return 'uk';
        }

        // Japanese kana / Korean hangul take precedence over the Han ideographs
        // all three scripts share.
        if (preg_match('/\p{Hiragana}|\p{Katakana}/u', $text) === 1) {
            return 'ja';
        }
        if (preg_match('/\p{Hangul}/u', $text) === 1) {
            return 'ko';
        }
        if (preg_match('/\p{Han}/u', $text) === 1) {
            return 'zh';
        }

        if (preg_match('/\p{Greek}/u', $text) === 1) {
            return 'el';
        }
        if (preg_match('/\p{Hebrew}/u', $text) === 1) {
            return 'he';
        }
        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'ar';
        }
        if (preg_match('/\p{Thai}/u', $text) === 1) {
            return 'th';
        }
        if (preg_match('/\p{Devanagari}/u', $text) === 1) {
            return 'hi';
        }
        if (preg_match('/\p{Georgian}/u', $text) === 1) {
            return 'ka';
        }
        if (preg_match('/\p{Armenian}/u', $text) === 1) {
            return 'hy';
        }

        return null;
    }
}
