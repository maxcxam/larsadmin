<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Audit\ActionAudit;
use Maxcxam\Larsadmin\Audit\AuditActor;
use Maxcxam\Larsadmin\Audit\AuditRecorder;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Throwable;

class ActionController
{
    /**
     * Run an action on the selected records (or all matching the index query).
     */
    public function run(Request $request, string $entity, string $action): RedirectResponse
    {
        $entity = Larsadmin::entityForKey($entity) ?? abort(404);

        abort_unless($entity::authorizedToViewAny($request), 403);

        $action = ActionRegistry::for($entity, $request)[$action] ?? abort(404);

        if ($action->standalone) {
            abort_unless($action->authorizedToRunStandalone($request), 403);
        }

        $resources = $this->resources($request, $entity, $action);
        $fields = $action->validatedFields($request);

        if (! $entity::audited()) {
            return $this->execute($request, $entity, $action, $resources, $fields, null);
        }

        // One batch id for the whole run; save()/deleteRecord() called by handle() write no entries of their own.
        $recorder = app(AuditRecorder::class);
        $audit = new ActionAudit($entity, $action, $fields, $request, AuditActor::fromRequest($request), $recorder);

        return $recorder->batch(fn () => $this->execute($request, $entity, $action, $resources, $fields, $audit));
    }

    /**
     * Run the action: standalone in one transaction, otherwise per chunk of authorized records, each
     * in its own transaction together with its audit entries.
     *
     * @param  class-string<Entity>  $entity
     * @param  'all'|list<int|string>  $resources
     */
    protected function execute(Request $request, string $entity, Action $action, string|array $resources, ActionFields $fields, ?ActionAudit $audit): RedirectResponse
    {
        if ($action->standalone) {
            $model = $entity::newModel();

            return $this->respond($request, $entity, $model->getConnection()->transaction(function () use ($action, $fields, $model, $audit) {
                $result = $action->handle($fields, $model->newCollection());
                $audit?->standalone();

                return $result;
            }), 0);
        }

        $builder = $this->builder($request, $entity, $resources);
        $model = $builder->getModel();

        $total = (clone $builder)->reorder()->count();
        $max = max(1, (int) config('larsadmin.actions.max_all', 1000));

        if ($resources === 'all' && $total > $max) {
            throw ValidationException::withMessages([
                'resources' => __('larsadmin::actions.too_many', ['total' => $total, 'max' => $max]),
            ]);
        }

        if ($total === 0) {
            return $this->respond($request, $entity, ActionResponse::danger(__('larsadmin::actions.nothing_matched')), 0);
        }

        $response = null;
        $ran = 0;
        $skipped = 0;

        try {
            $builder->reorder()->chunkById(
                max(1, $action->chunkSize),
                function (EloquentCollection $chunk) use ($request, $action, $fields, $audit, &$response, &$ran, &$skipped) {
                    $allowed = $chunk->filter(fn (Model $record) => $action->authorizedToRun($request, $record))->values();

                    $skipped += $chunk->count() - $allowed->count();

                    if ($allowed->isEmpty()) {
                        return;
                    }

                    $result = $allowed->first()->getConnection()->transaction(function () use ($action, $fields, $allowed, $audit) {
                        // Snapshots first: handle() may change the models it receives.
                        $snapshots = $audit?->before($allowed);
                        $result = $action->handle($fields, $allowed);

                        $audit?->after($snapshots ?? []);

                        return $result;
                    });

                    $ran += $allowed->count();
                    $response = $this->mostSevere($response, $result);
                },
                $model->getQualifiedKeyName(),
                $model->getKeyName(),
            );
        } catch (Throwable $e) {
            // Nothing committed: let the exception handler deal with it as usual.
            if ($ran === 0) {
                throw $e;
            }

            report($e);

            return $this->respond($request, $entity, ActionResponse::danger(
                __('larsadmin::actions.partial', ['done' => $ran, 'total' => $total]),
            ), $skipped);
        }

        // Rows matched but none was authorized (nothing matching is answered above).
        abort_if($ran === 0, 403);

        return $this->respond($request, $entity, $response, $skipped);
    }

    /**
     * Of two responses keep the more severe one: danger, then redirect/download, then message.
     * On a tie the earlier response wins.
     */
    protected function mostSevere(?ActionResponse $current, ?ActionResponse $next): ?ActionResponse
    {
        $rank = fn (?ActionResponse $response) => match ($response?->type) {
            ActionResponse::DANGER => 3,
            ActionResponse::REDIRECT, ActionResponse::DOWNLOAD => 2,
            ActionResponse::MESSAGE => 1,
            default => 0,
        };

        return $rank($next) > $rank($current) ? $next : $current;
    }

    /**
     * Validate the target selection: 'all' or a bounded list of scalar ids.
     *
     * @param  class-string<Entity>  $entity
     * @return 'all'|list<int|string>
     */
    protected function resources(Request $request, string $entity, Action $action): string|array
    {
        if ($action->standalone) {
            return [];
        }

        $request->validate([
            'resources' => ['required', function (string $attribute, mixed $value, Closure $fail) use ($entity) {
                $valid = $value === 'all' || (
                    is_array($value)
                    && array_is_list($value)
                    && $value !== []
                    && count($value) <= max($entity::$perPageOptions)
                    && collect($value)->every(fn ($id) => is_int($id) || (is_string($id) && $id !== '' && strlen($id) <= 64))
                );

                if (! $valid) {
                    $fail(__('validation.required', ['attribute' => $attribute]));
                }
            }],
        ]);

        $resources = $request->input('resources');

        // Only offered per row / on a detail page: never more than the one record those menus send.
        if ($action->runsOnSingleRecord() && ($resources === 'all' || count($resources) > 1)) {
            throw ValidationException::withMessages(['resources' => __('larsadmin::actions.single')]);
        }

        return $resources;
    }

    /**
     * @param  class-string<Entity>  $entity
     * @param  'all'|list<int|string>  $resources
     */
    protected function builder(Request $request, string $entity, string|array $resources): Builder
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
     * @param  class-string<Entity>  $entity
     */
    protected function respond(Request $request, string $entity, ?ActionResponse $response, int $skipped): RedirectResponse
    {
        $response ??= ActionResponse::message(__('larsadmin::actions.completed'));

        $redirect = $response->type === ActionResponse::REDIRECT
            ? redirect($response->url)
            : redirect($this->backUrl($request, $entity));

        if ($response->type === ActionResponse::DOWNLOAD) {
            $redirect->with('larsadmin.download', ['url' => $response->url, 'filename' => $response->filename]);
        }

        $message = $response->message;

        if ($message !== null && $skipped > 0 && $response->type === ActionResponse::MESSAGE) {
            $message .= ' '.trans_choice('larsadmin::actions.skipped', $skipped);
        }

        if ($message !== null) {
            $redirect->with('larsadmin.toast', [
                'message' => $message,
                'type' => $response->type === ActionResponse::DANGER ? 'error' : 'success',
            ]);
        }

        return $redirect;
    }

    /**
     * The Referer when it is on this host, otherwise the entity index.
     *
     * @param  class-string<Entity>  $entity
     */
    protected function backUrl(Request $request, string $entity): string
    {
        $referer = (string) $request->headers->get('referer');
        $parts = parse_url($referer);

        if (is_array($parts) && ($parts['host'] ?? null) === $request->getHost() && ! isset($parts['user'])) {
            return $referer;
        }

        return route('larsadmin.entities.index', $entity::uriKey());
    }
}
