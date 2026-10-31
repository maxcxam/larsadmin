<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonImmutable;

/**
 * Calendar-aligned metric periods, computed in the timezone of $now.
 *
 * @internal Not part of the public API.
 */
final class Range
{
    /**
     * The current period and the one to compare it with, each as [start, end) (half-open, like the trend
     * buckets) or null when there is no constraint / no previous period (ALL). The current period ends
     * with the second that contains $now; the previous one never reaches into it.
     *
     * @return array{0: array{0: CarbonImmutable, 1: CarbonImmutable}|null, 1: array{0: CarbonImmutable, 1: CarbonImmutable}|null}
     */
    public static function bounds(int|string|null $range, CarbonImmutable $now): array
    {
        if ($range === null || $range === 'ALL') {
            return [null, null];
        }

        $end = $now->startOfSecond()->addSecond();

        if (is_int($range) || ctype_digit($range)) {
            $days = max(1, (int) $range);
            $start = $now->subDays($days - 1)->startOfDay();

            return [[$start, $end], [$start->subDays($days)->startOfDay(), $end->subDays($days)]];
        }

        $start = match ($range) {
            'TODAY' => $now->startOfDay(),
            'MTD' => $now->startOfMonth(),
            'QTD' => $now->startOfQuarter(),
            'YTD' => $now->startOfYear(),
            default => null,
        };

        if ($start === null) {
            return [null, null];
        }

        $previousStart = match ($range) {
            'TODAY' => $start->subDay()->startOfDay(),
            'MTD' => $start->subMonthNoOverflow()->startOfMonth(),
            'QTD' => $start->subMonthsNoOverflow(3)->startOfMonth(),
            default => $start->subYear()->startOfYear(),
        };

        // Same elapsed time into the previous period, never reaching into the current one.
        $previousEnd = $previousStart->addSeconds((int) $start->diffInSeconds($end, true));
        $previousEnd = $previousEnd->greaterThan($start) ? $start : $previousEnd;

        return [[$start, $end], [$previousStart, $previousEnd]];
    }
}
