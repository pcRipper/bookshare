<?php

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

/**
 * The date range the operator dashboard reports over, from `?window=`.
 *
 * Clamped, never rejected — the same contract as Pagination, and the reason
 * there is no "invalid window" error to translate. An unrecognised value snaps
 * to the nearest offered one rather than 422-ing a dashboard.
 *
 * Everything here is server-local, matching the rest of the app (timestamps are
 * stored WITHOUT TIME ZONE and entities stamp a bare `new DateTimeImmutable()`).
 * There is one operator in one timezone; a per-viewer timezone would have to
 * start with the storage layer, not here.
 */
final class StatsWindow
{
    /** The windows the UI offers. Anything else snaps to the nearest. */
    public const ALLOWED = [7, 30, 90];

    public const DEFAULT = 30;

    public function __construct(public readonly int $days) {}

    public static function fromRequest(Request $request): self
    {
        $raw = $request->query->get('window');

        if ($raw === null || !ctype_digit((string) $raw)) {
            return new self(self::DEFAULT);
        }

        return new self(self::nearestAllowed((int) $raw));
    }

    /** Midnight on the first day of the window; the window includes today. */
    public function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today')->modify(sprintf('-%d days', $this->days - 1));
    }

    public function until(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    /**
     * Every calendar day in the window, oldest first — the x-axis every series
     * is aligned to.
     *
     * @return list<string> exactly $days entries, Y-m-d
     */
    public function dayKeys(): array
    {
        $day = $this->since();
        $keys = [];

        for ($i = 0; $i < $this->days; ++$i) {
            $keys[] = $day->format('Y-m-d');
            $day = $day->modify('+1 day');
        }

        return $keys;
    }

    /**
     * Projects a sparse `Y-m-d => count` map onto the full axis, filling absent
     * days with zero.
     *
     * SQL can only return days that have rows, and a chart fed a sparse series
     * silently draws a *different, wrong shape* rather than failing — a category
     * axis treats the array as ordinal, so missing days close up. Gap-filling is
     * therefore part of the response contract, not a nicety.
     *
     * @param  array<string, int> $byDay
     * @return list<int>
     */
    public function fill(array $byDay): array
    {
        return array_map(
            static fn (string $key) => $byDay[$key] ?? 0,
            $this->dayKeys(),
        );
    }

    private static function nearestAllowed(int $days): int
    {
        $best = self::DEFAULT;
        $bestDistance = PHP_INT_MAX;

        foreach (self::ALLOWED as $allowed) {
            $distance = abs($allowed - $days);
            if ($distance < $bestDistance) {
                $best = $allowed;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
