<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Events\Attached;
use Maxcxam\Larsadmin\Events\Detached;
use Maxcxam\Larsadmin\Fields\BelongsToMany;
use Maxcxam\Larsadmin\Http\Controllers\Concerns\ResolvesPanel;
use Maxcxam\Larsadmin\Rules\RelatableExists;

class AttachController
{
    use ResolvesPanel;

    /**
     * Attach a related record (ADR-006). Idempotent at the database level; duplicates fail validation.
     */
    public function store(Request $request, string $entity, string $id, string $field): RedirectResponse
    {
        [$parentEntity, $parent, $panel] = $this->resolvePanel($request, $entity, $id, $field, BelongsToMany::class);

        abort_unless($panel->authorizedToAttachAny($request, $parent), 403);

        $request->validate(
            ['related' => ['required', new RelatableExists($panel, $request, $parent, attach: true)]],
            [],
            ['related' => $panel->name],
        );

        $related = $panel->findRelatable($request, $request->input('related'), $parent) ?? abort(422);

        abort_unless($panel->authorizedToAttach($request, $parent, $related), 403);

        $parent->getConnection()->transaction(function () use ($panel, $parent, $related, $request) {
            $panel->relation($parent)->syncWithoutDetaching([$related->getKey()]);

            event(new Attached($parent, $panel->attribute, $related, $request->user()));
        });

        return $this->back($parentEntity, $parent, 'attached');
    }

    /**
     * Detach a currently attached record.
     */
    public function destroy(Request $request, string $entity, string $id, string $field, string $relatedId): RedirectResponse
    {
        [$parentEntity, $parent, $panel] = $this->resolvePanel($request, $entity, $id, $field, BelongsToMany::class);

        $key = $panel->normalizeKey($relatedId) ?? abort(404);
        $related = $panel->relation($parent)->whereKey($key)->first() ?? abort(404);

        abort_unless($panel->authorizedToDetach($request, $parent, $related), 403);

        $parent->getConnection()->transaction(function () use ($panel, $parent, $related, $request) {
            $panel->relation($parent)->detach([$related->getKey()]);

            event(new Detached($parent, $panel->attribute, $related, $request->user()));
        });

        return $this->back($parentEntity, $parent, 'detached');
    }

    /**
     * @param  class-string<Entity>  $parentEntity
     */
    protected function back(string $parentEntity, Model $parent, string $message): RedirectResponse
    {
        return redirect()
            ->route('larsadmin.entities.show', [$parentEntity::uriKey(), $parent->getKey()])
            ->with('larsadmin.toast', ['message' => __("larsadmin::messages.{$message}"), 'type' => 'success']);
    }
}
