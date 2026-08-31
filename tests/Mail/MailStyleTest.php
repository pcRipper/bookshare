<?php

namespace App\Tests\Mail;

use PHPUnit\Framework\TestCase;

/**
 * Pins the mail templates to the app's live design tokens.
 *
 * A mail can't read a CSS variable, so every colour and font in
 * templates/emails/ is a literal — which means the design system and the mails
 * can drift apart silently, and did: these templates first shipped in the
 * original green palette (#274738, Playfair Display) months after the app was
 * recoloured to Maritime Navy, because they were built from
 * references/design/literary_commons/DESIGN.md, which was never updated.
 *
 * assets/src/styles/tokens.css is the source of truth. This is the same job
 * CategoryPaletteTest does for the category palette across the front/back split.
 */
class MailStyleTest extends TestCase
{
    private const TOKENS = 'assets/src/styles/tokens.css';
    private const EMAILS = 'templates/emails';

    /** Retired palettes. Named so a reintroduction fails loudly, not subtly. */
    private const RETIRED = [
        '#274738' => 'the pre-navy primary green',
        '#fbf9f5' => 'the pre-navy warm page ground',
        '#f5f3ef' => 'the pre-navy warm container',
        '#1b1c1a' => 'the pre-navy on-surface',
        '#414844' => 'the pre-navy on-surface-variant',
        '#727974' => 'the pre-navy outline',
        'Playfair Display' => 'the pre-Literata display face',
        'Work Sans' => 'the pre-IBM-Plex body face',
    ];

    private static function read(string $relative): string
    {
        $path = \dirname(__DIR__, 2).'/'.$relative;
        self::assertFileExists($path);

        return file_get_contents($path);
    }

    /** @return list<string> lowercased #rrggbb values defined in tokens.css */
    private function tokenColors(): array
    {
        preg_match_all('/--[\w-]+:\s*(#[0-9a-fA-F]{6})\b/', self::read(self::TOKENS), $matches);
        self::assertNotEmpty($matches[1], 'Parsed no colours out of tokens.css — the scan is broken, not the mails.');

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        foreach (glob(\dirname(__DIR__, 2).'/'.self::EMAILS.'/*.twig') ?: [] as $path) {
            yield basename($path) => [self::EMAILS.'/'.basename($path)];
        }
    }

    /**
     * Every colour in a mail must be a value the design system actually defines.
     * That is stricter than "not the old green" on purpose: it also catches a
     * hand-picked shade nobody chose.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testEveryColourComesFromTheDesignTokens(string $template): void
    {
        $allowed = $this->tokenColors();

        preg_match_all('/#[0-9a-fA-F]{6}\b/', self::read($template), $matches);

        foreach (array_unique(array_map('strtolower', $matches[0])) as $colour) {
            self::assertContains(
                $colour,
                $allowed,
                "{$template} uses {$colour}, which is not a value in ".self::TOKENS.'.',
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testNoRetiredColourOrFaceSurvives(string $template): void
    {
        $body = self::read($template);

        foreach (self::RETIRED as $needle => $what) {
            // The doc comment in base.html.twig names the retired values on
            // purpose, to explain the trap — so only real declarations count.
            $body = preg_replace('/\{#.*?#\}/s', '', $body);

            self::assertStringNotContainsString(
                $needle,
                $body,
                "{$template} still carries {$needle} ({$what}).",
            );
        }
    }

    /**
     * The families are named for the clients that do have them; the fallback
     * stack is what almost every reader actually sees. Both halves matter, so
     * both are pinned.
     */
    public function testTheMailFacesMatchTheAppsFaces(): void
    {
        $tokens = self::read(self::TOKENS);

        preg_match('/--font-display:\s*\'([^\']+)\'/', $tokens, $display);
        preg_match('/--font-body:\s*\'([^\']+)\'/', $tokens, $body);
        self::assertNotEmpty($display[1] ?? null);
        self::assertNotEmpty($body[1] ?? null);

        $layout = self::read(self::EMAILS.'/base.html.twig');

        self::assertStringContainsString("'{$display[1]}',Georgia,'Times New Roman',serif", $layout);
        self::assertStringContainsString("'{$body[1]}',Helvetica,Arial,sans-serif", $layout);
    }

    /**
     * The one rule an email client enforces harder than a browser: a mail is a
     * single fixed-width column. Both halves live in the layout — the cap and
     * the fluid width that keeps a phone from panning.
     */
    public function testTheColumnIsCappedAndFluid(): void
    {
        $layout = self::read(self::EMAILS.'/base.html.twig');

        self::assertStringContainsString('max-width:600px', $layout);
        self::assertStringContainsString('width:100%', $layout);
    }
}
