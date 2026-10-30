<?php

namespace Maxcxam\Larsadmin\Metrics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Maxcxam\Larsadmin\Metrics\Results\ProgressResult;

/**
 * A value for the selected range measured against a target.
 *
 * SECURITY: pass an Entity class to scope the number by its indexQuery() and viewAny ($this->scoped() narrows
 * it further, still scoped). A model class, Eloquent builder or query builder is NOT restricted:
 * it counts every row it can reach.
 */
abstract class Progress extends Metric
{
    public string $component = 'progress-metric';

    /**
     * @return array<int|string, string>
     */
    public function ranges(): array
    {
        return $this->labelled([30, 60, 365, 'TODAY', 'MTD', 'QTD', 'YTD']);
    }

    abstract public function calculate(MetricRequest $request): ProgressResult;

    /**
     * Count rows towards $target. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function count(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, int|float $target, ?string $dateColumn = null): ProgressResult
    {
        return $this->progress($request, $model, 'count', null, $target, $dateColumn);
    }

    /**
     * Sum a column towards $target. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function sum(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, int|float $target, ?string $dateColumn = null): ProgressResult
    {
        return $this->progress($request, $model, 'sum', $column, $target, $dateColumn);
    }

    /**
     * Wrap a value you computed yourself.
     */
    protected function result(int|float|null $value, int|float $target): ProgressResult
    {
        return new ProgressResult($value, $target);
    }

    /**
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    private function progress(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $function, ?string $column, int|float $target, ?string $dateColumn): ProgressResult
    {
        $source = Source::make($request, $model);
        [$current] = Range::bounds($request->range, $request->now);

        return $this->result($this->aggregateQuery(
            $source->between($dateColumn, $current),
            $function,
            $column === null ? null : $source->qualify($column),
        ), $target);
    }
}
