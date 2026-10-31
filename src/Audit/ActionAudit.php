<?php

namespace Maxcxam\Larsadmin\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;

/**
 * The audit of one action run (ADR-022): the field attributes of each chunk's records are snapshotted
 * before handle() and re-read after it (one query, without global scopes), and one "action" entry per
 * record is inserted in one batch, inside the chunk's transaction. Independent of the HTTP layer
 * (actor given explicitly), so a queued run can reuse it.
 *
 * @internal Not part of the public API.
 */
final class ActionAudit
{
    /**
     * @var Collection<string, Field>|null
     */
    private ?Collection $fields = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $resolvedMeta = null;

    /**
     * @param  class-string<Entity>  $entity
     * @param  array<string, mixed>  $meta  merged into every entry's meta, e.g. ['run' => $id] of a queued run
     */
    public function __construct(
        private readonly string $entity,
        private readonly Action $action,
        private readonly ActionFields $values,
        private readonly Request $request,
        private readonly AuditActor $actor,
        private readonly AuditRecorder $recorder,
        private readonly array $meta = [],
    ) {}

    /**
     * Write the entry of a standalone run (no record).
     */
    public function standalone(): void
    {
        $this->recorder->record('action', $this->entity, null, null, $this->meta(), $this->actor);
    }

    /**
     * The in-memory snapshots of the records the action is about to handle, keyed by record key. The
     * records are covered until after(): save()/deleteRecord() on them inside handle() write no entries
     * of their own (other records still do).
     *
     * @param  Collection<int, Model>  $models
     * @return array<string, array{0: Model, 1: Snapshot}>
     */
    public function before(Collection $models): array
    {
        $snapshots = [];

        foreach ($models as $model) {
            $snapshots[(string) $model->getKey()] = [$model, Snapshot::of($this->fields(), $model)];
        }

        $this->recorder->cover($this->entity, $models);

        return $snapshots;
    }

    /**
     * Re-read the records and write one entry per record with its diff; a record that is gone is
     * marked "record_deleted".
     *
     * @param  array<string, array{0: Model, 1: Snapshot}>  $snapshots
     */
    public function after(array $snapshots): void
    {
        if ($snapshots === []) {
            return;
        }

        [$first] = reset($snapshots);
        $this->recorder->cover($this->entity, array_column($snapshots, 0), false);

        $fresh = $first->newQueryWithoutScopes()
            ->whereKey(array_map(fn (array $snapshot) => $snapshot[0]->getKey(), array_values($snapshots)))
            ->get()
            ->keyBy(fn (Model $model) => (string) $model->getKey());

        $rows = [];

        foreach ($snapshots as $key => [$model, $before]) {
            $current = $fresh->get($key);
            $meta = $this->meta();

            if ($current === null) {
                $meta['record_deleted'] = true;
            }

            // Only attributes both reads loaded: the chunk query may add (withCount) or omit (select) columns.
            $changes = $current === null
                ? $before->diff(Snapshot::empty())
                : $before->diff(Snapshot::of($this->fields(), $current), common: true);

            $rows[] = $this->recorder->row('action', $this->entity, $model, $changes, $meta, $this->actor);
        }

        $this->recorder->recordMany($rows, $first);
    }

    /**
     * The audited fields of the entity.
     *
     * @return Collection<string, Field>
     */
    private function fields(): Collection
    {
        return $this->fields ??= $this->entity::make()->auditedFields($this->request);
    }

    /**
     * The action, its name at run time and its field values (normalized, truncated, Password and
     * sensitive() fields masked).
     *
     * @return array<string, mixed>
     */
    private function meta(): array
    {
        if ($this->resolvedMeta !== null) {
            return $this->resolvedMeta;
        }

        $fields = [];

        foreach ($this->action->editableFields($this->request) as $field) {
            $fields[$field->attribute] = $field->isSensitive()
                ? AuditValues::MASKED
                : AuditValues::truncate(AuditValues::normalize($this->values->get($field->attribute)))[0];
        }

        return $this->resolvedMeta = [
            'action' => $this->action->uriKey(),
            'action_name' => $this->action->name(),
            ...($fields === [] ? [] : ['fields' => $fields]),
            ...$this->meta,
        ];
    }
}
