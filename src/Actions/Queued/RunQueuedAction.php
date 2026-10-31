<?php

namespace Maxcxam\Larsadmin\Actions\Queued;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\ActionRunner;
use Maxcxam\Larsadmin\Actions\RunResult;
use Maxcxam\Larsadmin\Audit\ActionAudit;
use Maxcxam\Larsadmin\Audit\AuditActor;
use Maxcxam\Larsadmin\Audit\AuditRecorder;
use Maxcxam\Larsadmin\Events\ActionRunFinished;
use Maxcxam\Larsadmin\Files\FileTypes;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Maxcxam\Larsadmin\Realtime\PanelSignal;
use Maxcxam\Larsadmin\Realtime\Realtime;
use RuntimeException;
use Throwable;

/**
 * Runs one queued action run (ADR-028): claims it atomically, acts as the user who queued it, repeats every
 * authorization check (fail closed, again before every chunk), runs the chunks through the ActionRunner with
 * a heartbeat, progress and cancellation, then finishes the run: status, message, result, notification,
 * signal and event.
 *
 * Every final transition is conditional (only from queued/running), so a run is finished, and its owner
 * notified, exactly once, whatever redeliveries, failed() calls or stale sweeps race with it.
 *
 * The queue payload is the run id only. No retries: handle() of an action is not necessarily idempotent.
 * The connection's "retry_after" must exceed the job timeout, or a long run is redelivered while it runs.
 *
 * @internal Not part of the public API.
 */
class RunQueuedAction implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    public ?int $timeout = null;

    /** The time of the last progress signal (at most one per second). */
    private float $signalledAt = 0.0;

    /** Why the runner was stopped between chunks: cancelled, unauthorized or abandoned. */
    private ?string $stopReason = null;

    public function __construct(public string $runId, ?int $timeout = null)
    {
        $this->timeout = $timeout;
    }

    public function handle(): void
    {
        $run = ActionRun::query()->find($this->runId);

        if ($run !== null) {
            self::inLocale($run, fn () => $this->process($run));
        }
    }

    /**
     * Run the callback in the run's locale (messages and notifications), restoring the previous one.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inLocale(ActionRun $run, Closure $callback): mixed
    {
        $locale = app()->getLocale();
        app()->setLocale($run->locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($locale);
        }
    }

    protected function process(ActionRun $run): void
    {
        $claimed = ActionRun::query()
            ->whereKey($run->getKey())
            ->where('status', ActionRun::QUEUED)
            ->update(['status' => ActionRun::RUNNING, 'started_at' => now(), 'updated_at' => now()]);

        // Delivered again, cancelled while queued, or swept as stale.
        if ($claimed === 0) {
            return;
        }

        $run->refresh();
        $user = ReplayedRequest::user($run);

        if ($run->cancel_requested_at !== null) {
            self::finish($run, ActionRun::CANCELLED, 'warning', __('larsadmin::actions.run_cancelled', ['done' => 0, 'total' => (int) $run->total]), null, $user);

            return;
        }

        try {
            $payload = $run->payload;
        } catch (Throwable $e) {
            report($e);
            self::finish($run, ActionRun::FAILED, 'danger', __('larsadmin::actions.run_error'), null, $user);

            return;
        }

        if ($user === null || ! is_array($payload)) {
            self::finish($run, ActionRun::FAILED, 'danger', __('larsadmin::actions.run_unauthorized'), null, $user);

            return;
        }

        ReplayedRequest::act($run, $user, $payload, function (Request $request, Authenticatable $user) use ($run, $payload) {
            try {
                $this->execute($run, $request, $user, $payload);
            } catch (Throwable $e) {
                report($e);
                $run->refresh();
                self::finish($run, ActionRun::FAILED, 'danger', self::errorMessage($run), null, $user);
            }
        });
    }

    /**
     * The framework gave up on the job (timeout, crash, attempts exceeded). A redelivery of a run that is still
     * alive elsewhere (MaxAttemptsExceeded with a fresh heartbeat) is left to its worker; anything else is
     * failed, once.
     */
    public function failed(?Throwable $e = null): void
    {
        $run = ActionRun::query()->find($this->runId);

        if ($run === null || $run->isFinished()) {
            return;
        }

        if ($e instanceof MaxAttemptsExceededException && $run->status === ActionRun::RUNNING && ! $run->isStale()) {
            return;
        }

        self::inLocale($run, fn () => self::finish($run, ActionRun::FAILED, 'danger', self::errorMessage($run), null, ReplayedRequest::user($run)));
    }

    /**
     * Fail an abandoned (stale) run, unless its heartbeat moved since it was read. Its payload is erased and its
     * owner notified, like any other finish. Returns whether this call finished it.
     */
    public static function abandon(ActionRun $run, string $status = ActionRun::FAILED): bool
    {
        $heartbeat = $run->updated_at;

        return self::inLocale($run, fn () => self::finish(
            $run,
            $status,
            $status === ActionRun::CANCELLED ? 'warning' : 'danger',
            $status === ActionRun::CANCELLED
                ? __('larsadmin::actions.run_cancelled', ['done' => (int) $run->ran, 'total' => (int) $run->total])
                : self::errorMessage($run),
            null,
            ReplayedRequest::user($run),
            $heartbeat,
        ));
    }

    /**
     * Repeat the checks of the HTTP request as the user, then run the chunks.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function execute(ActionRun $run, Request $request, Authenticatable $user, array $payload): void
    {
        $unauthorized = fn () => self::finish($run, ActionRun::FAILED, 'danger', __('larsadmin::actions.run_unauthorized'), null, $user);

        if (! Larsadmin::check($request)) {
            $unauthorized();

            return;
        }

        $entity = Larsadmin::entityForKey($run->entity);

        if ($entity === null || ! $entity::authorizedToViewAny($request)) {
            $unauthorized();

            return;
        }

        $action = ActionRegistry::for($entity, $request)[$run->action] ?? null;

        if ($action === null || ($action->standalone && ! $action->authorizedToRunStandalone($request))) {
            $unauthorized();

            return;
        }

        $runner = app(ActionRunner::class);
        $selection = $payload['selection'] ?? [];
        $builder = null;
        $total = null;

        if (! $action->standalone) {
            if ($selection !== 'all' && (! is_array($selection) || $selection === [])) {
                $unauthorized();

                return;
            }

            $builder = ActionRunner::builder($request, $entity, $selection);
            $total = $runner->count($builder);

            ActionRun::query()->whereKey($run->getKey())->where('status', ActionRun::RUNNING)->update(['total' => $total, 'updated_at' => now()]);
            $run->forceFill(['total' => $total])->syncOriginal();

            if ($total === 0) {
                self::finish($run, ActionRun::COMPLETED, 'warning', __('larsadmin::actions.nothing_matched'), null, $user);

                return;
            }

            $max = max(1, (int) config('larsadmin.actions.queued_max_all', 10000));

            // The cap of the dispatch applies again: more rows may match by now.
            if ($selection === 'all' && $total > $max) {
                self::finish($run, ActionRun::FAILED, 'danger', __('larsadmin::actions.too_many', ['total' => $total, 'max' => $max]), null, $user);

                return;
            }
        }

        $fields = new ActionFields(is_array($payload['fields'] ?? null) ? $payload['fields'] : []);
        $recorder = app(AuditRecorder::class);
        $audit = $entity::audited()
            ? new ActionAudit($entity, $action, $fields, $request, AuditActor::fromArray(is_array($payload['actor'] ?? null) ? $payload['actor'] : []), $recorder, ['run' => (string) $run->getKey()])
            : null;

        // The heartbeat: progress is written after each committed chunk, only while the run is still running.
        $progress = function (int $ran, int $skipped) use ($run) {
            ActionRun::query()->whereKey($run->getKey())->where('status', ActionRun::RUNNING)->update([
                'processed' => $ran + $skipped,
                'ran' => $ran,
                'skipped' => $skipped,
                'updated_at' => now(),
            ]);

            $run->forceFill(['processed' => $ran + $skipped, 'ran' => $ran, 'skipped' => $skipped])->syncOriginal();

            if (microtime(true) - $this->signalledAt >= 1.0) {
                $this->signalledAt = microtime(true);
                Realtime::signal(PanelSignal::run($run));
            }
        };

        $go = fn () => $runner->run($request, $entity, $action, $builder, $fields, $audit, $progress, fn () => $this->shouldStop($run, $request, $user), $total);

        /** @var RunResult $result */
        $result = $audit !== null ? $recorder->batch(fn () => $go(), $run->audit_batch_id) : $go();

        $run->forceFill(['processed' => $result->ran + $result->skipped, 'ran' => $result->ran, 'skipped' => $result->skipped]);

        $this->conclude($run, $result, $user, $builder === null);
    }

    /**
     * Before every chunk after the first: stop when cancelled or no longer running, and re-check that the user
     * still exists and may still use the panel (rights revoked during a long run take effect at once).
     */
    protected function shouldStop(ActionRun $run, Request $request, Authenticatable $user): bool
    {
        if ($run->freshCancelRequested()) {
            $this->stopReason = ActionRun::query()->whereKey($run->getKey())->where('status', ActionRun::RUNNING)->exists() ? 'cancelled' : 'abandoned';

            return true;
        }

        try {
            $present = $user instanceof Model ? (bool) $user->refresh() : ReplayedRequest::user($run) !== null;
        } catch (Throwable) {
            $present = false;
        }

        if (! $present || ! Larsadmin::check($request)) {
            $this->stopReason = 'unauthorized';

            return true;
        }

        return false;
    }

    /**
     * Map the runner's result to the run's final status (design §8.4).
     */
    protected function conclude(ActionRun $run, RunResult $result, Authenticatable $user, bool $standalone): void
    {
        $total = (int) $result->total;

        if ($result->cancelled) {
            match ($this->stopReason) {
                // Finished elsewhere (stale sweep): nothing left to do here.
                'abandoned' => null,
                'unauthorized' => self::finish($run, ActionRun::FAILED, 'danger', __('larsadmin::actions.run_unauthorized'), null, $user),
                default => self::finish($run, ActionRun::CANCELLED, 'warning', __('larsadmin::actions.run_cancelled', ['done' => $result->ran, 'total' => $total]), null, $user),
            };

            return;
        }

        if ($result->error !== null) {
            report($result->error);

            self::finish($run, ActionRun::FAILED, 'danger', $result->ran > 0
                ? __('larsadmin::actions.partial', ['done' => $result->ran, 'total' => $total])
                : __('larsadmin::actions.run_error'), null, $user);

            return;
        }

        if (! $standalone && $result->ran === 0) {
            self::finish($run, ActionRun::FAILED, 'danger', __('larsadmin::actions.run_unauthorized'), null, $user);

            return;
        }

        $response = $result->response;
        $completed = __('larsadmin::actions.completed');

        match ($response?->type) {
            ActionResponse::DANGER => self::finish($run, ActionRun::COMPLETED, 'danger', (string) $response->message, null, $user),
            ActionResponse::REDIRECT => self::finish($run, ActionRun::COMPLETED, 'success', $completed, ['type' => ActionResponse::REDIRECT, 'url' => $response->url], $user),
            ActionResponse::DOWNLOAD => self::finish($run, ActionRun::COMPLETED, 'success', $completed, ['type' => ActionResponse::DOWNLOAD, 'url' => $response->url, 'filename' => $response->filename], $user),
            ActionResponse::FILE => self::finish($run, ActionRun::COMPLETED, 'success', $completed, self::ownFile($run, $response), $user),
            default => self::finish($run, ActionRun::COMPLETED, 'success', ($response->message ?? $completed)
                .($result->skipped > 0 ? ' '.trans_choice('larsadmin::actions.skipped', $result->skipped) : ''), null, $user),
        };
    }

    /**
     * Copy a file() result into the run's own directory ("larsadmin/runs/{run}/" on "larsadmin.files.disk"):
     * the panel serves and prunes only that copy and never touches the host's file. A failing copy is
     * reported and leaves the run without a link.
     *
     * @return array<string, string>|null
     */
    protected static function ownFile(ActionRun $run, ActionResponse $response): ?array
    {
        $disk = (string) config('larsadmin.files.disk', 'local');
        $name = trim(FileTypes::sanitizeName((string) $response->filename));
        $name = $name !== '' && $name !== '.' && $name !== '..' ? $name : 'download';
        $path = ActionRun::filePrefix((string) $run->getKey()).$name;

        try {
            $stream = Storage::disk((string) $response->disk)->readStream((string) $response->path);

            if (! is_resource($stream)) {
                throw new RuntimeException("The file [{$response->path}] of a queued action result cannot be read.");
            }

            try {
                if (! Storage::disk($disk)->writeStream($path, $stream)) {
                    throw new RuntimeException("The file of a queued action result cannot be written to [{$disk}:{$path}].");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return ['type' => ActionResponse::FILE, 'disk' => $disk, 'path' => $path, 'filename' => (string) $response->filename];
    }

    /**
     * Move the run from queued/running to its final status, then (each step reported on failure, never changing
     * the status) notify the owner, signal the client and dispatch ActionRunFinished. Does nothing, and returns
     * false, when the run already finished or, with $heartbeat, when its heartbeat moved since then.
     *
     * @param  array<string, mixed>|null  $result
     */
    public static function finish(ActionRun $run, string $status, string $level, string $message, ?array $result, ?Authenticatable $user, ?Carbon $heartbeat = null): bool
    {
        $now = now();
        $values = [
            'status' => $status,
            'level' => $level,
            'message' => mb_substr($message, 0, 2000),
            'processed' => (int) $run->processed,
            'ran' => (int) $run->ran,
            'skipped' => (int) $run->skipped,
            'payload' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ];

        $query = ActionRun::query()->whereKey($run->getKey())->whereIn('status', ActionRun::ACTIVE);

        if ($heartbeat !== null) {
            $query->where('updated_at', '<=', $heartbeat);
        }

        if ($query->update([...$values, 'result' => $result !== null ? json_encode($result) : null]) === 0) {
            return false;
        }

        $run->forceFill([...$values, 'result' => $result])->syncOriginal();

        if ($user !== null && Inbox::supports($user)) {
            try {
                self::inLocale($run, fn () => $user->notify(RunPresenter::notification($run)));
            } catch (Throwable $e) {
                report($e);
            }
        }

        Realtime::signal(PanelSignal::run($run));

        try {
            event(new ActionRunFinished($run));
        } catch (Throwable $e) {
            report($e);
        }

        return true;
    }

    /**
     * "partial" when some records were handled, else "run_error" (the exception text is never shown).
     */
    private static function errorMessage(ActionRun $run): string
    {
        return $run->ran > 0
            ? __('larsadmin::actions.partial', ['done' => (int) $run->ran, 'total' => (int) $run->total])
            : __('larsadmin::actions.run_error');
    }
}
