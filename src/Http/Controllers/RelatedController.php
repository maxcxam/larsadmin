<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\BelongsToMany;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\HasMany;
use Maxcxam\Larsadmin\Http\Controllers\Concerns\ResolvesPanel;
use Maxcxam\Larsadmin\Query\EntityQuery;

/**
 * @internal Not part of the public API.
 */
class RelatedController
{
    use ResolvesPanel;

    /**
     * List the related records of a HasMany / BelongsToMany panel (ADR-005).
     */
    public function index(Request $request, string $entity, string $id, string $field): JsonResponse
    {
        [$parentEntity, $parent, $panel] = $this->resolvePanel($request, $entity, $id, $field);

        $related = $panel->relatedEntity();
        $columns = $related::indexColumns($request);

        $query = EntityQuery::fromRequest($request, $related, $panel->getPerPage())
            ->scope('index')
            ->constrainTo($panel->relation($parent))
            ->filter()
            ->applyRequest()
            ->withEagerLoads(Field::INDEX);

        $params = $query->params();
        $paginator = $query->paginate($params['perPage'], $params['page']);
        $via = $panel instanceof HasMany ? $panel->via($parent) : null;

        $data = $paginator->getCollection()
            ->map(function (Model $row) use ($request, $related, $panel, $parent, $parentEntity, $via) {
                $serialized = $related::make($row)->serializeRecord($request, Field::INDEX);
                $detach = $panel instanceof BelongsToMany && $panel->authorizedToDetach($request, $parent, $row);

                // A removed HasMany row returns to the parent through the validated via* query.
                if ($via !== null && $serialized['urls']['destroy'] !== null) {
                    $serialized['urls']['destroy'] = $related::make($row)->url('destroy', $via);
                }

                $parentKey = $parentEntity::pathSegment($parent->getKey());
                $relatedKey = $related::pathSegment($row->getKey());

                $serialized['urls']['detach'] = $detach && $parentKey !== null && $relatedKey !== null
                    ? route('larsadmin.entities.detach', [$parentEntity::uriKey(), $parentKey, $panel->attribute, $relatedKey], false)
                    : null;

                return $serialized + ['authorizedToDetach' => $detach];
            })
            ->values();

        $filters = $query->serializeFilters();

        return response()->json([
            'columns' => $columns,
            'records' => [
                'data' => $data,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'query' => [
                'search' => $params['search'],
                'sort' => $params['sort'],
                'direction' => $params['sort'] ? $params['direction'] : null,
                'perPage' => $params['perPage'],
                'defaultSort' => $params['defaultSort'],
            ] + ($filters === [] ? [] : ['filters' => (object) $query->activeFilters()]),
        ] + ($filters === [] ? [] : ['filters' => $filters]));
    }
}
