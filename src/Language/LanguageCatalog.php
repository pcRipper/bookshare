<?php

namespace App\Language;

/**
 * The vocabulary of languages a book may be catalogued in: ISO 639-1 code =>
 * English name. This is the single source of truth — it backs both the
 * `BookInput` validation (a book's language must be a known code) and the
 * `GET /api/languages` endpoint the frontend dropdown consumes, so the list is
 * never duplicated client-side.
 */
final class LanguageCatalog
{
    /** @var array<string, string> ISO 639-1 code => English name */
    public const LANGUAGES = [
        'ab' => 'Abkhaz',
        'aa' => 'Afar',
        'af' => 'Afrikaans',
        'ak' => 'Akan',
        'sq' => 'Albanian',
        'am' => 'Amharic',
        'ar' => 'Arabic',
        'an' => 'Aragonese',
        'hy' => 'Armenian',
        'as' => 'Assamese',
        'ay' => 'Aymara',
        'az' => 'Azerbaijani',
        'bm' => 'Bambara',
        'ba' => 'Bashkir',
        'eu' => 'Basque',
        'be' => 'Belarusian',
        'bn' => 'Bengali',
        'bs' => 'Bosnian',
        'br' => 'Breton',
        'bg' => 'Bulgarian',
        'my' => 'Burmese',
        'ca' => 'Catalan',
        'ch' => 'Chamorro',
        'ce' => 'Chechen',
        'ny' => 'Chichewa',
        'zh' => 'Chinese',
        'cv' => 'Chuvash',
        'kw' => 'Cornish',
        'co' => 'Corsican',
        'hr' => 'Croatian',
        'cs' => 'Czech',
        'da' => 'Danish',
        'dv' => 'Divehi',
        'nl' => 'Dutch',
        'dz' => 'Dzongkha',
        'en' => 'English',
        'eo' => 'Esperanto',
        'et' => 'Estonian',
        'ee' => 'Ewe',
        'fo' => 'Faroese',
        'fj' => 'Fijian',
        'fi' => 'Finnish',
        'fr' => 'French',
        'fy' => 'Frisian',
        'gl' => 'Galician',
        'ka' => 'Georgian',
        'de' => 'German',
        'el' => 'Greek',
        'gn' => 'Guarani',
        'gu' => 'Gujarati',
        'ht' => 'Haitian Creole',
        'ha' => 'Hausa',
        'he' => 'Hebrew',
        'hi' => 'Hindi',
        'hu' => 'Hungarian',
        'is' => 'Icelandic',
        'ig' => 'Igbo',
        'id' => 'Indonesian',
        'ga' => 'Irish',
        'it' => 'Italian',
        'ja' => 'Japanese',
        'jv' => 'Javanese',
        'kl' => 'Kalaallisut',
        'kn' => 'Kannada',
        'kk' => 'Kazakh',
        'km' => 'Khmer',
        'rw' => 'Kinyarwanda',
        'ky' => 'Kyrgyz',
        'ko' => 'Korean',
        'ku' => 'Kurdish',
        'lo' => 'Lao',
        'la' => 'Latin',
        'lv' => 'Latvian',
        'lt' => 'Lithuanian',
        'lb' => 'Luxembourgish',
        'mk' => 'Macedonian',
        'mg' => 'Malagasy',
        'ms' => 'Malay',
        'ml' => 'Malayalam',
        'mt' => 'Maltese',
        'mi' => 'Maori',
        'mr' => 'Marathi',
        'mn' => 'Mongolian',
        'ne' => 'Nepali',
        'no' => 'Norwegian',
        'oc' => 'Occitan',
        'or' => 'Odia',
        'om' => 'Oromo',
        'ps' => 'Pashto',
        'fa' => 'Persian',
        'pl' => 'Polish',
        'pt' => 'Portuguese',
        'pa' => 'Punjabi',
        'qu' => 'Quechua',
        'ro' => 'Romanian',
        'rm' => 'Romansh',
        'ru' => 'Russian',
        'sm' => 'Samoan',
        'sg' => 'Sango',
        'sa' => 'Sanskrit',
        'gd' => 'Scottish Gaelic',
        'sr' => 'Serbian',
        'sn' => 'Shona',
        'sd' => 'Sindhi',
        'si' => 'Sinhala',
        'sk' => 'Slovak',
        'sl' => 'Slovenian',
        'so' => 'Somali',
        'st' => 'Southern Sotho',
        'es' => 'Spanish',
        'su' => 'Sundanese',
        'sw' => 'Swahili',
        'ss' => 'Swati',
        'sv' => 'Swedish',
        'tl' => 'Tagalog',
        'tg' => 'Tajik',
        'ta' => 'Tamil',
        'tt' => 'Tatar',
        'te' => 'Telugu',
        'th' => 'Thai',
        'bo' => 'Tibetan',
        'ti' => 'Tigrinya',
        'to' => 'Tongan',
        'ts' => 'Tsonga',
        'tn' => 'Tswana',
        'tr' => 'Turkish',
        'tk' => 'Turkmen',
        'uk' => 'Ukrainian',
        'ur' => 'Urdu',
        'ug' => 'Uyghur',
        'uz' => 'Uzbek',
        've' => 'Venda',
        'vi' => 'Vietnamese',
        'cy' => 'Welsh',
        'wo' => 'Wolof',
        'xh' => 'Xhosa',
        'yi' => 'Yiddish',
        'yo' => 'Yoruba',
        'zu' => 'Zulu',
    ];

    /**
     * Valid language codes — the allow-list `BookInput`'s Assert\Choice enforces.
     *
     * @return string[]
     */
    public static function codes(): array
    {
        return array_keys(self::LANGUAGES);
    }

    public static function isValid(string $code): bool
    {
        return isset(self::LANGUAGES[$code]);
    }

    /** Resolve a code to its English name; null for null/unknown codes. */
    public static function name(?string $code): ?string
    {
        return $code !== null ? (self::LANGUAGES[$code] ?? null) : null;
    }

    /**
     * The full vocabulary as `{code, name}` rows, sorted alphabetically by name —
     * the shape the frontend dropdown renders.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function all(): array
    {
        $rows = [];
        foreach (self::LANGUAGES as $code => $name) {
            $rows[] = ['code' => $code, 'name' => $name];
        }
        usort($rows, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }
}
