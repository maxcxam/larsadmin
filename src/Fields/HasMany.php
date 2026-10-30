<?php

namespace Maxcxam\Larsadmin\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany as HasManyRelation;
use LogicException;

class HasMany extends PanelField
{
    public string $component = 'has-many-field';

    protected function relationClass(): string
    {
        return HasManyRelation::class;
    }

    /**
     * The BelongsTo attribute of the related entity that points back to the parent, if one can be prefilled.
     */
    public function inverseAttribute(): ?string
    {
        if ($this->request === null || $this->parentEntity === null) {
            return null;
        }

        $foreignKey = $this->relation()->getForeignKeyName();

        foreach ($this->relatedEntity()::make()->fieldPrototypes($this->request) as $field) {
            if (! $field instanceof BelongsTo || ! $field->isShownOn(self::CREATE, $this->request, $this->relatedEntity()::newModel()) || $field->isReadonlyFor($this->request, $this->relatedEntity()::newModel())) {
                continue;
            }

            try {
                if ($field->fillAttribute() === $foreignKey && $field->relatedEntity()::uriKey() === $this->parentEntity::uriKey()) {
                    return $field->attribute;
                }
            } catch (LogicException) {
                continue;
            }
        }

        return null;
    }

    /**
     * The via* query parameters (ADR-008) that make a related record return to the parent, or null
     * when the related entity has no BelongsTo pointing back.
     *
     * @return array{viaEntity: string, viaId: int|string, viaField: string}|null
     */
    public function via(?Model $record = null): ?array
    {
        $record ??= $this->parentRecord;
        $inverse = $this->inverseAttribute();

        return $inverse !== null && $record?->exists
            ? ['viaEntity' => $this->parentEntity::uriKey(), 'viaId' => $record->getKey(), 'viaField' => $inverse]
            : null;
    }

    public function jsonSerialize(): array
    {
        $related = $this->relatedEntity();
        $record = $this->parentRecord;
        $via = $this->via();

        $createUrl = null;

        if ($this->request !== null && $record?->exists && $related::authorizedToCreate($this->request)) {
            $createUrl = route('larsadmin.entities.create', [$related::uriKey(), ...($via ?? [])], false);
        }

        return array_merge(parent::jsonSerialize(), [
            'createUrl' => $createUrl,
            'via' => $via,
        ]);
    }
}
