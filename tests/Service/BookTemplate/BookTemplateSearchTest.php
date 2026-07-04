<?php

namespace App\Tests\Service\BookTemplate;

use App\Dto\BookTemplate;
use App\Dto\BookTemplateResult;
use App\Service\BookTemplate\BookTemplateProvider;
use App\Service\BookTemplate\BookTemplateSearch;
use PHPUnit\Framework\TestCase;

class BookTemplateSearchTest extends TestCase
{
    private function provider(string $key, array $results = []): BookTemplateProvider
    {
        $provider = $this->createStub(BookTemplateProvider::class);
        $provider->method('key')->willReturn($key);
        $provider->method('search')->willReturn(new BookTemplateResult($results, false));

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

        self::assertSame([$template], $search->search('site', 'dune', 12)->items);
        self::assertSame([], $search->search('external', 'dune', 12)->items);
    }

    public function testPassesLimitAndOffsetThroughToTheProvider(): void
    {
        $provider = $this->createMock(BookTemplateProvider::class);
        $provider->method('key')->willReturn('site');
        $provider->expects($this->once())
            ->method('search')
            ->with('dune', 24, 48)
            ->willReturn(new BookTemplateResult([], false));

        (new BookTemplateSearch([$provider]))->search('site', 'dune', 24, 48);
    }

    public function testUnknownSourceThrows(): void
    {
        $search = new BookTemplateSearch([$this->provider('site')]);

        $this->expectException(\InvalidArgumentException::class);
        $search->search('mystery', 'dune', 12);
    }
}
