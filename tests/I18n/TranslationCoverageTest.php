<?php

namespace App\Tests\I18n;

use App\I18n\LocaleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Guards the "the English sentence is its own translation id" convention.
 *
 * Every user-facing message in src/ is a literal English sentence that
 * App\Api\ApiError hands to the translator, so a missing catalog entry is
 * invisible in en and silently renders English everywhere else — exactly how
 * the withdraw-rejection message shipped untranslated. This walks the real
 * source with PHP's lexer (a regex mis-pairs quotes on apostrophes in
 * comments) and fails when an id has no entry in every non-default locale.
 */
class TranslationCoverageTest extends TestCase
{
    /** Console output is CLI-only — never negotiated, never localized. */
    private const SKIPPED_DIRS = ['Command'];

    /**
     * Internal failures that mean "a developer wired this wrong", not
     * "the reader did something we have to explain". They surface as
     * InvalidArgumentException, never as an API message.
     *
     * The dump one is the same shape with a different cause: DumpService throws
     * it when a file it just wrote cannot be read back, and
     * AdminDumpRestController catches everything from that call and answers with
     * its own translated sentence — the detail goes to the log, where an
     * operator can act on it, rather than to a reader who cannot.
     */
    private const NOT_USER_FACING = [
        'Unknown template source "%s".',
        'Unknown loan signal reason "%s".',
        'Unknown collection signal reason "%s".',
        'Unknown loan mail reason "%s".',
        'The dump was written but could not be read back.',
    ];

    /** @return array<string, list<string>> id => ["File.php:line", …] */
    private function messageIds(): array
    {
        $src = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        $ids = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($src) + 1));
            foreach (self::SKIPPED_DIRS as $skipped) {
                if (str_starts_with($relative, $skipped . '/')) {
                    continue 2;
                }
            }

            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $value = $this->unquote($token[1]);
                // An English sentence: capitalised, more than one word, punctuated.
                if (!preg_match('/^[A-Z].*\s.*[.!?]$/u', $value)) {
                    continue;
                }
                if (\in_array($value, self::NOT_USER_FACING, true)) {
                    continue;
                }
                $ids[$value][] = $relative . ':' . $token[2];
            }
        }

        return $ids;
    }

    private function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
            : stripcslashes($body);
    }

    /** @return array<string, true> */
    private function catalog(string $locale): array
    {
        $keys = [];
        foreach (['messages', 'validators'] as $domain) {
            $path = \dirname(__DIR__, 2) . "/translations/{$domain}.{$locale}.yaml";
            foreach (file($path, \FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match("/^'((?:[^']|'')+)':/", $line, $m)) {
                    $keys[str_replace("''", "'", $m[1])] = true;
                }
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function translatedLocales(): array
    {
        return array_values(array_diff(LocaleCatalog::codes(), [LocaleCatalog::DEFAULT]));
    }

    public function testEveryUserFacingMessageIsTranslatedInEveryLocale(): void
    {
        $ids = $this->messageIds();
        self::assertNotEmpty($ids, 'Found no message ids — the source scan is broken, not the catalogs.');

        $missing = [];
        foreach ($this->translatedLocales() as $locale) {
            $catalog = $this->catalog($locale);
            foreach ($ids as $id => $sites) {
                if (!isset($catalog[$id])) {
                    $missing[] = sprintf('[%s] "%s" (%s)', $locale, $id, implode(', ', array_unique($sites)));
                }
            }
        }

        self::assertSame([], $missing, "Untranslated message ids:\n" . implode("\n", $missing));
    }

    public function testTheLocaleCatalogsAgreeOnTheirKeys(): void
    {
        $locales = $this->translatedLocales();
        $reference = array_keys($this->catalog($locales[0]));
        sort($reference);

        foreach (\array_slice($locales, 1) as $locale) {
            $keys = array_keys($this->catalog($locale));
            sort($keys);
            self::assertSame($reference, $keys, "{$locale} does not carry the same keys as {$locales[0]}.");
        }
    }

    public function testNoCatalogEntryIsDead(): void
    {
        // Compared against *every* literal, not just sentence-shaped ones, so a
        // key like 'Missing authorization code' (no full stop) still counts.
        $src = \dirname(__DIR__, 2) . '/src';
        $haystack = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src)) as $file) {
            if ($file->getExtension() === 'php') {
                $haystack .= file_get_contents($file->getPathname());
            }
        }

        $dead = [];
        foreach (array_keys($this->catalog($this->translatedLocales()[0])) as $key) {
            // Match either quoting style, with PHP's escaping applied.
            $single = str_replace(["\\", "'"], ["\\\\", "\\'"], $key);
            if (!str_contains($haystack, "'" . $single . "'") && !str_contains($haystack, '"' . $key . '"')) {
                $dead[] = $key;
            }
        }

        self::assertSame([], $dead, "Catalog keys matching no message in src/:\n" . implode("\n", $dead));
    }
}
