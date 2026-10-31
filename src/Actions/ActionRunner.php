<?php

namespace Maxcxam\Larsadmin\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Audit\ActionAudit;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Throwable;

/**
 * The loop of an action run, shared by the HTTP request (synchronous) and the queue worker (ADR-028):
 * standalone in one transaction, otherwise per chunk of authorized records, each in its own transaction
 * together with its audit entries. Exceptions are returned, not thrown or reported: the caller decides.
 *
 * @internal Not part of the public API.
 */
class ActionRunner
{
    /**
     * @param  class-string<Entity>  $entity
     * @param  Builder<Model>|null  $builder  null for a standalone action
     * @param  int|null  $total  the already counted matches (counted here when null)
     * @param  (Closure(int $ran, int $skipped): void)|null  $progress  called after each chunk committed
     * @param  (Closure(): bool)|null  $shouldStop  asked before each chunk after the first
     */
    public function run(
        Request $request,
        string $entity,
        Action $action,
        ?Builder $builder,
        ActionFields $fields,
        ?ActionAudit $audit,
        ?Closure $progress = null,
        ?Closure $shouldStop = null,
        ?int $total = null,
    ): RunResult {
        if ($action->standalone || $builder === null) {
            $model = $entity::newModel();

            try {
                $response = $model->getConnection()->transaction(function () use ($action, $fields, $model, $audit) {
                    $result = $action->handle($fields, $model->newCollection());
                    $audit?->standalone();

                    return $result;
                });
            } catch (Throwable $e) {
                return new RunResult(null, null, error: $e);
            }

            return new RunResult($response, null);
        }

        $model = $builder->getModel();
        $total ??= $this->count($builder);

        $response = null;
        $ran = 0;
        $skipped = 0;
        $chunks = 0;
        $cancelled = false;

        try {
            $builder->reorder()->chunkById(
                max(1, $action->chunkSize),
                function (EloquentCollection $chunk) use ($request, $action, $fields, $audit, $progress, $shouldStop, &$response, &$ran, &$skipped, &$chunks, &$cancelled) {
                    if ($chunks++ > 0 && $shouldStop !== null && $shouldStop()) {
                        $cancelled = true;

                        return false;
                    }

                    $allowed = $chunk->filter(fn (Model $record) => $action->authorizedToRun($request, $record))->values();

                    $skipped += $chunk->count() - $allowed->count();

                    if ($allowed->isNotEmpty()) {
                        $result = $allowed->first()->getConnection()->transaction(function () use ($action, $fields, $allowed, $audit) {
                            // Snapshots first: handle() may change the models it receives.
                            $snapshots = $audit?->before($allowed);
                            $result = $action->handle($fields, $allowed);

                            $audit?->after($snapshots ?? []);

                            return $result;
                        });

                        $ran += $allowed->count();
                        $response = static::mostSevere($response, $result);
                    }

                    if ($progress !== null) {
                        $progress($ran, $skipped);
                    }
                },
                $model->getQualifiedKeyName(),
                $model->getKeyName(),
            );
        } catch (Throwable $e) {
            return new RunResult($response, $total, $ran, $skipped, $e);
        }

        return new RunResult($response, $total, $ran, $skipped, cancelled: $cancelled);
    }

    /**
     * The number of records the builder matches.
     *
     * @param  Builder<Model>  $builder
     */
    public function count(Builder $builder): int
    {
        return (clone $builder)->reorder()->count();
    }

    /**
     * The records an action runs on: 'all' = the index query with the request's filters and search,
     * otherwise the listed ids within the detail query.
     *
     * @param  class-string<Entity>  $entity
     * @param  'all'|list<int|string>  $resources
     * @return Builder<Model>
     */
    public static function builder(Request $request, string $entity, string|array $resources): Builder
    {
        $query = EntityQuery::for($entity, $request);

        if ($resources === 'all') {
            $filters = $request->input('filters');
            $search = $request->input('search');

            return $query->scope('index')
                ->filter(is_array($filters) ? $filters : [])
                ->search(is_string($search) ? $search : '')
                ->builder();
        }

        $model = $entity::newModel();
        $integerKey = $model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true);

        $ids = collect($resources)
            ->map(fn ($id) => (string) $id)
            ->when($integerKey, fn (Collection $ids) => $ids
                ->filter(fn (string $id) => ctype_digit($id) && strlen($id) <= 18)
                ->map(fn (string $id) => (int) $id))
            ->unique()
            ->values()
            ->all();

        return $query->scope('detail')->builder()->whereIn($model->getQualifiedKeyName(), $ids);
    }

    /**
     * Of two responses keep the more severe one: danger, then redirect/download/file, then message.
     * On a tie the earlier response wins.
     */
    public static function mostSevere(?ActionResponse $current, ?ActionResponse $next): ?ActionResponse
    {
        $rank = fn (?ActionResponse $response) => match ($response?->type) {
            ActionResponse::DANGER => 3,
            ActionResponse::REDIRECT, ActionResponse::DOWNLOAD, ActionResponse::FILE => 2,
            ActionResponse::MESSAGE => 1,
            default => 0,
        };

        return $rank($next) > $rank($current) ? $next : $current;
    }
}
