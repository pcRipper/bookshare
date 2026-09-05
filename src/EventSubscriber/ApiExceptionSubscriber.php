<?php

namespace App\EventSubscriber;

use App\Api\ApiError;
use App\Security\AdminAccess;
use Symfony\Component\HttpFoundation\Request;
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
 * The firewall's own denials are the exception, and this class is where they are
 * given a sentence: `access_control` fires before any controller, so the message
 * on an #[IsGranted] attribute never renders for a path the rule already guards.
 * See {@see reason()}.
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
            $this->errors->response(
                $this->reason($exception, $event->getRequest()),
                Response::HTTP_FORBIDDEN,
            ),
        );
    }

    /**
     * The sentence to render, which is only sometimes the exception's own.
     *
     * A denial raised by a voter or a service carries a message we wrote, and
     * that message is its own translation id. A denial raised by the firewall's
     * `access_control` rule does not: Symfony composes it from the decision
     * ("Access Denied." plus each voter's reason, e.g. "The user doesn't have
     * ROLE_ADMIN."), which matches no catalog key and reaches the reader in
     * English whatever language they asked for — and names the role, which is
     * internal vocabulary.
     *
     * That case is detected structurally rather than by sniffing the string:
     * every path that sets a *custom* message leaves it differing from the
     * decision's own, so `message === accessDecision->getMessage()` means
     * nothing was authored and we should supply the sentence ourselves.
     */
    private function reason(\Throwable $exception, Request $request): string
    {
        $denial = $this->originalDenial($exception);

        if ($denial !== null && $this->isComposedBySymfony($denial)) {
            return $this->defaultFor($request);
        }

        $message = trim($exception->getMessage());
        if ($message !== '') {
            return $message;
        }

        // A wrapper built without a message of its own: the reason is on the
        // exception it wraps.
        $inner = trim($denial?->getMessage() ?? '');

        return $inner !== '' ? $inner : $this->defaultFor($request);
    }

    /**
     * True when the denial's message is exactly what Symfony's AccessDecision
     * would have produced — so no voter, service or #[IsGranted] attribute
     * supplied one of ours.
     */
    private function isComposedBySymfony(AccessDeniedException $denial): bool
    {
        return $denial->getAccessDecision()?->getMessage() === trim($denial->getMessage());
    }

    /**
     * The firewall's exception listener re-wraps an authenticated denial as an
     * AccessDeniedHttpException, copying the message but not the decision, so
     * the structural check above has to reach the exception underneath.
     */
    private function originalDenial(\Throwable $exception): ?AccessDeniedException
    {
        if ($exception instanceof AccessDeniedException) {
            return $exception;
        }

        $previous = $exception->getPrevious();

        return $previous instanceof AccessDeniedException ? $previous : null;
    }

    /**
     * A sentence we own, chosen by prefix. `access_control` denies before any
     * controller runs, so this — not the #[IsGranted] attribute's `message` — is
     * what an operator-gated path actually renders; both read the same constant
     * so they cannot drift.
     */
    private function defaultFor(Request $request): string
    {
        return str_starts_with($request->getPathInfo(), AdminAccess::PATH_PREFIX)
            ? AdminAccess::DENIED_MESSAGE
            : 'Access denied.';
    }
}
