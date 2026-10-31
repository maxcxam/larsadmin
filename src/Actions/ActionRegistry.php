<?php

namespace Maxcxam\Larsadmin\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;

/**
 * Resolves an entity's actions: bound to the entity and visible to the user.
 *
 * @internal Not part of the public API.
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

    /**
     * The actions needing a per-row availability flag in the context: 'index' = inline, 'detail' = shown on
     * detail; standalone actions never run on a record.
     *
     * @param  class-string<Entity>  $entity
     * @return array<string, Action>
     */
    public static function rowActions(string $entity, Request $request, string $context): array
    {
        return array_filter(
            self::for($entity, $request),
            fn (Action $action) => ! $action->standalone && ($context === 'index' ? $action->showInline : $action->showOnDetail),
        );
    }

    /**
     * The uriKeys of $actions the user may run on the record. $canUpdate is the row's already computed update
     * ability, used for actions with the default check (no extra policy call); canRun callbacks run per record
     * and must not query. The run-time checks of ActionController stay authoritative.
     *
     * @param  array<string, Action>  $actions  bound actions, as returned by for()/rowActions()
     * @return list<string>
     */
    public static function runnableFor(Request $request, array $actions, Model $record, bool $canUpdate): array
    {
        $runnable = [];

        foreach ($actions as $action) {
            if ($action->standalone) {
                continue;
            }

            if ($action->usesDefaultRunCheck() ? $canUpdate : $action->authorizedToRun($request, $record)) {
                $runnable[] = $action->uriKey();
            }
        }

        return $runnable;
    }
}
