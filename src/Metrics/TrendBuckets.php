<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendar buckets of a trend, computed in PHP so boundaries are exact across DST changes and
 * non-hour offsets on every database (ADR-012). Each bucket is [start, end) in the timezone of $now.
 *
 * @internal Not part of the public API.
 */
final class TrendBuckets
{
    public const int MAX = 366;

    /**
     * The buckets ending with the (partial) one that contains $now, oldest first.
     *
     * @param  'day'|'week'|'month'  $unit  weeks start on Monday
     * @param  int  $count  clamped to 1..MAX
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public static function make(string $unit, int $count, CarbonImmutable $now): array
    {
        $current = match ($unit) {
            'day' => $now->startOfDay(),
            'week' => $now->startOfWeek(CarbonInterface::MONDAY),
            'month' => $now->startOfMonth(),
            default => throw new InvalidArgumentException("Unknown trend unit [{$unit}]; use day, week or month."),
        };

        $buckets = [];

        for ($i = min(max($count, 1), self::MAX) - 1; $i >= 0; $i--) {
            // Calendar arithmetic re-aligned to midnight: a DST gap or overlap never shifts a boundary.
            $start = match ($unit) {
                'day' => $current->subDays($i)->startOfDay(),
                'week' => $current->subWeeks($i)->startOfDay(),
                'month' => $current->subMonthsNoOverflow($i)->startOfMonth(),
            };

            $end = match ($unit) {
                'day' => $start->addDay()->startOfDay(),
                'week' => $start->addWeek()->startOfDay(),
                'month' => $start->addMonthNoOverflow()->startOfMonth(),
            };

            $buckets[] = [$start, $end];
        }

        return $buckets;
    }
}
