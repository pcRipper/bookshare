<?php

namespace App\Tests\Service\BookTemplate;

use App\Dto\BookTemplate;
use App\Service\BookTemplate\BookTemplateProvider;
use App\Service\BookTemplate\BookTemplateSearch;
use PHPUnit\Framework\TestCase;

class BookTemplateSearchTest extends TestCase
{
    private function provider(string $key, array $results = []): BookTemplateProvider
    {
        $provider = $this->createStub(BookTemplateProvider::class);
        $provider->method('key')->willReturn($key);
        $provider->method('search')->willReturn($results);

        return $provider;
    }

    public function testIndexesProvidersByKey(): void
    {
        $search = new BookTemplateSearch([$this->provider('site'), $this->provider('external')]);

        self::assertSame(['site', 'external'], $search->sources());
        self::assertTrue($search->supports('site'));
        self::assertFalse($search->supports('nope'));
    }

    public function testDispatchesToTheMatchingProvider(): void
    {
        $template = new BookTemplate('Dune', 'Frank Herbert');
        $search = new BookTemplateSearch([
            $this->provider('site', [$template]),
            $this->provider('external', []),
        ]);

        self::assertSame([$template], $search->search('site', 'dune', 12));
        self::assertSame([], $search->search('external', 'dune', 12));
    }

    public function testUnknownSourceThrows(): void
    {
        $search = new BookTemplateSearch([$this->provider('site')]);

        $this->expectException(\InvalidArgumentException::class);
        $search->search('mystery', 'dune', 12);
    }
}
