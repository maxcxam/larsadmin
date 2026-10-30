<?php

namespace Maxcxam\Larsadmin\Metrics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as BaseBuilder;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Metrics\Results\MetricResult;
use Maxcxam\Larsadmin\Metrics\Results\TrendResult;

/**
 * A line of an aggregate per day, week (Monday first) or month, ending with the current (partial) bucket.
 * The range is the number of buckets (at most 366).
 *
 * Buckets are computed in PHP in the metric timezone (exact across DST) and aggregated with one portable
 * CASE WHEN query, so it behaves identically on sqlite, MySQL/MariaDB and PostgreSQL.
 *
 * SECURITY: pass an Entity class to scope the rows by its indexQuery() and viewAny ($this->scoped() narrows
 * it further, still scoped). A model class, Eloquent builder or query builder is NOT restricted:
 * it aggregates every row it can reach.
 */
abstract class Trend extends Metric
{
    public string $component = 'trend-metric';

    /**
     * The number of buckets to show.
     *
     * @return array<int|string, string>
     */
    public function ranges(): array
    {
        return $this->labelled([7, 30, 90]);
    }

    abstract public function calculate(MetricRequest $request): TrendResult;

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function countByDays(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'day', 'count', null, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function countByWeeks(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'week', 'count', null, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function countByMonths(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'month', 'count', null, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function sumByDays(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'day', 'sum', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function sumByWeeks(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'week', 'sum', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function sumByMonths(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'month', 'sum', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function averageByDays(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'day', 'avg', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function averageByWeeks(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'week', 'avg', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function averageByMonths(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'month', 'avg', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function minByDays(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'day', 'min', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function minByWeeks(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'week', 'min', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function minByMonths(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'month', 'min', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function maxByDays(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'day', 'max', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function maxByWeeks(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'week', 'max', $column, $dateColumn);
    }

    /** @param  class-string|EntitySource|Builder|BaseBuilder  $model */
    protected function maxByMonths(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): TrendResult
    {
        return $this->aggregate($request, $model, 'month', 'max', $column, $dateColumn);
    }

    /**
     * Aggregate per bucket.
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model  Entity class or EntitySource (scoped), model class or builder (UNSCOPED)
     * @param  'day'|'week'|'month'  $unit
     * @param  'count'|'sum'|'avg'|'average'|'min'|'max'  $function
     */
    protected function aggregate(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $unit, string $function, ?string $column = null, ?string $dateColumn = null): TrendResult
    {
        $function = $function === 'average' ? 'avg' : $function;

        if (! in_array($function, self::FUNCTIONS, true)) {
            throw new InvalidArgumentException("Unsupported aggregate [{$function}].");
        }

        if ($function !== 'count' && $column === null) {
            throw new InvalidArgumentException("The [{$function}] aggregate needs a column.");
        }

        $buckets = TrendBuckets::make($unit, $this->bucketCount($request), $request->now);
        $source = Source::make($request, $model);

        $date = $source->dateColumn($dateColumn);
        $wrappedDate = $source->wrap($date);

        $whens = [];
        $bindings = [];

        foreach ($buckets as $index => [$start, $end]) {
            $whens[] = "WHEN {$wrappedDate} >= ? AND {$wrappedDate} < ? THEN {$index}";
            $bindings[] = $source->date($start);
            $bindings[] = $source->date($end);
        }

        $expression = $function === 'count' ? 'COUNT(*)' : strtoupper($function).'('.$source->wrap($source->qualify((string) $column)).')';

        $rows = $source->query()
            ->selectRaw('CASE '.implode(' ', $whens).' END as larsadmin_bucket, '.$expression.' as larsadmin_aggregate', $bindings)
            ->where($date, '>=', $source->date($buckets[0][0]))
            ->where($date, '<', $source->date($buckets[array_key_last($buckets)][1]))
            ->groupBy('larsadmin_bucket')
            ->get()
            ->pluck('larsadmin_aggregate', 'larsadmin_bucket');

        $missing = in_array($function, ['count', 'sum'], true) ? 0 : null;
        $locale = app()->getLocale();
        $format = $unit === 'month' ? 'MMM YYYY' : 'D MMM';

        $points = [];

        foreach ($buckets as $index => [$start]) {
            $points[] = [
                'label' => $start->locale($locale)->isoFormat($format),
                'start' => $start->toIso8601String(),
                'value' => MetricResult::number($rows->get($index)) ?? $missing,
            ];
        }

        return new TrendResult($unit, $points);
    }

    private function bucketCount(MetricRequest $request): int
    {
        $range = $request->range;

        return is_int($range) || (is_string($range) && ctype_digit($range)) ? (int) $range : 30;
    }
}
