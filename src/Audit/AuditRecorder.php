<?php

namespace Maxcxam\Larsadmin\Audit;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Events\AuditRecorded;
use RuntimeException;
use Throwable;

/**
 * Writes audit entries inside the transaction of the change they describe (ADR-022): a failing insert
 * rolls the change back, a rollback discards the entry. On another connection than the record's the
 * entry is written after the commit instead (no atomicity). Bound as a scoped singleton.
 *
 * @internal Not part of the public API.
 */
final class AuditRecorder
{
    private ?string $batchId = null;

    /**
     * The records whose seams write nothing: the chunk an action is handling ("Entity class|key").
     *
     * @var array<string, true>
     */
    private array $covered = [];

    /**
     * Run the callback as one operation: every entry written inside it shares one batch id.
     *
     * @template T
     *
     * @param  Closure(string): T  $callback  receives the batch id
     * @return T
     */
    public function batch(Closure $callback, ?string $batchId = null): mixed
    {
        $previous = $this->batchId;
        $this->batchId = $batchId ?? $previous ?? (string) Str::uuid();

        try {
            return $callback($this->batchId);
        } finally {
            $this->batchId = $previous;

            if ($previous === null) {
                $this->covered = [];
            }
        }
    }

    /**
     * Mark records as covered by an action entry (or release them with $covered = false): their
     * save()/deleteRecord() seams write no entries of their own. Other seams still record.
     *
     * @param  class-string<Entity>  $entity
     * @param  iterable<Model>  $records
     */
    public function cover(string $entity, iterable $records, bool $covered = true): void
    {
        foreach ($records as $record) {
            $key = $entity.'|'.$record->getKey();

            if ($covered) {
                $this->covered[$key] = true;
            } else {
                unset($this->covered[$key]);
            }
        }
    }

    /**
     * Whether the record's seams must not write entries (an action entry covers it).
     *
     * @param  class-string<Entity>  $entity
     */
    public function covers(string $entity, Model $record): bool
    {
        return $this->covered !== [] && $record->exists && isset($this->covered[$entity.'|'.$record->getKey()]);
    }

    /**
     * Write one entry.
     *
     * @param  class-string<Entity>  $entity
     * @param  array<string, array<string, mixed>>|null  $changes
     * @param  array<string, mixed>  $meta
     */
    public function record(string $event, string $entity, ?Model $record, ?array $changes, array $meta, AuditActor $actor): void
    {
        $this->recordMany([$this->row($event, $entity, $record, $changes, $meta, $actor)], $record ?? $entity::newModel());
    }

    /**
     * Build the columns of one entry (values limited, JSON encoded).
     *
     * @param  class-string<Entity>  $entity
     * @param  array<string, array<string, mixed>>|null  $changes
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function row(string $event, string $entity, ?Model $record, ?array $changes, array $meta, AuditActor $actor): array
    {
        [$changes, $omitted] = AuditValues::limit($changes ?? []);

        if ($omitted) {
            $meta['truncated'] = true;
        }

        return [
            'batch_id' => $this->batchId ?? (string) Str::uuid(),
            'event' => $event,
            'entity' => $entity::uriKey(),
            'auditable_type' => $record?->getMorphClass(),
            'auditable_id' => $record !== null ? (string) $record->getKey() : null,
            ...$actor->columns(),
            'changes' => $changes === [] ? null : AuditValues::json($changes),
            'meta' => $meta === [] ? null : AuditValues::json($meta),
        ];
    }

    /**
     * Write entries built by row() in one insert, in the transaction of the given model's connection.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function recordMany(array $rows, Model $on): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn (array $row) => $row + ['created_at' => $now], $rows);
        $entries = array_map(fn (array $row) => (new AuditEntry)->setRawAttributes($row), $rows);
        $connection = $on->getConnection();

        if (self::connectionName() === ($on->getConnectionName() ?? config('database.default'))) {
            $this->insert($rows, $entries);
        } else {
            $connection->afterCommit(function () use ($rows, $entries) {
                try {
                    $this->insert($rows, $entries);
                } catch (Throwable $e) {
                    // The change is committed without its entries: make the gap visible to monitoring.
                    Log::critical('Larsadmin could not write audit entries on the audit connection; the change is committed without them.', [
                        'batch_id' => $rows[0]['batch_id'],
                        'entity' => $rows[0]['entity'],
                        'keys' => array_column($rows, 'auditable_id'),
                        'exception' => $e,
                    ]);
                    report($e);
                }
            });
        }

        if (Event::hasListeners(AuditRecorded::class)) {
            // A failing listener must not break later after-commit work (file purge) or the response.
            $connection->afterCommit(function () use ($entries) {
                try {
                    event(new AuditRecorded(collect($entries)));
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
    }

    /**
     * The connection of the audit table.
     */
    public static function connectionName(): string
    {
        return (string) (config('larsadmin.audit.connection') ?? config('database.default'));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<AuditEntry>  $entries
     */
    private function insert(array $rows, array $entries): void
    {
        $table = (string) config('larsadmin.audit.table', 'larsadmin_audit_entries');
        $query = DB::connection(self::connectionName())->table($table);

        try {
            if (count($rows) === 1) {
                $entries[0]->setAttribute('id', $query->insertGetId($rows[0]));
            } else {
                $query->insert($rows);
            }
        } catch (QueryException $e) {
            if (self::isMissingTable($e)) {
                throw new RuntimeException(
                    "The audit log table [{$table}] does not exist: run the larsadmin migration "
                    .'(php artisan vendor:publish --tag=larsadmin-migrations && php artisan migrate) or disable the audit log (LARSADMIN_AUDIT=false).',
                    0,
                    $e,
                );
            }

            throw $e;
        }
    }

    private static function isMissingTable(QueryException $e): bool
    {
        return in_array($e->errorInfo[0] ?? null, ['42S02', '42P01'], true)
            || str_contains(strtolower($e->getMessage()), 'no such table');
    }
}
