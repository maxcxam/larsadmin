<?php

namespace Maxcxam\Larsadmin\Fields;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo as BelongsToRelation;
use Illuminate\Http\Request;
use LogicException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Concerns\HasRelatableOptions;
use Maxcxam\Larsadmin\Fields\Concerns\RelatedVisibility;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Maxcxam\Larsadmin\Rules\RelatableExists;

class BelongsTo extends Field
{
    use HasRelatableOptions;

    public string $component = 'belongs-to-field';

    public bool $locked = false;

    /**
     * The current related record as {id, title}, for form labels.
     *
     * @var array{id: int|string, title: string|null, hidden?: bool}|null
     */
    public ?array $selected = null;

    public ?string $link = null;

    /**
     * The related record exists but is outside the related entity's detailQuery for this request.
     */
    public bool $hidden = false;

    protected ?Model $pendingRelated = null;

    protected ?string $relatedEntity;

    /**
     * @var class-string<Entity>|null
     */
    protected ?string $parentEntity = null;

    /**
     * @param  class-string<Entity>|null  $entity  the related entity; resolved from the relation's model when null
     */
    public function __construct(string $name, ?string $attribute = null, ?string $entity = null)
    {
        parent::__construct($name, $attribute);

        $this->relatedEntity = $entity;
    }

    public function bindTo(Request $request, string $entity): void
    {
        $this->request = $request;
        $this->parentEntity = $entity;
    }

    /**
     * The relation on the parent model; it must be a BelongsTo.
     */
    public function relation(?Model $model = null): BelongsToRelation
    {
        if ($model === null) {
            if ($this->parentEntity === null) {
                throw new LogicException("The BelongsTo field [{$this->attribute}] is not attached to an entity.");
            }

            $model = $this->parentEntity::newModel();
        }

        $relation = method_exists($model, $this->attribute) ? $model->{$this->attribute}() : null;

        if (! $relation instanceof BelongsToRelation) {
            throw new LogicException(sprintf('[%s::%s()] must return a BelongsTo relation.', $model::class, $this->attribute));
        }

        return $relation;
    }

    /**
     * @return class-string<Entity>
     */
    public function relatedEntity(): string
    {
        return $this->relatedEntity ??= Larsadmin::entityForModel($this->relation()->getRelated()::class)
            ?? throw new LogicException("No registered entity found for the related model of [{$this->attribute}]; pass the entity class explicitly.");
    }

    protected function relatedModel(): Model
    {
        return $this->relation()->getRelated();
    }

    public function fillAttribute(): string
    {
        return $this->relation()->getForeignKeyName();
    }

    public function eagerLoads(): array
    {
        return [$this->attribute];
    }

    /**
     * The related record requested through the via* query parameters, when they are valid (ADR-008).
     * Invalid parameters are ignored; they never authorize anything on their own.
     */
    public function viaRecord(Request $request, ?Model $record = null): ?Model
    {
        $entity = $request->query('viaEntity');
        $id = $request->query('viaId');
        $field = $request->query('viaField');

        if (! is_string($entity) || ! is_string($id) || ! is_string($field) || $field !== $this->attribute) {
            return null;
        }

        $related = $this->relatedEntity();

        if (Larsadmin::entityForKey($entity) !== $related) {
            return null;
        }

        return $this->findRelatable($request, $id, $record);
    }

    public function resolve(Model $record): static
    {
        $key = $record->getAttribute($this->fillAttribute());
        $this->value = $key;
        $this->selected = null;
        $this->displayedAs = null;
        $this->link = null;
        $this->hidden = false;
        $this->pendingRelated = null;

        if ($key === null) {
            return $this;
        }

        $related = $record->relationLoaded($this->attribute) ? $record->getRelation($this->attribute) : $record->{$this->attribute};

        if (! $related instanceof Model) {
            return $this;
        }

        // Visibility is decided later, for every row at once (see settle()).
        $this->pendingRelated = $related;
        $this->visibility()->register($related->getKey());

        return $this;
    }

    /**
     * Settle the display state of the resolved related record: its title and link when it
     * is visible through the related entity's detailQuery, a hidden marker otherwise.
     * Deferred to serialization so one query covers all the rows that were resolved.
     */
    protected function settle(): void
    {
        $related = $this->pendingRelated;

        if ($related === null) {
            return;
        }

        $this->pendingRelated = null;

        if (! $this->visibility()->isVisible($related->getKey())) {
            $this->hidden = true;
            $this->selected = ['id' => $related->getKey(), 'title' => null, 'hidden' => true];

            return;
        }

        $entity = $this->relatedEntity();
        $instance = $entity::make($related);
        $title = $instance->title();

        $this->displayedAs = $title;
        $this->selected = ['id' => $related->getKey(), 'title' => $title];

        if (
            $this->context !== self::CREATE && $this->context !== self::UPDATE
            && $this->request !== null
            && Larsadmin::entityForKey($entity::uriKey()) === $entity
            && $instance->authorizedToView($this->request)
        ) {
            $this->link = route('larsadmin.entities.show', [$entity::uriKey(), $related->getKey()], false);
        }
    }

    /**
     * The request-scoped visibility tracker of this field's related records.
     */
    protected function visibility(): RelatedVisibility
    {
        $request = $this->request ?? request();
        $name = 'larsadmin.related_visibility.'.($this->parentEntity ?? '').'.'.$this->attribute;

        if (! $request->attributes->get($name) instanceof RelatedVisibility) {
            $request->attributes->set($name, new RelatedVisibility(function (array $keys) use ($request): array {
                $model = $this->relatedModel();

                return EntityQuery::for($this->relatedEntity(), $request)
                    ->scope('detail')
                    ->builder()
                    ->whereKey($keys)
                    ->pluck($model->getQualifiedKeyName())
                    ->all();
            }));
        }

        return $request->attributes->get($name);
    }

    public function resolveDefault(Request $request): static
    {
        parent::resolveDefault($request);

        $this->selected = null;
        $this->hidden = false;
        $this->pendingRelated = null;
        $this->locked = false;

        $via = $this->viaRecord($request);
        $related = $via ?? ($this->value !== null ? $this->findRelatable($request, $this->value) : null);

        if ($related === null) {
            $this->value = null;

            return $this;
        }

        $this->value = $related->getKey();
        $this->locked = $via !== null;
        $this->selected = ['id' => $related->getKey(), 'title' => $this->relatedEntity()::make($related)->title()];

        return $this;
    }

    public function fill(Request $request, Model $record): ?Closure
    {
        if ($this->fillCallback) {
            return parent::fill($request, $record);
        }

        if ($request->exists($this->attribute)) {
            $value = $request->input($this->attribute);

            $record->setAttribute($this->fillAttribute(), is_scalar($value) && $value !== '' ? $value : null);
            $record->unsetRelation($this->attribute);
        }

        return null;
    }

    public function applySort(Builder $query, string $direction): void
    {
        $relation = $this->relation($query->getModel());
        $related = $relation->getRelated();
        $title = $this->relatedEntity()::$title;

        $query->orderBy(
            $related->newQuery()
                ->select($related->qualifyColumn($title))
                ->whereColumn($related->getQualifiedKeyName(), $relation->getQualifiedForeignKeyName())
                ->limit(1),
            $direction,
        );
    }

    public function rulesFor(string $context, ?Model $record = null): array
    {
        $rules = parent::rulesFor($context, $record);
        $request = $this->request ?? request();

        $rules[$this->attribute] = [
            $this->nullable ? 'nullable' : 'required',
            ...$rules[$this->attribute],
            new RelatableExists($this, $request, $record),
        ];

        return $rules;
    }

    public function jsonSerialize(): array
    {
        $this->settle();

        $related = $this->relatedEntity();

        return array_merge(parent::jsonSerialize(), [
            'selected' => $this->selected,
            'link' => $this->link,
            'hidden' => $this->hidden,
            'relatedEntity' => [
                'uriKey' => $related::uriKey(),
                'singularLabel' => $related::singularLabel(),
            ],
            'searchable' => $this->searchable,
            'optionsUrl' => $this->parentEntity
                ? route('larsadmin.api.options', [$this->parentEntity::uriKey(), $this->attribute], false)
                : null,
            'locked' => $this->locked,
        ]);
    }
}
