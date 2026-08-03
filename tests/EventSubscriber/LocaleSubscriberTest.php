<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class LocaleSubscriberTest extends TestCase
{
    public function testSetsTheNegotiatedLocaleFromAcceptLanguage(): void
    {
        self::assertSame('uk', $this->localeFor('uk'));
        self::assertSame('de', $this->localeFor('de-AT,de;q=0.9'));
    }

    public function testHighestQualitySupportedLanguageWins(): void
    {
        self::assertSame('fr', $this->localeFor('pl;q=1.0,fr;q=0.8,en;q=0.2'));
    }

    public function testFallsBackToTheDefaultWhenNothingMatches(): void
    {
        self::assertSame('en', $this->localeFor('pl-PL'));
        self::assertSame('en', $this->localeFor(null));
    }

    public function testSubRequestsAreIgnored(): void
    {
        $request = Request::create('/api/books');
        $request->headers->set('Accept-Language', 'uk');
        $request->setLocale('en');

        $this->dispatch($request, HttpKernelInterface::SUB_REQUEST);

        self::assertSame('en', $request->getLocale());
    }

    private function localeFor(?string $header): string
    {
        $request = Request::create('/api/books');
        if ($header !== null) {
            $request->headers->set('Accept-Language', $header);
        }

        $this->dispatch($request);

        return $request->getLocale();
    }

    private function dispatch(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);

        (new LocaleSubscriber())->onKernelRequest($event);
    }
}
