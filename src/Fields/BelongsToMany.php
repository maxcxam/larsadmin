<?php

namespace Maxcxam\Larsadmin\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany as BelongsToManyRelation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Fields\Concerns\HasRelatableOptions;

class BelongsToMany extends PanelField
{
    use HasRelatableOptions;

    public string $component = 'belongs-to-many-field';

    protected function relationClass(): string
    {
        return BelongsToManyRelation::class;
    }

    /**
     * May the user open the picker and see the attach button? (ADR-006)
     */
    public function authorizedToAttachAny(Request $request, Model $parent): bool
    {
        return $this->allows('attachAny', $parent);
    }

    public function authorizedToAttach(Request $request, Model $parent, Model $related): bool
    {
        return $this->allows('attach', $parent, $related);
    }

    public function authorizedToDetach(Request $request, Model $parent, Model $related): bool
    {
        return $this->allows('detach', $parent, $related);
    }

    /**
     * Check "{ability}{RelatedModel}" on the parent policy when it defines it, else the parent "update".
     * A model without a policy is governed by "larsadmin.allow_without_policy".
     */
    protected function allows(string $ability, Model $parent, ?Model $related = null): bool
    {
        $policy = Gate::getPolicyFor($parent::class);

        if ($policy === null) {
            return (bool) config('larsadmin.allow_without_policy', true);
        }

        $method = $ability.Str::studly(class_basename($this->relatedModel()));

        if (method_exists($policy, $method)) {
            return Gate::check($method, $related ? [$parent, $related] : $parent);
        }

        return Gate::check('update', $parent);
    }

    public function jsonSerialize(): array
    {
        $record = $this->parentRecord;
        $resolved = $this->request !== null && $this->parentEntity !== null && $record?->exists;

        return array_merge(parent::jsonSerialize(), [
            'searchable' => $this->searchable,
            'optionsUrl' => $this->parentEntity
                ? route('larsadmin.api.options', [$this->parentEntity::uriKey(), $this->attribute], false)
                : null,
            'attachUrl' => $resolved && $this->authorizedToAttachAny($this->request, $record)
                ? route('larsadmin.entities.attach', [$this->parentEntity::uriKey(), $record->getKey(), $this->attribute], false)
                : null,
            'via' => null,
            'createUrl' => null,
        ]);
    }
}
