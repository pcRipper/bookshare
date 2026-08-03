<?php

namespace App\EventSubscriber;

use App\I18n\LocaleCatalog;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets each request's locale from `Accept-Language`, so the translator renders
 * API messages in the caller's UI language.
 *
 * The SPA sends the header on every call (its axios interceptor reads the same
 * locale it renders in), which is why this deliberately does *not* read the
 * user's persisted `UserSettings.locale`: that would cost a DB query per
 * request and would fight the header whenever a signed-in user switches
 * language before saving. The stored value exists only so the choice can be
 * restored on another device.
 *
 * Runs early — nothing here needs the firewall — and negotiates against
 * `LocaleCatalog`, so an unsupported or missing header lands on the default.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Ahead of the firewall/controller so anything downstream that
        // translates already sees the right locale.
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // getPreferredLanguage() honours the header's quality values and returns
        // the first argument as its own fallback when nothing matches.
        $locale = LocaleCatalog::negotiate(
            $request->getPreferredLanguage(LocaleCatalog::codes()),
        ) ?? LocaleCatalog::DEFAULT;

        $request->setLocale($locale);
    }
}
