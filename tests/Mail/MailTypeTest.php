<?php

namespace App\Tests\Mail;

use App\Entity\UserSettings;
use App\Mail\LoanMailer;
use App\Mail\MailType;
use App\Service\LoanEventPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Drift guards for the mail vocabulary, in the spirit of AnalyticsRoutesTest
 * (which reads the SPA router) and CategoryPaletteTest (which pins the palette
 * against its frontend twin).
 *
 * Three ways the mail layer can rot silently, all caught here:
 *  - a new LoanEventPublisher reason is added and nothing mails, because nobody
 *    remembered LoanMailer's map;
 *  - a template file is renamed or missed, which only fails when someone
 *    actually triggers that mail (in a worker, unwatched);
 *  - a gate is typo'd, which in PHP is a fatal call on a magic-free entity.
 */
class MailTypeTest extends TestCase
{
    /** @return array<string, string> constant name => reason string */
    private static function publisherReasons(): array
    {
        $reasons = [];
        foreach ((new \ReflectionClass(LoanEventPublisher::class))->getConstants() as $name => $value) {
            if (\is_string($value) && (str_starts_with($value, 'request.') || str_starts_with($value, 'return.') || str_starts_with($value, 'collection.'))) {
                $reasons[$name] = $value;
            }
        }

        return $reasons;
    }

    /** @return array<string, MailType|null> */
    private static function loanMailerMap(): array
    {
        $map = (new \ReflectionClass(LoanMailer::class))->getConstant('TYPE_BY_REASON');
        self::assertIsArray($map);

        return $map;
    }

    public function testEveryPublisherReasonIsAccountedForByTheMailer(): void
    {
        $map = self::loanMailerMap();

        $unmapped = [];
        foreach (self::publisherReasons() as $name => $reason) {
            if (!\array_key_exists($reason, $map)) {
                $unmapped[] = "$name ($reason)";
            }
        }

        self::assertSame([], $unmapped, implode("\n", [
            'These loan signals mail nobody and never said so.',
            'Add each to LoanMailer::TYPE_BY_REASON — mapped to a MailType, or to',
            'null if sending nothing is the intent:',
            ...$unmapped,
        ]));
    }

    public function testTheMailerMapCarriesNoReasonThePublisherDoesNotPublish(): void
    {
        $reasons = array_values(self::publisherReasons());

        foreach (array_keys(self::loanMailerMap()) as $reason) {
            self::assertContains($reason, $reasons, "LoanMailer maps \"$reason\", which LoanEventPublisher never publishes.");
        }
    }

    /**
     * The two withdrawal reasons are the only deliberate no-mails. Pinned so
     * that "we decided not to" stays distinguishable from "we forgot", and so
     * re-adding either is a conscious edit to this list.
     */
    public function testOnlyTheWithdrawalsMailNobody(): void
    {
        $silent = array_keys(array_filter(self::loanMailerMap(), static fn ($type) => $type === null));
        sort($silent);

        self::assertSame([
            LoanEventPublisher::COLLECTION_REQUEST_CANCELLED,
            LoanEventPublisher::REQUEST_CANCELLED,
        ], $silent);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('types')]
    public function testEveryTypeHasBothTemplates(MailType $type): void
    {
        foreach (['html', 'txt'] as $extension) {
            $path = \dirname(__DIR__, 2).'/templates/'.$type->template($extension);
            self::assertFileExists($path, "Missing {$extension} template for {$type->value}.");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('types')]
    public function testEveryGateNamesARealSettingsAccessor(MailType $type): void
    {
        $gate = $type->gate();
        if ($gate === null) {
            self::assertSame(MailType::AccountWelcome, $type, 'Only the welcome mail is ungated.');

            return;
        }

        self::assertTrue(
            method_exists(UserSettings::class, $gate),
            "MailType::{$type->name} is gated on UserSettings::{$gate}(), which does not exist.",
        );
        self::assertIsBool((new UserSettings())->$gate());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('types')]
    public function testEverySubjectIsAUsableTranslationId(MailType $type): void
    {
        $subject = $type->subject();

        // English sentence as its own id (the ApiError convention), so it has to
        // read as one — and carry no sprintf-style format, which can't be a key.
        self::assertNotSame('', trim($subject));
        self::assertStringNotContainsString('%s', $subject);
        self::assertStringNotContainsString('%d', $subject);
        // Placeholders are extracted for the trans parameters, so the extractor
        // and the sentence must agree.
        foreach ($type->subjectPlaceholders() as $name) {
            self::assertStringContainsString('%'.$name.'%', $subject);
        }
    }

    /** @return iterable<string, array{MailType}> */
    public static function types(): iterable
    {
        foreach (MailType::cases() as $type) {
            yield $type->value => [$type];
        }
    }
}
