<?php

namespace Maxcxam\Larsadmin\Fields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * A relation shown as a panel on the detail page (HasMany, BelongsToMany).
 * It is never shown on index or forms, and never filled or validated.
 */
abstract class PanelField extends Field
{
    public bool $panel = true;

    protected int $perPage = 10;

    protected ?string $relatedEntity;

    /**
     * @var class-string<Entity>|null
     */
    protected ?string $parentEntity = null;

    protected ?Request $request = null;

    protected ?Model $parentRecord = null;

    /**
     * The relation class the model method must return.
     *
     * @return class-string<Relation>
     */
    abstract protected function relationClass(): string;

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

    public function isRelation(): bool
    {
        return true;
    }

    public function isShownOn(string $context, ?Request $request = null, ?Model $record = null): bool
    {
        return $context === self::DETAIL && parent::isShownOn($context, $request, $record);
    }

    /**
     * The default page size of the panel.
     */
    public function perPage(int $perPage): static
    {
        if ($perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('The panel page size must be between 1 and 100.');
        }

        $this->perPage = $perPage;

        return $this;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * The relation on the given parent model (or an empty instance of the parent entity's model).
     */
    public function relation(?Model $model = null): Relation
    {
        if ($model === null) {
            if ($this->parentEntity === null) {
                throw new LogicException("The relation field [{$this->attribute}] is not attached to an entity.");
            }

            $model = $this->parentEntity::newModel();
        }

        $relation = method_exists($model, $this->attribute) ? $model->{$this->attribute}() : null;

        if (! is_a($relation, $this->relationClass())) {
            throw new LogicException(sprintf('[%s::%s()] must return a %s relation.', $model::class, $this->attribute, class_basename($this->relationClass())));
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

    /**
     * Panels hold no value; only the parent record is remembered for the URLs.
     */
    public function resolve(Model $record): static
    {
        $this->parentRecord = $record;
        $this->value = null;
        $this->displayedAs = null;

        return $this;
    }

    public function resolveDefault(Request $request): static
    {
        return $this;
    }

    public function fill(Request $request, Model $record): ?Closure
    {
        return null;
    }

    /**
     * The URL of the related list endpoint for the resolved parent record.
     */
    protected function endpoint(): ?string
    {
        if ($this->parentEntity === null || $this->parentRecord === null || ! $this->parentRecord->exists) {
            return null;
        }

        return route('larsadmin.api.related', [$this->parentEntity::uriKey(), $this->parentRecord->getKey(), $this->attribute], false);
    }

    public function jsonSerialize(): array
    {
        $related = $this->relatedEntity();

        return array_merge(parent::jsonSerialize(), [
            'panel' => true,
            'endpoint' => $this->endpoint(),
            'relatedEntity' => [
                'uriKey' => $related::uriKey(),
                'label' => $related::label(),
                'singularLabel' => $related::singularLabel(),
                'searchable' => $related::searchable(),
                'perPageOptions' => $related::$perPageOptions,
            ],
            'perPage' => $this->perPage,
        ]);
    }
}
