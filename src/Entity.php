<?php

namespace Maxcxam\Larsadmin;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Filters\Filter;
use Maxcxam\Larsadmin\Support\Like;

abstract class Entity
{
    /**
     * The model the entity corresponds to.
     *
     * @var class-string<Model>
     */
    public static string $model;

    /**
     * The attribute used as the record's display title.
     */
    public static string $title = 'id';

    /**
     * The columns that should be searched.
     *
     * @var array<int, string>
     */
    public static array $search = ['id'];

    /**
     * The relationships eager loaded on the index and detail queries.
     *
     * @var array<int, string>
     */
    public static array $with = [];

    /**
     * The default ordering applied when the request has no valid sort, e.g. ['published_at' => 'desc'].
     * Every attribute must belong to a sortable field.
     *
     * @var array<string, string>
     */
    public static array $sort = [];

    public static int $perPage = 25;

    /**
     * @var array<int, int>
     */
    public static array $perPageOptions = [25, 50, 100];

    /**
     * The navigation group the entity is listed under.
     */
    public static ?string $group = null;

    public static bool $displayInNavigation = true;

    /**
     * @var Collection<int, Field>|null
     */
    protected ?Collection $fieldPrototypes = null;

    public function __construct(public Model $record) {}

    /**
     * Get the fields displayed by the entity.
     *
     * @return array<int, Field>
     */
    abstract public function fields(Request $request): array;

    /**
     * Create an entity instance for the given record, or a fresh model.
     */
    public static function make(?Model $record = null): static
    {
        return new static($record ?? static::newModel());
    }

    public static function newModel(): Model
    {
        return new static::$model;
    }

    /**
     * Get the URI key of the entity.
     */
    public static function uriKey(): string
    {
        return Str::plural(Str::kebab(class_basename(static::class)));
    }

    /**
     * The plural label of the entity (translated via __()); derived from the class name when null.
     * The derived fallback is English: set $label / $singularLabel explicitly for other locales.
     */
    public static ?string $label = null;

    /**
     * The singular label of the entity (translated via __()); derived from the class name when null
     * (an English fallback: set it explicitly for other locales, e.g. uk).
     */
    public static ?string $singularLabel = null;

    public static function label(): string
    {
        return static::$label !== null ? __(static::$label) : Str::plural(static::singularLabel());
    }

    public static function singularLabel(): string
    {
        if (static::$singularLabel !== null) {
            return __(static::$singularLabel);
        }

        return Str::title(Str::snake(class_basename(static::class), ' '));
    }

    /**
     * Customize the query used to list the entity's records.
     */
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query;
    }

    /**
     * Customize the query used to resolve a single record (show, edit, update, delete).
     *
     * Defaults to the index query so row scoping stays consistent: indexQuery()
     * must mirror which rows a user may see. Override to loosen or tighten it.
     */
    public static function detailQuery(Request $request, Builder $query): Builder
    {
        return static::indexQuery($request, $query);
    }

    /**
     * Customize the query of records that may be picked in a relation field
     * (BelongsTo options and validation). Defaults to the index query.
     */
    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return static::indexQuery($request, $query);
    }

    /**
     * Get the actions available on the entity's index and detail pages.
     *
     * @return array<int, Action>
     */
    public function actions(Request $request): array
    {
        return [];
    }

    /**
     * Get the filters available on the entity's index and panels.
     *
     * @return array<int, Filter>
     */
    public function filters(Request $request): array
    {
        return [];
    }

    public static function searchable(): bool
    {
        return static::$search !== [];
    }

    /**
     * Get the display title of the record.
     */
    public function title(): string
    {
        return (string) data_get($this->record, static::$title);
    }

    /**
     * The unresolved fields, built once per request and entity class (cached on the request).
     * Resolution always works on clones, so the prototypes are never mutated.
     *
     * @return Collection<int, Field>
     */
    public function fieldPrototypes(Request $request): Collection
    {
        if ($this->fieldPrototypes !== null) {
            return $this->fieldPrototypes;
        }

        // Shared by every instance of the entity within the request (fields() must not depend on the record).
        $name = 'larsadmin.field_prototypes.'.static::class;
        $shared = $request->attributes->get($name);

        if (! $shared instanceof Collection) {
            $shared = collect($this->fields($request))
                ->each(fn (Field $field) => $field->bindTo($request, static::class))
                ->values();

            $request->attributes->set($name, $shared);
        }

        return $this->fieldPrototypes = $shared;
    }

    /**
     * Get the fields shown in the given context, cloned and resolved against the record.
     *
     * @return Collection<int, Field>
     */
    public function resolveFields(Request $request, string $context): Collection
    {
        return $this->fieldPrototypes($request)
            ->filter(fn (Field $field) => $field->isShownOn($context, $request, $this->record))
            ->map(fn (Field $field) => clone $field)
            ->each(function (Field $field) use ($request, $context) {
                $field->context = $context;
                $field->readonly = $field->isReadonlyFor($request, $this->record);

                $context === Field::CREATE ? $field->resolveDefault($request) : $field->resolve($this->record);
            })
            ->values();
    }

    /**
     * Get the index columns, built from unresolved prototypes.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function indexColumns(Request $request): array
    {
        return static::make()->fieldPrototypes($request)
            ->filter(fn (Field $field) => $field->isShownOn(Field::INDEX, $request))
            ->map(fn (Field $field) => $field->columnDefinition())
            ->values()
            ->all();
    }

    /**
     * The relations the fields shown in the context need eager loaded.
     *
     * @return array<int, string>
     */
    public static function eagerLoadsFor(Request $request, string $context): array
    {
        return static::make()->fieldPrototypes($request)
            ->filter(fn (Field $field) => $field->mayShowOn($context, $request))
            ->flatMap(fn (Field $field) => $field->eagerLoads())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Constrain the query to records matching the term.
     *
     * Entries of $search are columns (LIKE), the key name (equality), or
     * "relation.column" (one level, via orWhereHas).
     */
    public static function applySearch(Request $request, Builder $query, string $term): void
    {
        $model = $query->getModel();
        $integerKey = $model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true);

        $unseen = static::make()->fieldPrototypes($request)
            ->reject(fn (Field $field) => $field->isAuthorizedToSee($request))
            ->map(fn (Field $field) => $field->attribute)
            ->all();

        $query->where(function (Builder $query) use ($request, $model, $integerKey, $term, $unseen) {
            foreach (static::$search as $column) {
                if ($unseen !== [] && in_array(explode('.', $column, 2)[0], $unseen, true)) {
                    continue;
                }

                if ($column === $model->getKeyName()) {
                    if (! $integerKey) {
                        $query->orWhere($model->getQualifiedKeyName(), $term);
                    } elseif (ctype_digit($term) && strlen($term) <= 18) {
                        $query->orWhere($model->getQualifiedKeyName(), (int) $term);
                    }
                } elseif (str_contains($column, '.')) {
                    [$relation, $related] = explode('.', $column, 2);

                    // A relation mapped to a registered entity is searched only within that entity's index scope.
                    $relatedEntity = Larsadmin::entityForModel($model->{$relation}()->getRelated()::class);

                    $query->orWhereHas($relation, function (Builder $query) use ($request, $relatedEntity, $related, $term) {
                        if ($relatedEntity !== null) {
                            $query->where(fn (Builder $scope) => $relatedEntity::indexQuery($request, $scope));
                        }

                        Like::where($query, $query->getModel()->qualifyColumn($related), $term, 'and');
                    });
                } else {
                    Like::where($query, $model->qualifyColumn($column), $term);
                }
            }
        });
    }

    /**
     * Get the validation rules for the given form context.
     */
    public function rules(Request $request, string $context): array
    {
        return $this->editableFields($request, $context)
            ->flatMap(fn (Field $field) => $field->rulesFor($context, $this->record))
            ->all();
    }

    /**
     * Get the validation attribute names for the given form context.
     */
    public function validationAttributes(Request $request, string $context): array
    {
        return $this->editableFields($request, $context)
            ->mapWithKeys(fn (Field $field) => [$field->attribute => $field->name])
            ->all();
    }

    /**
     * Fill the record's attributes from the request, without saving.
     * After-save callbacks are discarded; use save() to run them.
     *
     * @internal Not part of the public API; use save().
     */
    public function fill(Request $request, string $context): Model
    {
        $this->editableFields($request, $context)->each(fn (Field $field) => $field->fill($request, $this->record));

        return $this->record;
    }

    /**
     * Fill and save the record, then run the fields' after-save callbacks, in one transaction.
     */
    public function save(Request $request, string $context): Model
    {
        $callbacks = $this->editableFields($request, $context)
            ->map(fn (Field $field) => $field->fill($request, $this->record))
            ->filter()
            ->values();

        return $this->record->getConnection()->transaction(function () use ($callbacks) {
            $this->record->save();
            $callbacks->each(fn (Closure $callback) => $callback($this->record));

            return $this->record;
        });
    }

    /**
     * @return Collection<int, Field>
     */
    protected function editableFields(Request $request, string $context): Collection
    {
        return $this->fieldPrototypes($request)
            ->filter(fn (Field $field) => $field->isShownOn($context, $request, $this->record)
                && ! $field->isReadonlyFor($request, $this->record)
                && ! $field->isRelation())
            ->values();
    }

    public static function authorizedToViewAny(Request $request): bool
    {
        return static::allows('viewAny', static::$model);
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return static::allows('create', static::$model);
    }

    public function authorizedToView(Request $request): bool
    {
        return static::allows('view', $this->record);
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return static::allows('update', $this->record);
    }

    public function authorizedToDelete(Request $request): bool
    {
        return static::allows('delete', $this->record);
    }

    /**
     * Check a policy ability. When the model has a policy the decision is
     * delegated entirely to the gate (so before() hooks apply and a missing
     * method means denied). Without a policy the
     * "larsadmin.allow_without_policy" config decides.
     */
    protected static function allows(string $ability, mixed $argument): bool
    {
        if (Gate::getPolicyFor(static::$model) === null) {
            return (bool) config('larsadmin.allow_without_policy', true);
        }

        return Gate::check($ability, $argument);
    }

    /**
     * Get the entity information shared with every entity page.
     */
    public static function serializeMeta(Request $request): array
    {
        return [
            'uriKey' => static::uriKey(),
            'label' => static::label(),
            'singularLabel' => static::singularLabel(),
            'searchable' => static::searchable(),
            'perPageOptions' => static::$perPageOptions,
            'authorizedToCreate' => static::authorizedToCreate($request),
        ];
    }

    /**
     * Serialize the record for the given context.
     */
    public function serializeRecord(Request $request, string $context): array
    {
        $view = $this->authorizedToView($request);
        $update = $this->authorizedToUpdate($request);
        $delete = $this->authorizedToDelete($request);

        return [
            'id' => $this->record->getKey(),
            'title' => $this->title(),
            'fields' => $this->resolveFields($request, $context),
            'authorizedToView' => $view,
            'authorizedToUpdate' => $update,
            'authorizedToDelete' => $delete,
            'urls' => [
                'show' => $view ? $this->url('show') : null,
                'edit' => $update ? $this->url('edit') : null,
                'destroy' => $delete ? $this->url('destroy') : null,
            ],
        ];
    }

    /**
     * A panel-relative URL of the record (route "larsadmin.entities.{name}"), or null when the
     * key cannot be put in a path segment safely.
     *
     * @param  array<string, mixed>  $query  extra query-string parameters
     */
    public function url(string $name, array $query = []): ?string
    {
        $key = static::pathSegment($this->record->getKey());

        return $key === null ? null : route("larsadmin.entities.{$name}", [static::uriKey(), $key, ...$query], false);
    }

    /**
     * A record key is safe in a URL path only when it is a plain segment: route() leaves
     * "/" unencoded, so keys such as "../users/1" would traverse.
     */
    public static function pathSegment(mixed $key): string|int|null
    {
        if (is_int($key)) {
            return $key;
        }

        if (! is_string($key) || $key === '' || $key === '.' || $key === '..' || preg_match('/[\/\\\\\x00-\x1F\x7F]/', $key) === 1) {
            return null;
        }

        return $key;
    }
}
