<?php

namespace App\I18n;

/**
 * The UI languages the application ships: locale code => the language's own
 * name. It backs the `UserSettings.locale` validation and the `Accept-Language`
 * negotiation in `LocaleSubscriber`.
 *
 * Unlike `LanguageCatalog` (which is served to the frontend over the API), this
 * list is duplicated client-side on purpose: the SPA has to bundle a message
 * catalog per locale anyway, so `assets/src/i18n/index.js`'s `SUPPORTED` — and
 * the files under `assets/src/i18n/locales/` — are the other half of this
 * allow-list. Adding a language means touching both sides.
 */
final class LocaleCatalog
{
    public const DEFAULT = 'en';

    /** @var array<string, string> locale code => endonym */
    public const LOCALES = [
        'en' => 'English',
        'de' => 'Deutsch',
        'es' => 'Español',
        'fr' => 'Français',
        'uk' => 'Українська',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LOCALES);
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && isset(self::LOCALES[$code]);
    }

    /**
     * Resolves a locale tag (`uk`, `uk-UA`, `de-AT`) to a supported code, so a
     * regional preference still lands on its language. Null when unsupported.
     */
    public static function negotiate(?string $tag): ?string
    {
        if ($tag === null || $tag === '') {
            return null;
        }

        $code = strtolower(explode('-', str_replace('_', '-', $tag))[0]);

        return self::isSupported($code) ? $code : null;
    }
}
