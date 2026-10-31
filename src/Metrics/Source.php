<?php

namespace Maxcxam\Larsadmin\Metrics;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Query\EntityQuery;

/**
 * The rows a metric aggregates, with the helpers to constrain and qualify them.
 *
 * Scoping (ADR-013): an Entity class is queried through its `indexQuery()` and requires `viewAny`;
 * an EntitySource is scoped the same way, then narrowed by its constraint (nested, so an orWhere
 * cannot escape the scoping); a model class, an Eloquent builder or a query builder is NOT restricted.
 *
 * @internal Not part of the public API.
 */
final class Source
{
    private function __construct(
        private readonly BaseBuilder $query,
        private readonly ?Model $model,
        private readonly string $dateFormat,
    ) {}

    /**
     * @param  class-string<Entity>|class-string<Model>|EntitySource|Builder|BaseBuilder  $source
     *
     * @throws AuthorizationException when an Entity class or EntitySource is given and the user may not view it
     */
    public static function make(MetricRequest $request, string|EntitySource|Builder|BaseBuilder $source): self
    {
        if ($source instanceof EntitySource) {
            $constraint = $source->constraint;
            $source = self::entityQuery($request, $source->entity)->where(fn (Builder $query) => $constraint($query));
        } elseif (is_string($source)) {
            if (is_subclass_of($source, Entity::class)) {
                $source = self::entityQuery($request, $source);
            } elseif (is_subclass_of($source, Model::class)) {
                $source = $source::query();
            } else {
                throw new InvalidArgumentException("Metrics need an Entity class, a Model class or a query builder; got [{$source}].");
            }
        }

        $model = $source instanceof Builder ? $source->getModel() : null;

        // toBase() applies the model's global scopes (e.g. soft deletes); reset what a listing query may add.
        $base = $source instanceof Builder ? $source->toBase() : clone $source;
        $base->columns = null;
        $base->reorder();

        return new self($base, $model, $model?->getDateFormat() ?? $base->getGrammar()->getDateFormat());
    }

    /**
     * The index-scoped query of an Entity, after checking viewAny.
     *
     * @param  class-string<Entity>  $entity
     *
     * @throws AuthorizationException when the user may not view the entity
     */
    private static function entityQuery(MetricRequest $request, string $entity): Builder
    {
        $request->useEntity($entity);

        if (! $entity::authorizedToViewAny($request->request)) {
            throw new AuthorizationException;
        }

        return EntityQuery::for($entity, $request->request)->scope('index')->builder();
    }

    /**
     * A fresh copy of the constrained query.
     */
    public function query(): BaseBuilder
    {
        return clone $this->query;
    }

    /**
     * Qualify a column with the table of the model (when there is one).
     */
    public function qualify(string $column): string
    {
        return $this->model?->qualifyColumn($column) ?? $column;
    }

    /**
     * The qualified date column: the given one, else the model's created-at column.
     */
    public function dateColumn(?string $column): string
    {
        $column ??= $this->model?->getCreatedAtColumn();

        if ($column === null) {
            throw new InvalidArgumentException('This metric source has no created-at column; pass the date column explicitly.');
        }

        return $this->qualify($column);
    }

    /**
     * Wrap an (already qualified) column for use in raw SQL.
     */
    public function wrap(string $column): string
    {
        return $this->query->getGrammar()->wrap($column);
    }

    /**
     * A date as stored: converted to the application timezone and formatted like the model's dates.
     */
    public function date(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone((string) config('app.timezone'))->format($this->dateFormat);
    }

    /**
     * A fresh query limited to [start, end) of the date column (half-open, like the trend buckets, so
     * sub-second timestamps never fall between two periods); unconstrained when $bounds is null.
     *
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $bounds
     */
    public function between(?string $dateColumn, ?array $bounds): BaseBuilder
    {
        $query = $this->query();

        if ($bounds !== null) {
            $column = $this->dateColumn($dateColumn);

            $query->where($column, '>=', $this->date($bounds[0]))->where($column, '<', $this->date($bounds[1]));
        }

        return $query;
    }

    /**
     * Whether the model casts the (optionally qualified) column to a boolean.
     */
    public function castsToBoolean(string $column): bool
    {
        return $this->model?->hasCast(Str::afterLast($column, '.'), ['bool', 'boolean']) ?? false;
    }
}
