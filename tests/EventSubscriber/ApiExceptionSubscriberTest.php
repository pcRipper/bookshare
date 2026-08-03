<?php

namespace App\Tests\EventSubscriber;

use App\Api\ApiError;
use App\EventSubscriber\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class ApiExceptionSubscriberTest extends TestCase
{
    public function testAVoterDenialBecomesATranslated403(): void
    {
        $event = $this->handle(new AccessDeniedException('You do not own this book.'));

        self::assertNotNull($event->getResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
        self::assertSame(
            ['error' => 'translated: You do not own this book.'],
            json_decode((string) $event->getResponse()->getContent(), true),
        );
    }

    public function testTheFirewallsWrappedFormIsHandledToo(): void
    {
        // The security firewall re-wraps an authenticated denial before the
        // kernel sees it; matching only the unwrapped type would never fire.
        $event = $this->handle(new AccessDeniedHttpException(
            'This collection is out on loan and can\'t be edited.',
        ));

        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testAMessagelessWrapperFallsBackToTheWrappedReason(): void
    {
        $event = $this->handle(new AccessDeniedHttpException('', new AccessDeniedException('You do not own this collection.')));

        self::assertSame(
            ['error' => 'translated: You do not own this collection.'],
            json_decode((string) $event->getResponse()?->getContent(), true),
        );
    }

    public function testOtherExceptionsAreLeftToTheKernel(): void
    {
        self::assertNull($this->handle(new NotFoundHttpException('Nope'))->getResponse());
    }

    public function testNonApiRoutesAreLeftAlone(): void
    {
        // Only the JSON API standardises on the { error } body.
        $event = $this->handle(new AccessDeniedException('Denied.'), '/some/page');

        self::assertNull($event->getResponse());
    }

    private function handle(\Throwable $exception, string $path = '/api/books/1'): ExceptionEvent
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => 'translated: ' . $id,
        );

        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        (new ApiExceptionSubscriber(new ApiError($translator)))->onKernelException($event);

        return $event;
    }
}
