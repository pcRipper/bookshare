<?php

namespace App\Tests\Exception;

use App\Exception\DomainRuleException;
use PHPUnit\Framework\TestCase;

class DomainRuleExceptionTest extends TestCase
{
    public function testIsCaughtByTheExistingDomainExceptionHandlers(): void
    {
        self::assertInstanceOf(\DomainException::class, new DomainRuleException('Nope.'));
    }

    public function testAPlainMessageIsBothTheTextAndTheTranslationId(): void
    {
        $e = new DomainRuleException('You cannot follow yourself.');

        self::assertSame('You cannot follow yourself.', $e->getMessage());
        self::assertSame('You cannot follow yourself.', $e->id);
        self::assertSame([], $e->params);
    }

    public function testPlaceholdersAreRenderedIntoTheEnglishMessageButKeptForTranslation(): void
    {
        // The id must stay parameterised — that's what matches a catalog key —
        // while getMessage() stays readable for logs and test assertions.
        $e = new DomainRuleException('A collection needs at least %count% of your books.', ['%count%' => 2]);

        self::assertSame('A collection needs at least 2 of your books.', $e->getMessage());
        self::assertSame('A collection needs at least %count% of your books.', $e->id);
        self::assertSame(['%count%' => 2], $e->params);
    }

    public function testNonStringParamsAreCastForRendering(): void
    {
        $e = new DomainRuleException('Up to %n% items.', ['%n%' => 10]);

        self::assertSame('Up to 10 items.', $e->getMessage());
    }
}
