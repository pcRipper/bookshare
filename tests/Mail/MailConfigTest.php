<?php

namespace App\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
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
