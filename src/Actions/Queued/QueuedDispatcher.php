<?php

namespace Maxcxam\Larsadmin\Actions\Queued;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\ActionRunner;
use Maxcxam\Larsadmin\Audit\AuditActor;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Throwable;
use UnitEnum;

/**
 * Puts a queued action run on the queue (ADR-028): the per-user limit of active runs, the selection
 * count ("queued_max_all"), the run row with its encrypted payload and the job carrying only the run id.
 *
 * The limit is atomic per user: the count and the insert run under a cache lock (when the cache store
 * supports locks), and the inserted run is re-counted afterwards and withdrawn when over the limit, so
 * concurrent requests can never exceed it, even without locks.
 *
 * @internal Not part of the public API.
 */
class QueuedDispatcher
{
    /** Seconds a dispatch waits for the user's lock. */
    public const int LOCK_WAIT = 5;

    /** @var array<string, true> the connection/timeout pairs already warned about */
    private static array $warned = [];

    public function __construct(private readonly ActionRunner $runner, private readonly Dispatcher $bus) {}

    /**
     * Queue the run; an ActionResponse answers the user at once instead (limits, nothing matched, queue down).
     *
     * @param  class-string<Entity>  $entity
     * @param  'all'|list<int|string>  $resources
     *
     * @throws ValidationException when 'all' matches more than "queued_max_all" records
     * @throws LogicException when a field value (Field::actionValue()) cannot be queued, before anything is stored
     */
    public function dispatch(Request $request, string $entity, Action $action, string|array $resources, ActionFields $fields): ActionRun|ActionResponse
    {
        $user = $request->user(Larsadmin::guard());

        if ($user === null) {
            abort(403);
        }

        // Fail fast, before the run row exists: every value must survive the encrypted JSON payload.
        $values = self::normalize($fields->toArray());

        $max = max(1, (int) config('larsadmin.actions.queued_max_active', 3));

        $run = $this->locked($user, function () use ($request, $entity, $action, $resources, $values, $user, $max) {
            try {
                $active = ActionRun::liveFor($user)->count();
            } catch (Throwable $e) {
                Inbox::rethrow($e);
            }

            if ($active >= $max) {
                return ActionResponse::danger(__('larsadmin::actions.queued_limit', ['max' => $max]));
            }

            $total = null;

            if (! $action->standalone) {
                $total = $this->runner->count(ActionRunner::builder($request, $entity, $resources));
                $limit = max(1, (int) config('larsadmin.actions.queued_max_all', 10000));

                if ($resources === 'all' && $total > $limit) {
                    throw ValidationException::withMessages([
                        'resources' => __('larsadmin::actions.too_many', ['total' => $total, 'max' => $limit]),
                    ]);
                }

                if ($total === 0) {
                    return ActionResponse::danger(__('larsadmin::actions.nothing_matched'));
                }
            }

            $run = $this->create($request, $entity, $action, $resources, $values, $user, $total);

            // Re-count after the insert: a concurrent request that slipped past the count loses here.
            $live = ActionRun::query()->ownedBy($user)->active()->orderBy('created_at')->orderBy('id')->get(['id', 'status', 'updated_at', 'created_at', 'payload'])
                ->reject(fn (ActionRun $other) => $other->isStale());

            if ($live->search(fn (ActionRun $other) => $other->is($run)) >= $max) {
                $run->delete();

                return ActionResponse::danger(__('larsadmin::actions.queued_limit', ['max' => $max]));
            }

            return $run;
        });

        if ($run instanceof ActionResponse) {
            return $run;
        }

        $connection = $action->queueConnection ?? config('larsadmin.actions.queue_connection');
        $this->warnAboutRetryAfter($connection, $action->timeout);

        $job = (new RunQueuedAction((string) $run->getKey(), $action->timeout))
            ->onConnection($connection)
            ->onQueue($action->queue ?? config('larsadmin.actions.queue'));

        try {
            $this->bus->dispatch($job);
        } catch (Throwable $e) {
            report($e);

            // The job may have run already (the "sync" driver rethrows its exceptions): then its answer stands.
            if ($run->refresh()->isFinished()) {
                return $run;
            }

            ActionRun::query()->whereKey($run->getKey())->whereIn('status', ActionRun::ACTIVE)->update([
                'status' => ActionRun::FAILED,
                'level' => 'danger',
                'message' => __('larsadmin::actions.run_error'),
                'payload' => null,
                'finished_at' => now(),
            ]);

            return ActionResponse::danger(__('larsadmin::actions.queue_unavailable'));
        }

        return $run->refresh();
    }

    /**
     * Run the callback under the user's dispatch lock, when the cache store supports locks.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|ActionResponse
     */
    protected function locked(Authenticatable $user, Closure $callback): mixed
    {
        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            return $callback();
        }

        $type = $user instanceof Model ? $user->getMorphClass() : $user::class;
        $lock = Cache::lock('larsadmin:runs:'.sha1($type.'|'.$user->getAuthIdentifier()), 30);

        try {
            return $lock->block(self::LOCK_WAIT, $callback);
        } catch (LockTimeoutException) {
            return ActionResponse::danger(__('larsadmin::actions.queued_limit', ['max' => max(1, (int) config('larsadmin.actions.queued_max_active', 3))]));
        }
    }

    /**
     * @param  class-string<Entity>  $entity
     * @param  'all'|list<int|string>  $resources
     * @param  array<array-key, mixed>  $values  the field values, already normalized for the queue
     */
    protected function create(Request $request, string $entity, Action $action, string|array $resources, array $values, Authenticatable $user, ?int $total): ActionRun
    {
        $filters = $request->input('filters');
        $search = $request->input('search');

        return ActionRun::query()->create([
            'entity' => $entity::uriKey(),
            'action' => $action->uriKey(),
            'action_name' => mb_substr($action->name(), 0, 255),
            'user_type' => $user instanceof Model ? $user->getMorphClass() : $user::class,
            'user_id' => (string) $user->getAuthIdentifier(),
            'guard' => Larsadmin::guard() ?? config('auth.defaults.guard'),
            'locale' => mb_substr(app()->getLocale(), 0, 12),
            'status' => ActionRun::QUEUED,
            'total' => $total,
            'audit_batch_id' => $entity::audited() ? (string) Str::uuid() : null,
            'payload' => [
                'selection' => $action->standalone ? [] : $resources,
                'filters' => $resources === 'all' && is_array($filters) ? $filters : [],
                'search' => $resources === 'all' && is_string($search) ? $search : '',
                'fields' => $values,
                'origin_url' => self::originUrl($request),
                'actor' => AuditActor::fromRequest($request)->toArray(),
                'timeout' => $action->timeout,
            ],
        ]);
    }

    /**
     * The URL the worker replays: the configured application URL (or the panel domain) with the request's
     * path, so the run never depends on the request's Host header. Falls back to the request URL.
     */
    public static function originUrl(Request $request): string
    {
        $app = parse_url((string) config('app.url'));

        if (! is_array($app) || ! in_array($app['scheme'] ?? null, ['http', 'https'], true) || ! isset($app['host'])) {
            return $request->url();
        }

        $domain = config('larsadmin.domain');
        $host = is_string($domain) && $domain !== '' ? $domain : $app['host'].(isset($app['port']) ? ':'.$app['port'] : '');

        return $app['scheme'].'://'.$host.'/'.ltrim($request->path(), '/');
    }

    /**
     * Log once per process when a job may outlive its connection's "retry_after": it would be redelivered
     * while it still runs (the redelivery then leaves it alone, but the queue holds it twice).
     */
    protected function warnAboutRetryAfter(?string $connection, ?int $timeout): void
    {
        $connection ??= config('queue.default');
        $retryAfter = is_string($connection) ? config("queue.connections.{$connection}.retry_after") : null;

        if ($timeout === null || ! is_numeric($retryAfter) || $timeout < (int) $retryAfter || isset(self::$warned[$connection.'|'.$timeout])) {
            return;
        }

        self::$warned[$connection.'|'.$timeout] = true;

        Log::warning("Larsadmin: a queued action has a timeout of {$timeout} s, but the queue connection [{$connection}] has a retry_after of {$retryAfter} s. Set retry_after above the action's timeout (and the worker's --timeout).");
    }

    /**
     * Reset the one-time warnings (tests).
     */
    public static function flushState(): void
    {
        self::$warned = [];
    }

    /**
     * Field values as they survive JSON: dates as ISO-8601 strings, enums as their values, non-finite
     * numbers as null; any other object fails fast, naming the field (the top-level key) it came from.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     *
     * @throws LogicException for values that cannot be queued
     */
    public static function normalize(array $values, ?string $field = null): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $name = $field ?? (string) $key;

            $normalized[$key] = match (true) {
                $value === null, is_string($value), is_int($value), is_bool($value) => $value,
                is_float($value) => is_finite($value) ? $value : null,
                is_array($value) => self::normalize($value, $name),
                $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
                $value instanceof BackedEnum => $value->value,
                $value instanceof UnitEnum => $value->name,
                default => throw new LogicException(sprintf('A queued action cannot carry the value of the field [%s] of type [%s]; use scalars, arrays, dates or enums (see Field::actionValue()).', $name, get_debug_type($value))),
            };
        }

        return $normalized;
    }
}
