<?php

namespace Maxcxam\Larsadmin\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\PanelField;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * Resolves the parent record and the relation panel of a panel endpoint (ADR-005, ADR-006).
 * The viewAny checks run first so unauthorized users cannot probe for records.
 *
 * @internal Not part of the public API.
 */
trait ResolvesPanel
{
    /**
     * The parent entity class and its record, resolved through the detail scope and viewable.
     *
     * @return array{0: class-string<Entity>, 1: Model}
     */
    protected function resolveParent(Request $request, string $entity, string $id): array
    {
        $parent = Larsadmin::findRecord($request, $entity, $id);

        return [Larsadmin::entityForKey($entity), $parent];
    }

    /**
     * The parent plus the panel field of the given type shown on its detail page, whose related entity is listable.
     *
     * @param  class-string<PanelField>  $type
     * @return array{0: class-string<Entity>, 1: Model, 2: PanelField}
     */
    protected function resolvePanel(Request $request, string $entity, string $id, string $field, string $type = PanelField::class): array
    {
        [$parentEntity, $parent] = $this->resolveParent($request, $entity, $id);

        $panel = $parentEntity::make($parent)->fieldPrototypes($request)
            ->first(fn (Field $candidate) => $candidate instanceof $type
                && $candidate->attribute === $field
                && $candidate->isShownOn(Field::DETAIL, $request, $parent));

        abort_unless($panel instanceof PanelField, 404);
        abort_unless($panel->relatedEntity()::authorizedToViewAny($request), 403);

        return [$parentEntity, $parent, $panel];
    }
}
