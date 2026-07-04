<?php

namespace App\Service\BookTemplate;

use App\Dto\BookTemplateResult;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Dispatches a template search to the provider matching the requested source.
 * Providers are discovered through the `app.book_template_provider` tag (see
 * config/services.yaml) and indexed by their {@see BookTemplateProvider::key()}.
 */
final class BookTemplateSearch
{
    /** @var array<string, BookTemplateProvider> */
    private array $providers = [];

    /**
     * @param iterable<BookTemplateProvider> $providers
     */
    public function __construct(
        #[AutowireIterator('app.book_template_provider')] iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /** The source keys the UI may offer, e.g. ['site', 'external']. */
    public function sources(): array
    {
        return array_keys($this->providers);
    }

    public function supports(string $source): bool
    {
        return isset($this->providers[$source]);
    }

    /**
     * @throws \InvalidArgumentException when no provider handles $source
     */
    public function search(string $source, string $query, int $limit, int $offset = 0): BookTemplateResult
    {
        if (!isset($this->providers[$source])) {
            throw new \InvalidArgumentException(sprintf('Unknown template source "%s".', $source));
        }

        return $this->providers[$source]->search($query, $limit, $offset);
    }
}
