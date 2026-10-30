<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonImmutable;

/**
 * Calendar-aligned metric periods, computed in the timezone of $now.
 */
final class Range
{
    /**
     * The current period and the one to compare it with, each as [start, end] (both inclusive)
     * or null when there is no constraint / no previous period (ALL).
     *
     * @return array{0: array{0: CarbonImmutable, 1: CarbonImmutable}|null, 1: array{0: CarbonImmutable, 1: CarbonImmutable}|null}
     */
    public static function bounds(int|string|null $range, CarbonImmutable $now): array
    {
        if ($range === null || $range === 'ALL') {
            return [null, null];
        }

        if (is_int($range) || ctype_digit($range)) {
            $days = max(1, (int) $range);
            $start = $now->subDays($days - 1)->startOfDay();

            return [[$start, $now], [$start->subDays($days)->startOfDay(), $now->subDays($days)]];
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
        $previousEnd = $previousStart->addSeconds((int) $start->diffInSeconds($now, true));
        $previousEnd = $previousEnd->greaterThanOrEqualTo($start) ? $start->subSecond() : $previousEnd;

        return [[$start, $now], [$previousStart, $previousEnd]];
    }
}
