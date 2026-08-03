<?php

namespace App\EventSubscriber;

use App\Api\ApiError;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Renders authorization failures under /api as the same translated
 * `{ "error": … }` body every other API failure uses.
 *
 * The voters and services that guard ownership (`BookVoter`, `CollectionVoter`,
 * the request services' assertOwner/assertRequester) all raise
 * AccessDeniedException with a user-facing English reason. Left to the kernel
 * that reason would come back as an untranslated problem-details `detail`; here
 * it goes through the translator — the message is its own translation id, the
 * same convention {@see ApiError} documents.
 *
 * Runs after the security firewall's own exception listener, which is what turns
 * an *unauthenticated* denial into a 401 challenge (that must not become a 403)
 * and re-wraps an authenticated one as AccessDeniedHttpException — hence both
 * types are matched here, and the negative priority. Still ahead of the kernel's
 * ErrorListener (-128).
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApiError $errors,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', -64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof AccessDeniedException && !$exception instanceof AccessDeniedHttpException) {
            return;
        }

        $event->setResponse(
            $this->errors->response($this->reason($exception), Response::HTTP_FORBIDDEN),
        );
    }

    /**
     * The voter/service message. The firewall's wrapper copies it onto itself,
     * but fall back to the wrapped exception in case a wrapper is ever built
     * without one.
     */
    private function reason(\Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message !== '') {
            return $message;
        }

        $previous = $exception->getPrevious();

        return $previous !== null && trim($previous->getMessage()) !== ''
            ? trim($previous->getMessage())
            : 'Access denied.';
    }
}
