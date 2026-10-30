<?php

namespace Maxcxam\Larsadmin\Actions;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;

/**
 * Resolves an entity's actions: bound to the entity and visible to the user.
 */
final class ActionRegistry
{
    /**
     * The entity's actions bound to it, keyed by uriKey (first wins), visible to the user.
     *
     * @param  class-string<Entity>  $entity
     * @return array<string, Action>
     */
    public static function for(string $entity, Request $request): array
    {
        $instance = $entity::make();
        $actions = [];

        foreach ($instance->actions($request) as $action) {
            if ($action instanceof Action && $action->bindTo($entity)->authorizedToSee($request)) {
                $actions[$action->uriKey()] ??= $action;
            }
        }

        return $actions;
    }

    /**
     * The `actions` prop.
     *
     * @param  class-string<Entity>  $entity
     * @return list<array<string, mixed>>
     */
    public static function serialize(string $entity, Request $request): array
    {
        return collect(self::for($entity, $request))
            ->map(fn (Action $action) => $action->serialize($request))
            ->values()
            ->all();
    }
}
