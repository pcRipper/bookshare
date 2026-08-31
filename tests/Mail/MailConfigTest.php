<?php

namespace App\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the shipped YAML, the way PublicAccessConfigTest pins its firewall.
 *
 * `when@test: async: in-memory://` means no test can observe the real transport,
 * so nothing else in the suite would notice if mail went back to being sent
 * synchronously — and the symptom in production is not an error but a loan
 * transition that now waits on an SMTP handshake inside one of five FPM workers.
 */
class MailConfigTest extends TestCase
{
    private static function config(string $file): array
    {
        return Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/'.$file);
    }

    public function testMailIsRoutedToTheAsyncTransport(): void
    {
        $routing = self::config('messenger.yaml')['framework']['messenger']['routing'] ?? [];

        self::assertArrayHasKey(SendEmailMessage::class, $routing, 'Nothing routes mail off the sync bus.');
        self::assertSame('async', $routing[SendEmailMessage::class]);
    }

    /**
     * The queue table is a checked-in migration. With auto_setup on, a worker
     * would DDL against production on its first message and the table's shape
     * would live nowhere in the repo.
     */
    public function testTheTransportDoesNotSetItselfUp(): void
    {
        $messenger = self::config('messenger.yaml');

        // The async DSN is env-driven, so the shipped defaults are what can be
        // pinned: the parameter fallback, and every DSN the repo actually sets.
        self::assertStringContainsString('auto_setup=0', $messenger['parameters']['messenger_transport_dsn_fallback']);
        self::assertStringContainsString('auto_setup=0', $messenger['framework']['messenger']['transports']['failed']);

        foreach (['.env', 'docker/local/php/app.env'] as $envFile) {
            $body = file_get_contents(\dirname(__DIR__, 2).'/'.$envFile);
            preg_match('/^MESSENGER_TRANSPORT_DSN=(.*)$/m', $body, $m);
            self::assertNotEmpty($m, "{$envFile} sets no MESSENGER_TRANSPORT_DSN.");
            self::assertStringContainsString('auto_setup=0', $m[1], "{$envFile} would let the transport DDL its own table.");
        }
    }

    /** A queue with no failure transport drops what it cannot deliver. */
    public function testFailedMailIsKeptRatherThanDiscarded(): void
    {
        self::assertSame('failed', self::config('messenger.yaml')['framework']['messenger']['failure_transport']);
    }

    /**
     * Both env vars fall back to a parameter rather than being read directly:
     * dev Symfony reads only the hand-maintained .env.local.php, so a bare
     * %env(MAILER_DSN)% would throw "Environment variable not found" on every
     * request until somebody edited a gitignored file by hand.
     */
    public function testTheMailEnvVarsHaveFallbacks(): void
    {
        $mailer = self::config('mailer.yaml');

        self::assertStringContainsString('default:mailer_dsn_fallback:MAILER_DSN', $mailer['framework']['mailer']['dsn']);
        self::assertArrayHasKey('mailer_dsn_fallback', $mailer['parameters']);
        self::assertStringContainsString(
            'default:messenger_transport_dsn_fallback:MESSENGER_TRANSPORT_DSN',
            self::config('messenger.yaml')['framework']['messenger']['transports']['async']['dsn'],
        );
    }

    /**
     * Every From:/sender value we ship must parse as an address.
     *
     * This is not hypothetical tidiness. `MAILER_FROM` takes `Name <addr>` while
     * `MAILER_SENDER` takes a bare `addr`, and dropping the angle brackets off
     * the first produces a value that fails in the worst possible place: the
     * framework applies it as the default From: header, so the mail throws while
     * it is still being *built* — before the bus, before SMTP. The queue stays at
     * zero, the failure transport stays empty, and the only trace is one warning
     * on the `mail` channel. It cost an evening in production once.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function addressValues(): iterable
    {
        $files = ['.env', 'docker/local/php/app.env'];

        foreach ($files as $file) {
            $body = file_get_contents(\dirname(__DIR__, 2).'/'.$file);

            foreach (['MAILER_FROM', 'MAILER_SENDER'] as $var) {
                // Only real assignments: the same files carry commented-out
                // examples of DSNs and alternatives, which are documentation.
                if (preg_match('/^'.$var.'=(.*)$/m', $body, $m) === 1) {
                    yield "{$file}: {$var}" => [trim($m[1], " \"'"), "{$file} {$var}"];
                }
            }
        }

        // The fallbacks are what a machine with no value configured actually
        // sends with, so they are held to the same rule.
        $parameters = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/mailer.yaml')['parameters'];
        yield 'mailer.yaml: from fallback' => [$parameters['mailer_from_fallback'], 'mailer_from_fallback'];
        yield 'mailer.yaml: sender fallback' => [$parameters['mailer_sender_fallback'], 'mailer_sender_fallback'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addressValues')]
    public function testEveryShippedFromAddressParses(string $value, string $where): void
    {
        $address = Address::create($value);

        self::assertNotSame('', $address->getAddress(), "{$where} parsed to an empty address.");
    }

    /**
     * A queued mail with nothing consuming the queue is indistinguishable from
     * no mail at all, so the worker is part of both stacks — and its absence is
     * exactly the kind of omission a green suite would otherwise miss.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('composeFiles')]
    public function testEveryStackRunsAWorker(string $file): void
    {
        $services = Yaml::parseFile(\dirname(__DIR__, 2).'/'.$file)['services'];

        self::assertArrayHasKey('messenger-worker', $services, "{$file} queues mail but never consumes it.");
        self::assertStringContainsString('messenger:consume async', $services['messenger-worker']['command']);
        // Without a limit the process grows until the box notices.
        self::assertStringContainsString('--memory-limit=', $services['messenger-worker']['command']);
    }

    /** @return iterable<string, array{string}> */
    public static function composeFiles(): iterable
    {
        yield 'local' => ['compose.yaml'];
        yield 'production' => ['compose.prod.yaml'];
    }

    /** Mailpit is a dev catcher; production must never point at it. */
    public function testProductionShipsNoMailCatcher(): void
    {
        $prod = Yaml::parseFile(\dirname(__DIR__, 2).'/compose.prod.yaml');

        self::assertArrayNotHasKey('mailpit', $prod['services']);
    }
}
