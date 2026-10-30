<?php

namespace Maxcxam\Larsadmin\Metrics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Maxcxam\Larsadmin\Metrics\Results\ValueResult;

/**
 * A single number for the selected range, compared with the previous period.
 *
 * SECURITY: pass an Entity class to scope the number by its indexQuery() and viewAny ($this->scoped() narrows
 * it further, still scoped). A model class, Eloquent builder or query builder is NOT restricted:
 * it counts every row it can reach.
 */
abstract class Value extends Metric
{
    public string $component = 'value-metric';

    /**
     * @return array<int|string, string>
     */
    public function ranges(): array
    {
        return $this->labelled([30, 60, 365, 'TODAY', 'MTD', 'QTD', 'YTD']);
    }

    abstract public function calculate(MetricRequest $request): ValueResult;

    /**
     * Count rows. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function count(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'count', null, $dateColumn);
    }

    /**
     * Sum a column. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function sum(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'sum', $column, $dateColumn);
    }

    /**
     * Average a column. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function average(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'avg', $column, $dateColumn);
    }

    /**
     * Smallest value of a column. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function min(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'min', $column, $dateColumn);
    }

    /**
     * Largest value of a column. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function max(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, ?string $dateColumn = null): ValueResult
    {
        return $this->aggregate($request, $model, 'max', $column, $dateColumn);
    }

    /**
     * Wrap a value you computed yourself.
     */
    protected function result(int|float|null $value): ValueResult
    {
        return new ValueResult($value);
    }

    /**
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    private function aggregate(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $function, ?string $column, ?string $dateColumn): ValueResult
    {
        $source = Source::make($request, $model);
        $column = $column === null ? null : $source->qualify($column);

        [$current, $previous] = Range::bounds($request->range, $request->now);

        return $this->result($this->aggregateQuery($source->between($dateColumn, $current), $function, $column))
            ->previous($previous === null ? null : $this->aggregateQuery($source->between($dateColumn, $previous), $function, $column));
    }
}
