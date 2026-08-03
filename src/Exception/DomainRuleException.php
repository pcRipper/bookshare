<?php

namespace App\Exception;

/**
 * A business-rule violation whose message is meant for the user (the API turns
 * it into a 409). It extends \DomainException so the existing controller
 * `catch (\DomainException $e)` blocks keep working unchanged.
 *
 * The message doubles as its own translation id — the English text is the key
 * in `translations/messages.<locale>.yaml`, so an untranslated locale simply
 * renders the English sentence, and `getMessage()` stays readable in logs and
 * test assertions. Messages that interpolate a value therefore can't be built
 * with concatenation (the result would never match a catalog key): they pass a
 * parameterised id plus `$params`, and this class renders the English form
 * itself so both audiences are served by one throw site.
 */
class DomainRuleException extends \DomainException
{
    /** @param array<string, string|int> $params translation placeholders, e.g. ['%count%' => 2] */
    public function __construct(
        public readonly string $id,
        public readonly array $params = [],
    ) {
        parent::__construct(strtr($id, $this->stringifyParams()));
    }

    /** @return array<string, string> */
    private function stringifyParams(): array
    {
        return array_map(static fn ($value): string => (string) $value, $this->params);
    }
}
