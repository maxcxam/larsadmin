<?php

namespace Maxcxam\Larsadmin\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as BaseCollection;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Query\EntityQuery;

/**
 * The Scout path of the global search: the engine only proposes ranked keys, the rows are always
 * loaded through indexQuery() (ADR-021). Scout is referenced dynamically: it is not a dependency.
 *
 * @internal
 */
class ScoutCandidates
{
    /**
     * The upper bound of "larsadmin.search.scout_candidates" (SECS-6).
     */
    public const int MAX_CANDIDATES = 500;

    /**
     * Up to $take records of the entity matching the term, in the engine's ranking order.
     *
     * @param  class-string<Entity>  $entity
     */
    public function get(string $entity, Request $request, string $term, int $take): Collection
    {
        $candidates = max(min(self::MAX_CANDIDATES, max(1, (int) config('larsadmin.search.scout_candidates', 50))), $take);
        $model = $entity::$model;

        // Keys are compared as strings and strictly: " 4" must not swallow "4" (CRS-03).
        $keys = collect($entity::scoutQuery($request, $model::search($term))->take($candidates)->keys())
            ->map(fn ($key) => (string) $key)
            ->uniqueStrict()
            ->values();

        $instance = $entity::newModel();

        // Keys an integer key column cannot hold are dropped rather than sent to the database.
        $values = $instance->getIncrementing() && in_array($instance->getKeyType(), ['int', 'integer'], true)
            ? $keys->filter(fn (string $key) => ctype_digit($key) && strlen($key) <= 18)->map(fn (string $key) => (int) $key)
            : $keys;

        if ($values->isEmpty()) {
            return new Collection;
        }

        $rank = $keys->flip();
        $byRank = fn (BaseCollection $items, Closure $key) => $items
            ->sortBy(fn ($item) => $rank[(string) $key($item)] ?? PHP_INT_MAX)
            ->values();

        // First the candidates visible in the index scope, without eager loads; then only the top $take rows.
        $visible = $this->scoped($entity, $request)
            ->whereIn($instance->getQualifiedKeyName(), $values->unique()->values()->all())
            ->pluck($instance->getQualifiedKeyName());

        $top = $byRank($visible, fn ($key) => $key)->take($take);

        if ($top->isEmpty()) {
            return new Collection;
        }

        $rows = $this->scoped($entity, $request)
            ->with(ResultText::eagerLoads($entity))
            ->whereIn($instance->getQualifiedKeyName(), $top->all())
            ->get();

        return $byRank($rows, fn (Model $row) => $row->getKey());
    }

    /**
     * @param  class-string<Entity>  $entity
     */
    protected function scoped(string $entity, Request $request): Builder
    {
        return EntityQuery::for($entity, $request)->scope('index')->builder();
    }
}
