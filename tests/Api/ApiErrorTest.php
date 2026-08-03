<?php

namespace App\Tests\Api;

use App\Api\ApiError;
use App\Exception\DomainRuleException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class ApiErrorTest extends TestCase
{
    public function testResponseCarriesTheTranslatedMessageAndStatus(): void
    {
        $errors = new ApiError($this->translatorReturning('Ця книга вам не належить.'));

        $response = $errors->response('You do not own this book.', Response::HTTP_FORBIDDEN);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(['error' => 'Ця книга вам не належить.'], json_decode((string) $response->getContent(), true));
    }

    public function testADomainRuleExceptionIsTranslatedFromItsIdAndParams(): void
    {
        // The rendered English message must not be what gets looked up — only the
        // parameterised id matches a catalog key.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('A collection needs at least %count% of your books.', ['%count%' => 2])
            ->willReturn('Eine Sammlung braucht mindestens 2 deiner Bücher.');

        $response = (new ApiError($translator))->fromDomain(
            new DomainRuleException('A collection needs at least %count% of your books.', ['%count%' => 2]),
            Response::HTTP_CONFLICT,
        );

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Eine Sammlung braucht mindestens 2 deiner Bücher.'],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testAPlainDomainExceptionFallsBackToItsMessage(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('Something domain-ish.', [])
            ->willReturn('Something domain-ish.');

        $response = (new ApiError($translator))->fromDomain(
            new \DomainException('Something domain-ish.'),
            Response::HTTP_CONFLICT,
        );

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    private function translatorReturning(string $translation): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn($translation);

        return $translator;
    }
}
