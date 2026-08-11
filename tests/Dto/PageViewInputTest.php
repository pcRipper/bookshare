<?php

namespace App\Tests\Dto;

use App\Analytics\AnalyticsRoutes;
use App\Dto\PageViewInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * This is the test that says the ingest endpoint cannot write arbitrary strings
 * into the traffic table — see AnalyticsRoutes for why that bound matters.
 */
class PageViewInputTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    private function input(string $route): PageViewInput
    {
        $input = new PageViewInput();
        $input->route = $route;

        return $input;
    }

    public function testEveryAllowedRouteValidates(): void
    {
        foreach (AnalyticsRoutes::names() as $name) {
            self::assertCount(
                0,
                $this->validator->validate($this->input($name)),
                "route $name should validate",
            );
        }
    }

    public function testBlankRouteIsRejected(): void
    {
        $messages = [];
        foreach ($this->validator->validate($this->input('')) as $v) {
            $messages[] = $v->getMessage();
        }

        self::assertContains('A route name is required.', $messages);
    }

    #[DataProvider('unknownRoutes')]
    public function testUnknownRouteIsRejected(string $route): void
    {
        $messages = [];
        foreach ($this->validator->validate($this->input($route)) as $v) {
            $messages[] = $v->getMessage();
        }

        self::assertContains('Unknown route.', $messages);
    }

    /** @return iterable<string, array{string}> */
    public static function unknownRoutes(): iterable
    {
        yield 'invented name' => ['nope'];
        yield 'a path, not a name' => ['/library'];
        yield 'a path carrying an id' => ['profile/42'];
        yield 'case variant' => ['Library'];
        yield 'traversal-shaped' => ['../../etc/passwd'];
        yield 'markup' => ['<script>alert(1)</script>'];
        // The cardinality attack the allow-list exists to stop.
        yield 'very long string' => [str_repeat('a', 5000)];
    }
}
