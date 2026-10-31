<?php

namespace Maxcxam\Larsadmin\Query;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Filters\Filter;

/**
 * @internal Not part of the public API.
 */
final class EntityQuery
{
    public const int MAX_SEARCH_LENGTH = 100;

    /** @var array<string, Filter>|null */
    private ?array $filters = null;

    /** @var array<string, mixed> */
    private array $activeFilters = [];

    /** @var array{search: string, sort: ?string, direction: string, perPage: int, page: int, defaultSort: array{attribute: string, direction: string}|null}|null */
    private ?array $params = null;

    /**
     * @param  class-string<Entity>  $entity
     */
    private function __construct(
        private readonly string $entity,
        private readonly Request $request,
        private Builder $query,
    ) {}

    /**
     * @param  class-string<Entity>  $entity
     */
    public static function for(string $entity, Request $request, ?Builder $base = null): self
    {
        return new self($entity, $request, $base ?? $entity::newModel()->newQuery());
    }

    /**
     * Start a listing query and parse its query-string parameters (`search`, `sort`,
     * `direction`, `perPage`, `page`) with a single policy: unknown or malformed values fall
     * back to the defaults, and `perPage` must be one of the entity's options or the default.
     * The parsed values are exposed by params() and applied by applyRequest().
     *
     * @param  class-string<Entity>  $entity
     * @param  int|null  $defaultPerPage  the page size when none (or an invalid one) is requested; the entity's by default
     */
    public static function fromRequest(Request $request, string $entity, ?int $defaultPerPage = null, ?Builder $base = null): self
    {
        $query = self::for($entity, $request, $base);
        $default = $defaultPerPage ?? $entity::$perPage;

        $search = $request->query('search');
        $search = is_string($search) ? mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH) : '';

        $sort = $request->query('sort');
        $sortable = $entity::make()->fieldPrototypes($request)
            ->filter(fn (Field $field) => $field->isShownOn(Field::INDEX, $request) && $field->sortable)
            ->pluck('attribute')
            ->all();
        $sort = is_string($sort) && in_array($sort, $sortable, true) ? $sort : null;

        $perPage = self::intQuery($request, 'perPage');

        $query->params = [
            'search' => $search,
            'sort' => $sort,
            'direction' => $request->query('direction') === 'asc' ? 'asc' : 'desc',
            'perPage' => in_array($perPage, [...$entity::$perPageOptions, $default], true) ? $perPage : $default,
            'page' => self::intQuery($request, 'page') ?: 1,
            'defaultSort' => self::defaultSortFor($entity, $request)[0] ?? null,
        ];

        return $query;
    }

    /**
     * The entity's validated default ordering ($sort), as attribute/direction pairs.
     *
     * @param  class-string<Entity>  $entity
     * @return array<int, array{attribute: string, direction: string}>
     *
     * @throws InvalidArgumentException when an entry is not a sortable field or has a bad direction
     */
    public static function defaultSortFor(string $entity, Request $request): array
    {
        $order = [];

        foreach ($entity::$sort as $attribute => $direction) {
            $direction = is_string($direction) ? strtolower($direction) : $direction;

            if (! is_string($attribute) || ! in_array($direction, ['asc', 'desc'], true)) {
                throw new InvalidArgumentException(
                    "Invalid default sort in [{$entity}::\$sort]: use ['attribute' => 'asc'|'desc'] pairs."
                );
            }

            $field = $entity::make()->fieldPrototypes($request)
                ->first(fn (Field $field) => $field->sortable && $field->attribute === $attribute && $field->isAuthorizedToSee($request));

            if ($field === null) {
                throw new InvalidArgumentException(
                    "Invalid default sort in [{$entity}::\$sort]: [{$attribute}] is not a sortable field; declare it with ->sortable()."
                );
            }

            $order[] = ['attribute' => $attribute, 'direction' => $direction];
        }

        return $order;
    }

    /**
     * The values parsed by fromRequest().
     *
     * @return array{search: string, sort: ?string, direction: string, perPage: int, page: int, defaultSort: array{attribute: string, direction: string}|null}
     */
    public function params(): array
    {
        return $this->params ?? throw new LogicException('The query was not created with fromRequest().');
    }

    /**
     * Apply the parsed search term and sort order.
     */
    public function applyRequest(): self
    {
        $params = $this->params();

        return $this->search($params['search'])->sort($params['sort'], $params['direction']);
    }

    /**
     * Read a positive integer query parameter, or null when absent or malformed.
     */
    private static function intQuery(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_string($value) && ctype_digit($value) && strlen($value) <= 9 ? (int) $value : null;
    }

    /**
     * Apply the entity's row scope: "index", "detail" or "relatable".
     */
    public function scope(string $scope = 'index'): self
    {
        $this->query = match ($scope) {
            'index' => $this->entity::indexQuery($this->request, $this->query),
            'detail' => $this->entity::detailQuery($this->request, $this->query),
            'relatable' => $this->entity::relatableQuery($this->request, $this->query),
            default => throw new InvalidArgumentException("Unknown query scope [{$scope}]."),
        };

        return $this;
    }

    /**
     * Apply the query-string filters (`filters[key]=value`). Unknown keys, filters the
     * user may not see and invalid values are ignored; a key absent from the query
     * string falls back to the filter's default, an empty value clears it. Each filter
     * is applied in its own where-group so it cannot escape the entity's row scope.
     */
    public function filter(mixed $raw = null): self
    {
        $raw ??= $this->request->query('filters');
        $raw = is_array($raw) ? $raw : [];

        foreach ($this->filters() as $key => $filter) {
            $value = $filter->normalize(
                $this->request,
                array_key_exists($key, $raw) ? $raw[$key] : $filter->default(),
            );

            if ($value === null) {
                continue;
            }

            $this->activeFilters[$key] = $value;
            $this->query->where(fn (Builder $group) => $filter->apply($this->request, $group, $value));
        }

        return $this;
    }

    /**
     * The authorized filters of the entity, keyed by filter key.
     *
     * @return array<string, Filter>
     */
    public function filters(): array
    {
        if ($this->filters === null) {
            $this->filters = [];

            foreach ($this->entity::make()->filters($this->request) as $filter) {
                if ($filter instanceof Filter && $filter->authorizedToSee($this->request)) {
                    $this->filters[$filter->key()] ??= $filter;
                }
            }
        }

        return $this->filters;
    }

    /**
     * The validated values applied by filter(), keyed by filter key.
     *
     * @return array<string, mixed>
     */
    public function activeFilters(): array
    {
        return $this->activeFilters;
    }

    /**
     * The `filters` prop: every authorized filter with its current value.
     *
     * @return list<array<string, mixed>>
     */
    public function serializeFilters(): array
    {
        return collect($this->filters())
            ->map(fn (Filter $filter, string $key) => $filter->serialize($this->request, $this->activeFilters[$key] ?? null))
            ->values()
            ->all();
    }

    /**
     * Search the entity's searchable columns. The term is trimmed and capped.
     */
    public function search(?string $term): self
    {
        $term = mb_substr(trim((string) $term), 0, self::MAX_SEARCH_LENGTH);

        if ($term !== '' && $this->entity::searchable()) {
            $this->entity::applySearch($this->request, $this->query, $term);
        }

        return $this;
    }

    /**
     * Order by a sortable index field (or the entity's default sort when none is valid), always followed by the key as a tiebreaker.
     * Unknown or non-sortable attributes are ignored (key order only).
     */
    public function sort(?string $attribute, string $direction): self
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $field = $attribute === null ? null : $this->entity::make()->fieldPrototypes($this->request)
            ->first(fn (Field $field) => $field->isShownOn(Field::INDEX, $this->request) && $field->sortable && $field->attribute === $attribute);

        if ($field !== null) {
            $field->applySort($this->query, $direction);
        } else {
            // No valid requested sort: apply the entity's default ordering.
            foreach (self::defaultSortFor($this->entity, $this->request) as $default) {
                $this->entity::make()->fieldPrototypes($this->request)
                    ->first(fn (Field $candidate) => $candidate->attribute === $default['attribute'])
                    ->applySort($this->query, $default['direction']);
            }
        }

        $this->query->orderBy($this->query->getModel()->getQualifiedKeyName(), 'desc');

        return $this;
    }

    /**
     * Eager load the entity's relations plus those required by its fields.
     */
    public function withEagerLoads(string $context): self
    {
        $this->query->with(array_values(array_unique([
            ...$this->entity::$with,
            ...$this->entity::eagerLoadsFor($this->request, $context),
        ])));

        return $this;
    }

    /**
     * Constrain to the records of a HasMany / BelongsToMany relation of a parent,
     * keeping the relation's own constraints (e.g. wherePivot).
     */
    public function constrainTo(Relation $relation): self
    {
        $related = $relation->getRelated();

        $this->query->whereIn(
            $this->query->getModel()->getQualifiedKeyName(),
            $relation->getQuery()->reorder()->select($related->getQualifiedKeyName())->toBase(),
        );

        return $this;
    }

    /**
     * Resolve one record in the current scope by its route key. A malformed
     * key for an integer-keyed model is a 404, never a query; the 404 never names the model class.
     */
    public function findOrFail(string $id): Model
    {
        $model = $this->query->getModel();
        $integerKey = $model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true);

        if ($integerKey && (! ctype_digit($id) || strlen($id) > 18)) {
            abort(404);
        }

        return $this->query->where($model->getQualifiedKeyName(), $integerKey ? (int) $id : $id)->first() ?? abort(404);
    }

    public function builder(): Builder
    {
        return $this->query;
    }

    public function paginate(int $perPage, ?int $page = null): LengthAwarePaginator
    {
        return $this->query->paginate($perPage, page: $page);
    }
}
