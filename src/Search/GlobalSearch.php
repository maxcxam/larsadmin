<?php

namespace Maxcxam\Larsadmin\Search;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The global search (Ctrl/⌘+K) across the opt-in entities (ADR-019, ADR-020, ADR-021).
 *
 * @internal
 */
class GlobalSearch
{
    public const int MAX_LIMIT = 20;

    /**
     * Milliseconds since an arbitrary point; injectable for tests.
     */
    protected Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? fn (): float => hrtime(true) / 1e6;
    }

    /**
     * Whether an exception means "not visible to this user": an authorization error, or an HTTP 403/404
     * (e.g. abort() in indexQuery() or a policy). Such entities and records are skipped silently.
     */
    public static function isRefusal(Throwable $e): bool
    {
        return $e instanceof AuthorizationException
            || ($e instanceof HttpExceptionInterface && in_array($e->getStatusCode(), [403, 404], true));
    }

    /**
     * Whether a digits-only term is shorter than the minimum length, so only the key is searched (SECS-6).
     */
    public static function isKeyLookup(string $term): bool
    {
        return ctype_digit($term) && mb_strlen($term) < static::minLength();
    }

    /**
     * The shortest term that is searched (digits-only terms are searched from one character).
     */
    public static function minLength(): int
    {
        return max(1, (int) config('larsadmin.search.min_length', 2));
    }

    /**
     * Whether the (trimmed) term is long enough to be searched.
     */
    public static function shouldSearch(string $term): bool
    {
        return $term !== '' && (ctype_digit($term) || mb_strlen($term) >= static::minLength());
    }

    /**
     * The client settings of the search, or null when the request can reach no opt-in entity.
     *
     * @return array{minLength: int, maxLength: int}|null
     */
    public static function clientConfig(Request $request): ?array
    {
        foreach (Larsadmin::registeredEntities() as $entity) {
            // Evaluated on every page: failures are reported by the search endpoint, not here (SECS-9).
            if (static::participates($entity, $request, report: false)) {
                return ['minLength' => static::minLength(), 'maxLength' => EntityQuery::MAX_SEARCH_LENGTH];
            }
        }

        return null;
    }

    /**
     * The per-entity result limit, clamped to 1..20.
     *
     * @param  class-string<Entity>  $entity
     */
    public static function limitFor(string $entity): int
    {
        return max(1, min(self::MAX_LIMIT, (int) ($entity::$globalSearchLimit ?? config('larsadmin.search.limit', 5))));
    }

    /**
     * Search every opt-in entity the request may list, in sidebar order.
     *
     * @return array{search: string, groups: list<array<string, mixed>>, failed: list<array{entity: string, label: string}>, partial: bool}
     */
    public function run(Request $request, string $term): array
    {
        $term = mb_substr(trim($term), 0, EntityQuery::MAX_SEARCH_LENGTH);
        $response = ['search' => $term, 'groups' => [], 'failed' => [], 'partial' => false];

        if (! static::shouldSearch($term)) {
            return $response;
        }

        $budget = config('larsadmin.search.time_budget_ms', 3000);
        $deadline = $budget === null ? null : ($this->clock)() + max(0, (int) $budget);

        foreach (Larsadmin::searchOrder() as $entity) {
            if (! static::participates($entity, $request)) {
                continue;
            }

            if ($deadline !== null && ($this->clock)() > $deadline) {
                $response['partial'] = true;

                break;
            }

            try {
                $group = $this->searchEntity($entity, $request, $term);
            } catch (Throwable $e) {
                // indexQuery() refusing the user means the entity is not visible, not that it failed.
                if (static::isRefusal($e)) {
                    continue;
                }

                report($e);

                $response['failed'][] = ['entity' => $entity::uriKey(), 'label' => $entity::label()];

                continue;
            }

            if ($group !== null) {
                $response['groups'][] = $group;
            }
        }

        return $response;
    }

    /**
     * Whether the entity opted in, can be searched and may be listed by the request. Failures deny.
     *
     * @param  class-string<Entity>  $entity
     */
    protected static function participates(string $entity, Request $request, bool $report = true): bool
    {
        if (! $entity::$globallySearchable) {
            return false;
        }

        try {
            return ($entity::usesScout() || $entity::searchable()) && $entity::authorizedToViewAny($request);
        } catch (Throwable $e) {
            // Fail closed, without naming the entity in the response.
            if ($report && ! static::isRefusal($e)) {
                report($e);
            }

            return false;
        }
    }

    /**
     * The result group of one entity, or null when nothing viewable matched.
     *
     * @param  class-string<Entity>  $entity
     * @return array<string, mixed>|null
     */
    protected function searchEntity(string $entity, Request $request, string $term): ?array
    {
        $limit = static::limitFor($entity);

        $candidates = $entity::usesScout() ? app(ScoutCandidates::class) : app(SqlCandidates::class);
        $rows = $candidates->get($entity, $request, $term, $limit + 1);

        $results = $this->results($entity, $request, $rows->take($limit));

        if ($results === []) {
            return null;
        }

        return [
            'entity' => $entity::uriKey(),
            'label' => $entity::label(),
            'results' => $results,
            'more' => $rows->count() > $limit,
            'indexUrl' => $entity::searchable()
                ? route('larsadmin.entities.index', [$entity::uriKey(), 'search' => $term], false)
                : null,
        ];
    }

    /**
     * Serialize the rows the request may view; rows without a safe detail URL are dropped.
     *
     * @param  class-string<Entity>  $entity
     * @param  Collection<int, Model>  $rows
     * @return list<array{id: mixed, title: string, subtitle: ?string, url: string}>
     */
    protected function results(string $entity, Request $request, Collection $rows): array
    {
        $shown = [];

        foreach ($rows as $row) {
            $record = $entity::make($row);

            try {
                $viewable = $record->authorizedToView($request);
            } catch (Throwable $e) {
                $viewable = static::isRefusal($e) ? false : throw $e;
            }

            if ($viewable && ($url = $record->url('show')) !== null) {
                $shown[] = [$record, $url];
            }
        }

        // Related records of the shown rows are checked together: one query per relation path.
        $text = new ResultText($entity, $request, collect($shown)->map(fn (array $item) => $item[0]->record));

        return array_map(fn (array $item) => [
            'id' => $item[0]->record->getKey(),
            'title' => $text->title($item[0]),
            'subtitle' => $text->subtitle($item[0]),
            'url' => $item[1],
        ], $shown);
    }
}
