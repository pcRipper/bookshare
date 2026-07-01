<?php

namespace App\Tests\Dto;

use App\Dto\Pagination;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class PaginationTest extends TestCase
{
    private function fromQuery(array $query, int $defaultPerPage = 24): Pagination
    {
        return Pagination::fromRequest(new Request($query), $defaultPerPage);
    }

    public function testDefaultsWhenNoQueryParams(): void
    {
        $p = $this->fromQuery([], 24);

        self::assertSame(1, $p->page);
        self::assertSame(24, $p->perPage);
        self::assertSame(0, $p->offset());
    }

    public function testReadsPageAndPerPage(): void
    {
        $p = $this->fromQuery(['page' => '3', 'perPage' => '10'], 24);

        self::assertSame(3, $p->page);
        self::assertSame(10, $p->perPage);
        // Offset is zero-based: two full pages of 10 skipped.
        self::assertSame(20, $p->offset());
    }

    public function testNonNumericValuesFallBackToDefaults(): void
    {
        $p = $this->fromQuery(['page' => 'abc', 'perPage' => '-5'], 24);

        self::assertSame(1, $p->page);
        self::assertSame(24, $p->perPage);
    }

    public function testPerPageIsClampedToTheCeiling(): void
    {
        $p = $this->fromQuery(['perPage' => '9999'], 24);

        self::assertSame(Pagination::MAX_PER_PAGE, $p->perPage);
    }

    public function testPageZeroFallsBackToDefault(): void
    {
        // "0" is numeric but below the minimum of 1 → clamped up to 1.
        $p = $this->fromQuery(['page' => '0'], 24);

        self::assertSame(1, $p->page);
    }
}
