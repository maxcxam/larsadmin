<?php

namespace Maxcxam\Larsadmin\Search;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Maxcxam\Larsadmin\Support\Like;

/**
 * The SQL path of the global search: the entity's index search, scoped by indexQuery() (ADR-020).
 *
 * @internal
 */
class SqlCandidates
{
    /**
     * Up to $take records of the entity matching the term, prefix matches of the title first, then newest.
     *
     * @param  class-string<Entity>  $entity
     */
    public function get(string $entity, Request $request, string $term, int $take): Collection
    {
        if (! $entity::searchable()) {
            return new Collection;
        }

        if (GlobalSearch::isKeyLookup($term)) {
            return $this->byKey($entity, $request, $term, $take);
        }

        $scoped = EntityQuery::for($entity, $request)->scope('index');
        $constraints = count($scoped->builder()->getQuery()->wheres);

        $query = $scoped->search($term)->builder();

        // No searchable column applied (all hidden by canSee, or a non-numeric term for an integer key only):
        // an unconstrained query would match every row, so nothing matches.
        if (count($query->getQuery()->wheres) === $constraints) {
            return new Collection;
        }

        $model = $query->getModel();
        $query->with(ResultText::eagerLoads($entity))->reorder();

        $title = $entity::$title;
        $base = $query->getQuery();

        // A DISTINCT or grouped scope cannot be ordered by an expression outside the select list (pgsql, mysql).
        if (in_array($title, $entity::$search, true)
            && ! str_contains($title, '.')
            && $title !== $model->getKeyName()
            && ! $base->distinct
            && empty($base->groups)
            && ! in_array($title, $entity::unseenAttributes($request), true)) {
            Like::orderByPrefix($query, $model->qualifyColumn($title), $term);
        }

        return $query->orderBy($model->getQualifiedKeyName(), 'desc')->limit($take)->get();
    }

    /**
     * A digits-only term shorter than the minimum length matches the key only (an ID lookup), when the key
     * is a $search column the user may see.
     *
     * @param  class-string<Entity>  $entity
     */
    protected function byKey(string $entity, Request $request, string $term, int $take): Collection
    {
        $query = EntityQuery::for($entity, $request)->scope('index')->builder();
        $model = $query->getModel();
        $key = $model->getKeyName();

        if (! in_array($key, $entity::$search, true) || in_array($key, $entity::unseenAttributes($request), true)) {
            return new Collection;
        }

        $integerKey = $model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true);

        return $query->with(ResultText::eagerLoads($entity))
            ->where($model->getQualifiedKeyName(), $integerKey ? (int) $term : $term)
            ->limit($take)
            ->get();
    }
}
