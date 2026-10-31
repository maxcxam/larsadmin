<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\ActionRunner;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\QueuedDispatcher;
use Maxcxam\Larsadmin\Actions\Queued\RunPresenter;
use Maxcxam\Larsadmin\Audit\ActionAudit;
use Maxcxam\Larsadmin\Audit\AuditActor;
use Maxcxam\Larsadmin\Audit\AuditRecorder;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * @internal Not part of the public API.
 */
class ActionController
{
    /**
     * Run an action on the selected records (or all matching the index query), or queue it.
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

        if ($action->runsQueued()) {
            return $this->queue($request, $entity, $action, $resources, $fields);
        }

        if (! $entity::audited()) {
            return $this->execute($request, $entity, $action, $resources, $fields, null);
        }

        // One batch id for the whole run; save()/deleteRecord() called by handle() write no entries of their own.
        $recorder = app(AuditRecorder::class);
        $audit = new ActionAudit($entity, $action, $fields, $request, AuditActor::fromRequest($request), $recorder);

        return $recorder->batch(fn () => $this->execute($request, $entity, $action, $resources, $fields, $audit));
    }

    /**
     * Run the action synchronously through the ActionRunner: standalone in one transaction, otherwise per
     * chunk of authorized records, each in its own transaction together with its audit entries.
     *
     * @param  class-string<Entity>  $entity
     * @param  'all'|list<int|string>  $resources
     */
    protected function execute(Request $request, string $entity, Action $action, string|array $resources, ActionFields $fields, ?ActionAudit $audit): RedirectResponse
    {
        $runner = app(ActionRunner::class);

        if ($action->standalone) {
            $result = $runner->run($request, $entity, $action, null, $fields, $audit);

            if ($result->error !== null) {
                throw $result->error;
            }

            return $this->respond($request, $entity, $result->response, 0);
        }

        $builder = ActionRunner::builder($request, $entity, $resources);

        $total = $runner->count($builder);
        $max = max(1, (int) config('larsadmin.actions.max_all', 1000));

        if ($resources === 'all' && $total > $max) {
            throw ValidationException::withMessages([
                'resources' => __('larsadmin::actions.too_many', ['total' => $total, 'max' => $max]),
            ]);
        }

        if ($total === 0) {
            return $this->respond($request, $entity, ActionResponse::danger(__('larsadmin::actions.nothing_matched')), 0);
        }

        $result = $runner->run($request, $entity, $action, $builder, $fields, $audit, total: $total);

        if ($result->error !== null) {
            // Nothing committed: let the exception handler deal with it as usual.
            if ($result->ran === 0) {
                throw $result->error;
            }

            report($result->error);

            return $this->respond($request, $entity, ActionResponse::danger(
                __('larsadmin::actions.partial', ['done' => $result->ran, 'total' => $total]),
            ), $result->skipped);
        }

        // Rows matched but none was authorized (nothing matching is answered above).
        abort_if($result->ran === 0, 403);

        return $this->respond($request, $entity, $result->response, $result->skipped);
    }

    /**
     * Queue the run (ADR-028). With the "sync" queue driver it has already finished: answer like a
     * synchronous run; otherwise confirm and let the client track it.
     *
     * @param  class-string<Entity>  $entity
     * @param  'all'|list<int|string>  $resources
     */
    protected function queue(Request $request, string $entity, Action $action, string|array $resources, ActionFields $fields): RedirectResponse
    {
        $run = app(QueuedDispatcher::class)->dispatch($request, $entity, $action, $resources, $fields);

        if ($run instanceof ActionResponse) {
            return $this->respond($request, $entity, $run, 0);
        }

        if ($run->isFinished()) {
            return $this->respondFinished($request, $entity, $run);
        }

        return redirect($this->backUrl($request, $entity))
            ->with('larsadmin.run', ['id' => (string) $run->getKey()])
            ->with('larsadmin.toast', [
                'message' => __('larsadmin::actions.queued', ['action' => $run->action_name]),
                'type' => 'success',
            ]);
    }

    /**
     * The answer to a run that finished before the response (the "sync" queue driver).
     *
     * @param  class-string<Entity>  $entity
     */
    protected function respondFinished(Request $request, string $entity, ActionRun $run): RedirectResponse
    {
        $result = is_array($run->result) ? $run->result : [];
        $url = RunPresenter::resultUrl($run);

        $redirect = ($result['type'] ?? null) === ActionResponse::REDIRECT && $url !== null
            ? redirect($url)
            : redirect($this->backUrl($request, $entity));

        if ($url !== null && RunPresenter::isDownload($run)) {
            $redirect->with('larsadmin.download', ['url' => $url, 'filename' => RunPresenter::filename($run)]);
        }

        if ($run->message !== null) {
            $redirect->with('larsadmin.toast', [
                'message' => $run->message,
                'type' => $run->status === ActionRun::COMPLETED && $run->level === 'success' ? 'success' : 'error',
            ]);
        }

        return $redirect;
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

        if ($response->type === ActionResponse::FILE) {
            $redirect->with('larsadmin.download', ['url' => DownloadController::url($request, $response), 'filename' => $response->filename]);
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
