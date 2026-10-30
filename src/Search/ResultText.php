<?php

namespace Maxcxam\Larsadmin\Search;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;
use ReflectionMethod;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * The title and subtitle of global search results, with the visibility rules of the panel (SECS-1, SECS-2):
 * a declarative $subtitle (and a "relation.attribute" $title) whose field is hidden by canSee is not shown,
 * and a related record is shown only when the related entity is registered, the user may list it, the
 * attribute's field is visible there and the record is inside its detail scope. Overridden title() /
 * subtitle() methods own their visibility.
 *
 * @internal
 */
final class ResultText
{
    /**
     * Visible related keys (as array keys) per "relation.attribute" path.
     *
     * @var array<string, array<string, int>>
     */
    private array $visible = [];

    /** @var array<int, string>|null */
    private ?array $unseen = null;

    private readonly Model $model;

    /**
     * @param  class-string<Entity>  $entity
     * @param  Collection<int, Model>  $rows  the rows of the result set, checked together
     */
    public function __construct(
        private readonly string $entity,
        private readonly Request $request,
        private readonly Collection $rows,
    ) {
        $this->model = $entity::newModel();
    }

    /**
     * A display string for an attribute value: enums by value (backed) or name, Stringable and scalars
     * cast, anything else (arrays, collections, objects) null; an empty string is null.
     */
    public static function text(mixed $value): ?string
    {
        $text = match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Stringable, is_scalar($value) => (string) $value,
            default => null,
        };

        return $text === '' ? null : $text;
    }

    /**
     * The entity's $with plus the relation of a "relation.attribute" $title / $subtitle.
     *
     * @param  class-string<Entity>  $entity
     * @return array<int, string>
     */
    public static function eagerLoads(string $entity): array
    {
        $model = $entity::newModel();
        $with = $entity::$with;

        foreach ([$entity::$title, $entity::$subtitle] as $path) {
            if ($path !== null && substr_count($path, '.') === 1 && self::isRelation($model, $head = explode('.', $path)[0])) {
                $with[] = $head;
            }
        }

        return array_values(array_unique($with));
    }

    public function title(Entity $record): string
    {
        if (self::overrides($this->entity, 'title') || ! str_contains($this->entity::$title, '.')) {
            return $record->title();
        }

        return $this->resolve($record->record, $this->entity::$title) ?? '';
    }

    public function subtitle(Entity $record): ?string
    {
        if (self::overrides($this->entity, 'subtitle')) {
            return $record->subtitle();
        }

        return $this->entity::$subtitle === null ? null : $this->resolve($record->record, $this->entity::$subtitle);
    }

    private function resolve(Model $row, string $path): ?string
    {
        [$head, $rest] = array_pad(explode('.', $path, 2), 2, null);

        $this->unseen ??= $this->entity::unseenAttributes($this->request);

        if (in_array($head, $this->unseen, true)) {
            return null;
        }

        if ($rest === null || ! self::isRelation($this->model, $head)) {
            return self::text(data_get($row, $path));
        }

        // Deeper chains cannot be scoped level by level; an unloaded relation is not lazy loaded.
        if (str_contains($rest, '.') || ! $row->relationLoaded($head) || ! ($related = $row->getRelation($head)) instanceof Model) {
            return null;
        }

        $this->visible[$path] ??= $this->visibleKeys($head, $rest);

        return isset($this->visible[$path][(string) $related->getKey()]) ? self::text($related->getAttribute($rest)) : null;
    }

    /**
     * The keys of the related records of all rows that the user may see through the related entity,
     * in one query.
     *
     * @return array<string, int>
     */
    private function visibleKeys(string $relation, string $attribute): array
    {
        $related = Larsadmin::entityForModel($this->model->{$relation}()->getRelated()::class);

        $keys = $this->rows
            ->map(fn (Model $row) => $row->relationLoaded($relation) ? $row->getRelation($relation) : null)
            ->filter(fn ($model) => $model instanceof Model)
            ->map(fn (Model $model) => $model->getKey())
            ->unique()
            ->values();

        if ($related === null || $keys->isEmpty()) {
            return [];
        }

        try {
            if (! $related::authorizedToViewAny($this->request)
                || in_array($attribute, $related::unseenAttributes($this->request), true)) {
                return [];
            }

            $query = EntityQuery::for($related, $this->request)->scope('detail')->builder();

            return $query->whereKey($keys->all())
                ->pluck($query->getModel()->getQualifiedKeyName())
                ->mapWithKeys(fn ($key) => [(string) $key => 1])
                ->all();
        } catch (Throwable $e) {
            if (GlobalSearch::isRefusal($e)) {
                return [];
            }

            throw $e;
        }
    }

    private static function isRelation(Model $model, string $name): bool
    {
        try {
            return $model->isRelation($name) && $model->{$name}() instanceof Relation;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  class-string<Entity>  $entity
     */
    private static function overrides(string $entity, string $method): bool
    {
        return (new ReflectionMethod($entity, $method))->getDeclaringClass()->getName() !== Entity::class;
    }
}
