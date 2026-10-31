<?php

namespace Maxcxam\Larsadmin\Actions\Queued;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One run of a queued action (ADR-028). Written by the panel only; hosts may read it (e.g. in an
 * ActionRunFinished listener). The table and connection come from config "larsadmin.actions".
 *
 * @property string $id
 * @property string $entity
 * @property string $action
 * @property string $action_name
 * @property string $user_type
 * @property string $user_id
 * @property string|null $guard
 * @property string $locale
 * @property string $status
 * @property int|null $total
 * @property int $processed
 * @property int $ran
 * @property int $skipped
 * @property string|null $level
 * @property string|null $message
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $payload
 * @property string|null $audit_batch_id
 * @property Carbon|null $cancel_requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ActionRun extends Model
{
    use HasUlids;

    public const string QUEUED = 'queued';

    public const string RUNNING = 'running';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    public const array ACTIVE = [self::QUEUED, self::RUNNING];

    public const array FINISHED = [self::COMPLETED, self::FAILED, self::CANCELLED];

    /** Seconds added to a running run's timeout before it counts as abandoned. */
    public const int STALE_MARGIN = 60;

    /**
     * Runs are created by the panel only, from validated values.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The encrypted payload is never serialized.
     *
     * @var list<string>
     */
    protected $hidden = ['payload'];

    public function getTable(): string
    {
        return (string) config('larsadmin.actions.runs_table', 'larsadmin_action_runs');
    }

    public function getConnectionName(): ?string
    {
        return config('larsadmin.actions.runs_connection');
    }

    /**
     * The runs of a user (morph type and auth identifier).
     *
     * @param  Builder<self>  $query
     */
    public function scopeOwnedBy(Builder $query, Authenticatable $user): void
    {
        $query->where('user_type', $user instanceof Model ? $user->getMorphClass() : $user::class)
            ->where('user_id', (string) $user->getAuthIdentifier());
    }

    /**
     * Queued or running runs.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    /**
     * Whether the run should stop before its next chunk: its owner asked to cancel it, or it is no longer
     * running (abandoned by the stale rule or finished elsewhere). One query, between chunks.
     */
    public function freshCancelRequested(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where(fn (Builder $query) => $query->whereNotNull('cancel_requested_at')->orWhere('status', '!=', self::RUNNING))
            ->exists();
    }

    /**
     * The directory of the files a queued run's file() result is copied to: the panel owns (and prunes) only these.
     */
    public static function filePrefix(string $id): string
    {
        return 'larsadmin/runs/'.strtolower($id).'/';
    }

    /**
     * The seconds without a heartbeat (updated_at, touched per chunk) after which an active run counts as
     * abandoned: a running one after its job timeout (else "larsadmin.actions.stale_after") plus a margin,
     * a queued one after "larsadmin.actions.queued_stale_after".
     */
    public function staleAfter(): int
    {
        if ($this->status === self::QUEUED) {
            return max(60, (int) config('larsadmin.actions.queued_stale_after', 86400));
        }

        try {
            $timeout = $this->payload['timeout'] ?? null;
        } catch (Throwable) {
            $timeout = null;
        }

        $seconds = is_int($timeout) && $timeout > 0 ? $timeout : max(60, (int) config('larsadmin.actions.stale_after', 3600));

        return $seconds + self::STALE_MARGIN;
    }

    /**
     * Whether the run is queued or running but its heartbeat is older than staleAfter().
     */
    public function isStale(): bool
    {
        return $this->isActive()
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subSeconds($this->staleAfter()));
    }

    /**
     * The user's active runs that are still alive; stale ones are failed on the way (RunQueuedAction::abandon()).
     *
     * @return EloquentCollection<int, self>
     */
    public static function liveFor(Authenticatable $user): EloquentCollection
    {
        return self::withoutStale(static::query()->ownedBy($user)->active()->get());
    }

    /**
     * Fail the stale runs of the collection (once each, notifying their owner) and return the others.
     *
     * @param  EloquentCollection<int, self>  $runs
     * @return EloquentCollection<int, self>
     */
    public static function withoutStale(EloquentCollection $runs): EloquentCollection
    {
        return $runs->reject(fn (self $run) => $run->isStale() && RunQueuedAction::abandon($run))->values();
    }

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'processed' => 'integer',
            'ran' => 'integer',
            'skipped' => 'integer',
            'result' => 'array',
            'payload' => 'encrypted:array',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
