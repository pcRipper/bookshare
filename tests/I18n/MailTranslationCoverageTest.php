<?php

namespace App\Tests\I18n;

use App\I18n\LocaleCatalog;
use App\Mail\MailType;
use PHPUnit\Framework\TestCase;

/**
 * TranslationCoverageTest's counterpart for the `mails` domain.
 *
 * The stakes are higher here than in the API catalogs. A missing message key
 * renders one English sentence inside an otherwise translated screen, which
 * somebody notices; a missing mail key renders an English mail to a reader who
 * chose German, in a channel nobody on the team ever looks at. And unlike the
 * API strings, these ids live in Twig, so no PHP lexer sweeps them up.
 */
class MailTranslationCoverageTest extends TestCase
{
    private const DOMAIN = 'mails';

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * Every id the mails actually ask the translator for: the `|trans(..., 'mails')`
     * calls in the templates, plus the subjects, which live in the enum.
     *
     * @return array<string, list<string>> id => where it came from
     */
    private function usedIds(): array
    {
        $ids = [];

        foreach (glob(self::root().'/templates/emails/*.twig') ?: [] as $path) {
            $body = file_get_contents($path);
            // Matches `'An English sentence'|trans(` — the one shape used in
            // these templates. A ternary picking between two ids therefore
            // translates each side separately (see _loan_summary), which keeps
            // this scan a single regex instead of a Twig expression parser.
            preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\\s*\\|\\s*trans\\(/", $body, $matches);
            foreach ($matches[1] as $id) {
                $ids[stripcslashes($id)][] = basename($path);
            }
        }

        foreach (MailType::cases() as $type) {
            $ids[$type->subject()][] = 'MailType::'.$type->name;
        }

        return $ids;
    }

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        $path = self::root()."/translations/".self::DOMAIN.".{$locale}.yaml";
        self::assertFileExists($path, "The mails catalog for {$locale} is missing entirely.");

        $entries = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match("/^'((?:[^']|'')+)':\\s*'((?:[^']|'')*)'\\s*$/", $line, $m)) {
                $entries[str_replace("''", "'", $m[1])] = str_replace("''", "'", $m[2]);
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private function translatedLocales(): array
    {
        return array_values(array_diff(LocaleCatalog::codes(), [LocaleCatalog::DEFAULT]));
    }

    public function testEveryMailStringIsTranslatedInEveryLocale(): void
    {
        $ids = $this->usedIds();
        self::assertNotEmpty($ids, 'Found no mail ids — the template scan is broken, not the catalogs.');

        $missing = [];
        foreach ($this->translatedLocales() as $locale) {
            $catalog = $this->catalog($locale);
            foreach ($ids as $id => $sources) {
                if (!isset($catalog[$id])) {
                    $missing[] = sprintf('[%s] "%s" (%s)', $locale, $id, implode(', ', array_unique($sources)));
                }
            }
        }

        self::assertSame([], $missing, "Untranslated mail ids:\n".implode("\n", $missing));
    }

    public function testTheMailCatalogsAgreeOnTheirKeys(): void
    {
        $locales = $this->translatedLocales();
        $reference = array_keys($this->catalog($locales[0]));
        sort($reference);

        foreach (\array_slice($locales, 1) as $locale) {
            $keys = array_keys($this->catalog($locale));
            sort($keys);
            self::assertSame($reference, $keys, "{$locale} does not carry the same mail keys as {$locales[0]}.");
        }
    }

    public function testNoMailCatalogEntryIsDead(): void
    {
        $used = $this->usedIds();

        $dead = array_values(array_filter(
            array_keys($this->catalog($this->translatedLocales()[0])),
            static fn (string $key) => !isset($used[$key]),
        ));

        self::assertSame([], $dead, "Mail catalog keys no template or subject asks for:\n".implode("\n", $dead));
    }

    /**
     * A translation that dropped a placeholder renders a sentence missing the
     * name or date it was about — worse than the English original, and invisible
     * unless somebody reads that locale.
     */
    public function testEveryTranslationKeepsThePlaceholdersOfItsId(): void
    {
        $broken = [];
        foreach ($this->translatedLocales() as $locale) {
            foreach ($this->catalog($locale) as $id => $translation) {
                preg_match_all('/%\w+%/', $id, $expected);
                preg_match_all('/%\w+%/', $translation, $actual);

                sort($expected[0]);
                sort($actual[0]);
                if ($expected[0] !== $actual[0]) {
                    $broken[] = sprintf('[%s] "%s" expects %s, has %s', $locale, $id,
                        json_encode($expected[0]), json_encode($actual[0]));
                }
            }
        }

        self::assertSame([], $broken, "Placeholder mismatches:\n".implode("\n", $broken));
    }
}
