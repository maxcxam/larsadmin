<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\BelongsToMany;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Http\Controllers\Concerns\ResolvesPanel;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;

class RelatableController
{
    use ResolvesPanel;

    public const int MAX_PAGE = 1000;

    /**
     * List the records that may be picked in a BelongsTo field, or attached through a BelongsToMany field (ADR-004).
     */
    public function index(Request $request, string $entity, string $field): JsonResponse
    {
        $parent = Larsadmin::entityForKey($entity) ?? abort(404);

        abort_unless($parent::authorizedToViewAny($request), 403);

        $validator = Validator::make($request->query(), [
            'context' => ['required', 'string', 'in:create,update,attach,action'],
            'action' => ['exclude_unless:context,action', 'required', 'string', 'max:64'],
            'resourceId' => ['exclude_if:context,create,action', 'required', 'string', 'max:64'],
            'search' => ['nullable', 'string', 'max:'.EntityQuery::MAX_SEARCH_LENGTH],
            'page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $context = $request->query('context');
        $record = null;

        if ($context === 'attach') {
            [, $record, $picker] = $this->resolvePanel($request, $entity, (string) $request->query('resourceId'), $field, BelongsToMany::class);

            abort_unless($picker->authorizedToAttachAny($request, $record), 403);
        } else {
            if ($context === 'create') {
                abort_unless($parent::authorizedToCreate($request), 403);
            } elseif ($context === 'update') {
                $record = $this->record($request, $parent, (string) $request->query('resourceId'));

                abort_unless($parent::make($record)->authorizedToUpdate($request), 403);
            }

            // An action context is authorized by the action's canSee (the registry only returns visible actions).
            $fields = $context === 'action'
                ? (ActionRegistry::for($parent, $request)[(string) $request->query('action')] ?? abort(404))->fieldPrototypes($request)
                : $parent::make($record)->fieldPrototypes($request);

            $formContext = $context === 'update' ? Field::UPDATE : Field::CREATE;

            $picker = $fields->first(fn (Field $candidate) => $candidate instanceof BelongsTo
                && $candidate->attribute === $field
                && $candidate->isShownOn($formContext, $request, $record ?? $parent::newModel())
                && ! $candidate->isReadonlyFor($request, $record ?? $parent::newModel()));

            abort_unless($picker instanceof BelongsTo, 404);
        }

        $related = $picker->relatedEntity();

        abort_unless($related::authorizedToViewAny($request), 403);

        $limit = $picker->getRelatableLimit();
        $page = (int) ($request->query('page') ?: 1);

        $query = $picker->relatableQuery($request, $record);
        $relatedModel = $query->getModel();

        if ($picker instanceof BelongsToMany) {
            $query->whereNotIn(
                $relatedModel->getQualifiedKeyName(),
                $picker->relation($record)->getQuery()->reorder()->select($relatedModel->getQualifiedKeyName())->toBase(),
            );
        }

        $rows = EntityQuery::for($related, $request, $query)
            ->search($request->query('search'))
            ->builder()
            ->reorder()
            ->orderBy($relatedModel->getQualifiedKeyName(), 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit + 1)
            ->get();

        return response()->json([
            'data' => $rows->take($limit)
                ->map(fn (Model $row) => ['id' => $row->getKey(), 'title' => $related::make($row)->title()])
                ->values(),
            'hasMore' => $rows->count() > $limit,
        ]);
    }

    /**
     * Resolve the parent record through its detail scope.
     *
     * @param  class-string<Entity>  $entity
     */
    protected function record(Request $request, string $entity, string $id): Model
    {
        return EntityQuery::for($entity, $request)->scope('detail')->findOrFail($id);
    }
}
