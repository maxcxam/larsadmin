<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Maxcxam\Larsadmin\Audit\AuditFilters;
use Maxcxam\Larsadmin\Audit\AuditPresenter;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Symfony\Component\HttpFoundation\Response;

/**
 * The audit log views (ADR-025): the global page and the history of one record. Read-only.
 *
 * @internal Not part of the public API.
 */
class AuditController
{
    /**
     * The page size of a record's history panel.
     */
    public const int RECORD_PER_PAGE = 10;

    /**
     * The deepest page served (offset pagination): the record route answers 422 above it, the global
     * page shows the first page.
     */
    public const int MAX_PAGE = 1000;

    /**
     * The global audit log page (Inertia "Audit/Index"), newest first, previous/next without a total.
     */
    public function index(Request $request): Response
    {
        $this->authorize($request);

        $entities = array_values(array_filter(
            Larsadmin::registeredEntities(),
            fn (string $entity) => $entity::authorizedToViewAny($request),
        ));

        $filters = new AuditFilters($request, $entities);
        $perPage = min(100, max(1, (int) config('larsadmin.audit.per_page', 25)));
        $page = $this->page($request) ?? 1;

        [$entries, $hasMore] = $this->slice($filters->apply($filters->scope()), $page, $perPage);

        $presenter = new AuditPresenter($request);
        $query = $filters->active() === [] ? [] : ['filters' => $filters->active()];

        return $this->noStore(Inertia::render('Audit/Index', [
            'entries' => [
                'data' => $presenter->present($entries, true),
                'page' => $page,
                'perPage' => $perPage,
                'prevUrl' => $page > 1 ? route('larsadmin.audit.index', [...$query, 'page' => $page - 1], false) : null,
                'nextUrl' => $hasMore ? route('larsadmin.audit.index', [...$query, 'page' => $page + 1], false) : null,
            ],
            'filters' => $filters->serialize(),
            'query' => ['filters' => (object) $filters->active()],
            'timezone' => $presenter->timezone(),
        ])->toResponse($request));
    }

    /**
     * The history of one record (JSON), for its detail page: the gate, the entity's viewAny, the record
     * within detailQuery (404) and its view ability (403), as the detail page itself.
     */
    public function record(Request $request, string $entity, string $id): JsonResponse
    {
        $this->authorize($request);

        /** @var class-string<Entity> $entity */
        $entity = Larsadmin::entityForKey($entity) ?? abort(404);

        // As the detail page: no history panel for an entity that is not audited ($auditable = false).
        abort_unless($entity::audited(), 404);
        abort_unless($entity::authorizedToViewAny($request), 403);

        $record = EntityQuery::for($entity, $request)->scope('detail')->findOrFail($id);

        abort_unless($entity::make($record)->authorizedToView($request), 403);

        $page = $this->page($request);

        if ($page === null && $request->query->has('page')) {
            return $this->noStore(response()->json([
                'message' => __('validation.between.numeric', ['attribute' => 'page', 'min' => 1, 'max' => self::MAX_PAGE]),
                'errors' => ['page' => [__('validation.between.numeric', ['attribute' => 'page', 'min' => 1, 'max' => self::MAX_PAGE])]],
            ], 422));
        }

        $page ??= 1;

        // Entries of this morph type come only from entities of the same model: check just those.
        $morph = $record->getMorphClass();
        $allowed = array_values(array_map(
            fn (string $candidate) => $candidate::uriKey(),
            array_filter(Larsadmin::registeredEntities(), fn (string $candidate) => $candidate === $entity
                || ($candidate::newModel()->getMorphClass() === $morph && $candidate::authorizedToViewAny($request))),
        ));

        [$entries, $hasMore] = $this->slice(
            AuditEntry::query()
                ->where('auditable_type', $record->getMorphClass())
                ->where('auditable_id', (string) $record->getKey())
                ->whereIn('entity', $allowed),
            $page,
            self::RECORD_PER_PAGE,
        );

        $presenter = new AuditPresenter($request);

        return $this->noStore(response()->json([
            'entries' => $presenter->present($entries, false),
            'page' => $page,
            'perPage' => self::RECORD_PER_PAGE,
            'hasMore' => $hasMore,
            'timezone' => $presenter->timezone(),
        ]));
    }

    /**
     * 404 while the log is disabled; 403 without the "viewLarsadminAudit" ability (undefined = denied).
     */
    protected function authorize(Request $request): void
    {
        abort_unless(Larsadmin::auditEnabled(), 404);
        abort_unless(static::allowed($request), 403);
    }

    /**
     * Whether the request's panel user may read the audit log.
     */
    public static function allowed(Request $request): bool
    {
        $user = $request->user(Larsadmin::guard());

        return $user !== null && Gate::forUser($user)->check('viewLarsadminAudit');
    }

    /**
     * The requested page (absent: null), or null when it is not an integer from 1 to MAX_PAGE.
     */
    protected function page(Request $request): ?int
    {
        $page = $request->query('page');

        return is_string($page) && ctype_digit($page) && strlen($page) <= 9 && (int) $page >= 1 && (int) $page <= self::MAX_PAGE ? (int) $page : null;
    }

    /**
     * One page newest first, with one extra row to know whether there is a next page (no COUNT).
     *
     * @param  Builder<AuditEntry>  $query
     * @return array{0: Collection<int, AuditEntry>, 1: bool}
     */
    protected function slice($query, int $page, int $perPage): array
    {
        $entries = $query->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
        $hasMore = $entries->count() > $perPage;

        return [$hasMore ? $entries->slice(0, $perPage)->values() : $entries, $hasMore];
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    protected function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
