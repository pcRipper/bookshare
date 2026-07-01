<?php

namespace App\Tests\Service\BookTemplate;

use App\Service\BookTemplate\ExternalBookTemplateProvider;
use PHPUnit\Framework\TestCase;

class ExternalBookTemplateProviderTest extends TestCase
{
    public function testKeyIsExternal(): void
    {
        self::assertSame('external', (new ExternalBookTemplateProvider())->key());
    }

    public function testSearchIsAnEmptyPlaceholder(): void
    {
        self::assertSame([], (new ExternalBookTemplateProvider())->search('anything', 12));
    }
}
