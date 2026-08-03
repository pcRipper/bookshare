<?php

namespace App\Api;

use App\Exception\DomainRuleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the API's `{ "error": … }` failure bodies with the message translated
 * into the caller's language (the request locale, negotiated from
 * `Accept-Language` by {@see \App\EventSubscriber\LocaleSubscriber}).
 *
 * The English sentence passed in *is* the translation id — there is no separate
 * key vocabulary to keep in step, an untranslated locale renders the English
 * text, and the strings stay readable and greppable at their call sites. Only
 * `translations/messages.<locale>.yaml` needs filling per language.
 */
class ApiError
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    /** @param array<string, string|int> $params translation placeholders */
    public function response(string $message, int $status, array $params = []): JsonResponse
    {
        return new JsonResponse(['error' => $this->translate($message, $params)], $status);
    }

    /**
     * The response for a caught business-rule violation. A DomainRuleException
     * carries its untranslated id and placeholders; a plain \DomainException
     * (from elsewhere) falls back to its message.
     */
    public function fromDomain(\DomainException $e, int $status): JsonResponse
    {
        return $e instanceof DomainRuleException
            ? $this->response($e->id, $status, $e->params)
            : $this->response($e->getMessage(), $status);
    }

    /** @param array<string, string|int> $params */
    public function translate(string $message, array $params = []): string
    {
        return $this->translator->trans($message, $params);
    }
}
