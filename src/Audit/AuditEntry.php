<?php

namespace Maxcxam\Larsadmin\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry of the audit log (read-only: entries are written by the panel and removed only by
 * `larsadmin:prune-audit`). The table and connection come from config "larsadmin.audit".
 *
 * @property int|null $id
 * @property string $batch_id
 * @property string $event
 * @property string $entity
 * @property string|null $auditable_type
 * @property string|null $auditable_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_guard
 * @property string|null $actor_label
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $meta
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class AuditEntry extends Model
{
    /**
     * Only created_at exists; it is set by the recorder.
     */
    public $timestamps = false;

    /**
     * Entries are inserted by the recorder through the query builder, never by mass assignment.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Instances can be neither created, changed nor deleted through Eloquent (save, push, increment,
     * forceFill()->save(), delete, destroy). Builder-level writes stay possible: database grants are
     * the real guard; only the prune command deletes.
     */
    protected static function booted(): void
    {
        $deny = fn () => throw new LogicException('Audit entries are immutable: they are written by Larsadmin only.');

        // save() and delete() also refuse outright (quiet variants skip events); these catch the
        // remaining instance paths, e.g. incrementEach().
        static::updating($deny);
        static::deleting($deny);
    }

    /**
     * Entries are never saved through Eloquent (also not quietly or by push()).
     *
     * @throws LogicException
     */
    public function save(array $options = []): bool
    {
        throw new LogicException('Audit entries are immutable: they are written by Larsadmin only.');
    }

    /**
     * increment()/decrement() (also quietly) on an instance.
     *
     * @throws LogicException
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Audit entries are immutable: they are written by Larsadmin only.');
    }

    public function getTable(): string
    {
        return (string) config('larsadmin.audit.table', 'larsadmin_audit_entries');
    }

    public function getConnectionName(): ?string
    {
        return config('larsadmin.audit.connection');
    }

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The audited record (may no longer exist).
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Audit entries are immutable.
     *
     * @throws LogicException
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('Audit entries are immutable.');
    }

    /**
     * Audit entries are removed only by `larsadmin:prune-audit`.
     *
     * @throws LogicException
     */
    public function delete(): ?bool
    {
        throw new LogicException('Audit entries cannot be deleted; use `php artisan larsadmin:prune-audit`.');
    }
}
