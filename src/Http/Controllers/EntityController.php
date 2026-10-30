<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;

class EntityController
{
    /**
     * List the entity's records.
     */
    public function index(Request $request, string $entity): Response
    {
        $entity = $this->entity($entity);

        abort_unless($entity::authorizedToViewAny($request), 403);

        $columns = $entity::indexColumns($request);

        $query = EntityQuery::fromRequest($request, $entity)
            ->scope('index')
            ->filter()
            ->applyRequest()
            ->withEagerLoads(Field::INDEX);

        $params = $query->params();

        $records = $query->paginate($params['perPage'], $params['page'])
            ->withQueryString()
            ->through(fn (Model $record) => $entity::make($record)->serializeRecord($request, Field::INDEX));

        return Inertia::render('Entities/Index', [
            'entity' => $entity::serializeMeta($request),
            'actions' => ActionRegistry::serialize($entity, $request),
            'columns' => $columns,
            'records' => $records,
            'filters' => $query->serializeFilters(),
            'query' => [
                'filters' => (object) $query->activeFilters(),
                'search' => $params['search'],
                'sort' => $params['sort'],
                'direction' => $params['sort'] ? $params['direction'] : null,
                'perPage' => $params['perPage'],
                'defaultSort' => $params['defaultSort'],
            ],
        ]);
    }

    /**
     * Show the form for creating a record.
     */
    public function create(Request $request, string $entity): Response
    {
        $entity = $this->entity($entity);

        abort_unless($entity::authorizedToCreate($request), 403);

        return Inertia::render('Entities/Create', [
            'entity' => $entity::serializeMeta($request),
            'fields' => $entity::make()->resolveFields($request, Field::CREATE),
        ]);
    }

    /**
     * Store a new record.
     */
    public function store(Request $request, string $entity): RedirectResponse
    {
        $entity = $this->entity($entity);

        abort_unless($entity::authorizedToCreate($request), 403);

        $instance = $entity::make();

        $request->validate(
            $instance->rules($request, Field::CREATE),
            [],
            $instance->validationAttributes($request, Field::CREATE),
        );

        $record = $instance->save($request, Field::CREATE);

        return redirect()
            ->route('larsadmin.entities.show', $this->returnTo($request, $instance) ?? [$entity::uriKey(), $record->getKey()])
            ->with('larsadmin.toast', ['message' => __('larsadmin::messages.created'), 'type' => 'success']);
    }

    /**
     * Show a record.
     */
    public function show(Request $request, string $entity, string $id): Response
    {
        $entity = $this->entity($entity);
        $instance = $this->instance($request, $entity, $id);

        abort_unless($instance->authorizedToView($request), 403);

        return Inertia::render('Entities/Detail', [
            'entity' => $entity::serializeMeta($request),
            'record' => $instance->serializeRecord($request, Field::DETAIL),
            'actions' => ActionRegistry::serialize($entity, $request),
        ]);
    }

    /**
     * Show the form for editing a record.
     */
    public function edit(Request $request, string $entity, string $id): Response
    {
        $entity = $this->entity($entity);
        $instance = $this->instance($request, $entity, $id, Field::UPDATE);

        abort_unless($instance->authorizedToUpdate($request), 403);

        return Inertia::render('Entities/Edit', [
            'entity' => $entity::serializeMeta($request),
            'record' => $instance->serializeRecord($request, Field::UPDATE),
        ]);
    }

    /**
     * Update a record.
     */
    public function update(Request $request, string $entity, string $id): RedirectResponse
    {
        $entity = $this->entity($entity);
        $instance = $this->instance($request, $entity, $id, Field::UPDATE);

        abort_unless($instance->authorizedToUpdate($request), 403);

        $request->validate(
            $instance->rules($request, Field::UPDATE),
            [],
            $instance->validationAttributes($request, Field::UPDATE),
        );

        $instance->save($request, Field::UPDATE);

        return redirect()
            ->route('larsadmin.entities.show', $this->returnTo($request, $instance) ?? [$entity::uriKey(), $instance->record->getKey()])
            ->with('larsadmin.toast', ['message' => __('larsadmin::messages.updated'), 'type' => 'success']);
    }

    /**
     * Delete a record.
     */
    public function destroy(Request $request, string $entity, string $id): RedirectResponse
    {
        $entity = $this->entity($entity);
        $instance = $this->instance($request, $entity, $id);

        abort_unless($instance->authorizedToDelete($request), 403);

        $via = $this->returnTo($request, $instance);

        $instance->record->delete();

        return ($via ? redirect()->route('larsadmin.entities.show', $via) : redirect($this->indexUrl($request, $entity)))
            ->with('larsadmin.toast', ['message' => __('larsadmin::messages.deleted'), 'type' => 'success']);
    }

    /**
     * The show route parameters of the validated via* parent (ADR-008), or null.
     * Built from validated keys only, never from raw input.
     *
     * @return array{0: string, 1: int|string}|null
     */
    protected function returnTo(Request $request, Entity $instance): ?array
    {
        foreach ($instance->fieldPrototypes($request) as $field) {
            if ($field instanceof BelongsTo && ($parent = $field->viaRecord($request, $instance->record))) {
                $parentEntity = $field->relatedEntity();
                $key = $parentEntity::pathSegment($parent->getKey());

                // A pickable parent may still be unviewable: then fall back to the record's own page.
                $viewable = $key !== null
                    && EntityQuery::for($parentEntity, $request)->scope('detail')->builder()->whereKey($parent->getKey())->exists()
                    && $parentEntity::make($parent)->authorizedToView($request);

                return $viewable ? [$parentEntity::uriKey(), $key] : null;
            }
        }

        return null;
    }

    /**
     * Resolve the entity class for the given URI key.
     *
     * @return class-string<Entity>
     */
    protected function entity(string $key): string
    {
        return Larsadmin::entityForKey($key) ?? abort(404);
    }

    /**
     * Resolve the entity instance for the record, after the viewAny check so
     * unauthorized users cannot tell whether a record exists.
     *
     * @param  class-string<Entity>  $entity
     */
    protected function instance(Request $request, string $entity, string $id, string $context = Field::DETAIL): Entity
    {
        abort_unless($entity::authorizedToViewAny($request), 403);

        return $entity::make($this->record($request, $entity, $id, $context));
    }

    /**
     * @param  class-string<Entity>  $entity
     */
    protected function record(Request $request, string $entity, string $id, string $context = Field::DETAIL): Model
    {
        $model = $entity::newModel();

        if ($this->hasIntegerKey($model) && (! ctype_digit($id) || strlen($id) > 18)) {
            abort(404);
        }

        return EntityQuery::for($entity, $request)
            ->scope('detail')
            ->withEagerLoads($context)
            ->builder()
            ->where($model->getQualifiedKeyName(), $this->hasIntegerKey($model) ? (int) $id : $id)
            ->firstOrFail();
    }

    protected function hasIntegerKey(Model $model): bool
    {
        return $model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true);
    }

    /**
     * The index URL to return to after a delete. The query string of the
     * Referer is kept only when it points at this entity's index.
     *
     * @param  class-string<Entity>  $entity
     */
    protected function indexUrl(Request $request, string $entity): string
    {
        $url = route('larsadmin.entities.index', $entity::uriKey());
        $referer = parse_url((string) $request->headers->get('referer'));

        if (
            is_array($referer)
            && ($referer['host'] ?? null) === $request->getHost()
            && ($referer['path'] ?? null) === parse_url($url, PHP_URL_PATH)
            && ! empty($referer['query'])
        ) {
            return $url.'?'.$referer['query'];
        }

        return $url;
    }
}
