<?php

namespace Maxcxam\Larsadmin\Metrics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Maxcxam\Larsadmin\Metrics\Results\MetricResult;
use Maxcxam\Larsadmin\Metrics\Results\PartitionResult;

/**
 * Rows grouped by a column (a donut). It has no range unless you override ranges(); it then
 * still ignores it, because the helpers take no date column.
 *
 * SECURITY: pass an Entity class to scope the groups by its indexQuery() and viewAny ($this->scoped() narrows
 * it further, still scoped). A model class, Eloquent builder or query builder is NOT restricted:
 * it groups every row it can reach.
 */
abstract class Partition extends Metric
{
    public string $component = 'partition-metric';

    abstract public function calculate(MetricRequest $request): PartitionResult;

    /**
     * Count rows per $groupBy. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function count(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $groupBy): PartitionResult
    {
        return $this->aggregate($request, $model, 'count', null, $groupBy);
    }

    /**
     * Sum a column per $groupBy. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function sum(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, string $groupBy): PartitionResult
    {
        return $this->aggregate($request, $model, 'sum', $column, $groupBy);
    }

    /**
     * Average a column per $groupBy. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function average(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, string $groupBy): PartitionResult
    {
        return $this->aggregate($request, $model, 'avg', $column, $groupBy);
    }

    /**
     * Smallest value of a column per $groupBy. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function min(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, string $groupBy): PartitionResult
    {
        return $this->aggregate($request, $model, 'min', $column, $groupBy);
    }

    /**
     * Largest value of a column per $groupBy. $model: Entity class or EntitySource (scoped), model class or builder (UNSCOPED).
     *
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    protected function max(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $column, string $groupBy): PartitionResult
    {
        return $this->aggregate($request, $model, 'max', $column, $groupBy);
    }

    /**
     * @param  class-string|EntitySource|Builder|BaseBuilder  $model
     */
    private function aggregate(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $model, string $function, ?string $column, string $groupBy): PartitionResult
    {
        $source = Source::make($request, $model);
        $group = $source->qualify($groupBy);

        $expression = $function === 'count' ? 'COUNT(*)' : strtoupper($function).'('.$source->wrap($source->qualify((string) $column)).')';

        $rows = $source->query()
            ->selectRaw($source->wrap($group).' as larsadmin_key, '.$expression.' as larsadmin_aggregate')
            ->groupBy($group)
            ->orderByDesc('larsadmin_aggregate')
            ->orderBy($group)
            ->get();

        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row->larsadmin_key] = MetricResult::number($row->larsadmin_aggregate) ?? 0;
        }

        return new PartitionResult($values, in_array($function, ['count', 'sum'], true));
    }
}
