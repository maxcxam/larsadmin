<?php

namespace Maxcxam\Larsadmin\Audit;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Query\EntityQuery;

/**
 * Serializes audit entries for the viewer (ADR-025): only attributes of current fields the viewer may
 * see, masking re-applied under the current rules, related titles and record links only within the
 * related entity's detail scope and view ability (batched: one query per entity per page).
 *
 * Entries are built twice: the first pass only collects the record keys to resolve.
 *
 * @internal Not part of the public API.
 */
final class AuditPresenter
{
    private bool $collecting = false;

    /**
     * @var array<class-string<Entity>, array<string, true>>
     */
    private array $pending = [];

    /**
     * @var array<class-string<Entity>, array<string, array{title: string, url: string|null}>>
     */
    private array $resolved = [];

    /**
     * @var array<string, Collection<string, Field>>
     */
    private array $fields = [];

    /**
     * @var array<string, array<string, Action>>
     */
    private array $actions = [];

    /**
     * Memoized viewAny abilities of entities.
     *
     * @var array<class-string<Entity>, bool>
     */
    private array $listable = [];

    private string $timezone;

    public function __construct(private readonly Request $request)
    {
        $this->timezone = Larsadmin::timezone($request);
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * @param  iterable<AuditEntry>  $entries  entries of registered entities the viewer may list
     * @param  bool  $global  add the entity, the record, the IP and the user agent (global page)
     * @return list<array<string, mixed>>
     */
    public function present(iterable $entries, bool $global): array
    {
        $this->collecting = true;

        foreach ($entries as $entry) {
            $this->entry($entry, $global);
        }

        $this->collecting = false;
        $this->resolve();

        $presented = [];

        foreach ($entries as $entry) {
            $presented[] = $this->entry($entry, $global);
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(AuditEntry $entry, bool $global): array
    {
        /** @var class-string<Entity>|null $entity */
        $entity = Larsadmin::entityForKey($entry->entity);
        $meta = is_array($entry->meta) ? $entry->meta : [];

        $data = [
            'id' => $entry->id,
            'event' => $entry->event,
            'createdAt' => Carbon::instance($entry->created_at)->setTimezone($this->timezone)->toIso8601String(),
            // The id (and the type on the global page) tell apart actors whose labels look alike,
            // e.g. a user renamed to "System"; the system itself is null.
            'actor' => $entry->actor_id === null && $entry->actor_type === null
                ? null
                : [
                    'label' => $entry->actor_label ?? '#'.$entry->actor_id,
                    'id' => $entry->actor_id,
                    'system' => false,
                    ...($global ? ['type' => $entry->actor_type] : []),
                ],
            'action' => $entry->event === 'action' && $entity !== null ? $this->action($entity, $meta) : null,
            'related' => in_array($entry->event, ['attached', 'detached'], true) && $entity !== null ? $this->related($entity, $meta) : null,
            'soft' => ($meta['soft'] ?? false) === true,
            'recordDeleted' => ($meta['record_deleted'] ?? false) === true,
            'changes' => $entity !== null ? $this->changes($entity, is_array($entry->changes) ? $entry->changes : []) : [],
        ];

        if ($global) {
            $data += [
                'entity' => ['uriKey' => $entry->entity, 'label' => $entity !== null ? $entity::label() : $entry->entity],
                'record' => $entry->auditable_id === null || $entity === null ? null : $this->record($entity, $entry->auditable_id, $entry->auditable_type === $entity::newModel()->getMorphClass()),
                'ip' => $entry->ip_address,
                'userAgent' => $entry->user_agent,
            ];
        }

        return $data;
    }

    /**
     * The current fields of the entity the viewer may see, keyed by attribute (relation panels included,
     * for attach/detach).
     *
     * @param  class-string<Entity>  $entity
     * @return Collection<string, Field>
     */
    private function fields(string $entity): Collection
    {
        return $this->fields[$entity] ??= $entity::make()->fieldPrototypes($this->request)
            ->filter(fn (Field $field) => $field->isAuthorizedToSee($this->request))
            ->keyBy(fn (Field $field) => $field->attribute);
    }

    /**
     * @param  class-string<Entity>  $entity
     * @param  array<string, mixed>  $changes
     * @return list<array<string, mixed>>
     */
    private function changes(string $entity, array $changes): array
    {
        $fields = $this->fields($entity);
        $model = $entity::newModel();
        $rows = [];

        foreach ($changes as $attribute => $change) {
            $field = $fields->get((string) $attribute);

            // Unknown (removed, renamed) and hidden fields, and relation panels, are not shown (fail closed).
            if ($field === null || $field->isRelation() || ! is_array($change)) {
                continue;
            }

            $row = ['attribute' => (string) $attribute, 'label' => $field->name];
            $source = $field instanceof File ? $field->attribute : $field->fillAttribute();

            // A field the viewer never sees on the index or detail page (onlyOnForms(), hideFromDetail())
            // shows only that it changed.
            $hiddenInViews = ! $field->mayShowOn(Field::DETAIL, $this->request) && ! $field->mayShowOn(Field::INDEX, $this->request);

            if (($change['masked'] ?? false) === true || $hiddenInViews || AuditValues::isMasked($field, $model, $source)) {
                $rows[] = $row + ['masked' => true];

                continue;
            }

            $truncated = is_array($change['truncated'] ?? null) ? $change['truncated'] : [];
            $omitted = ($change['omitted'] ?? false) === true;

            foreach (['old', 'new'] as $side) {
                $value = $this->value($field, $change[$side] ?? null);

                if (in_array($side, $truncated, true) || ($omitted && ($change[$side] ?? null) === null)) {
                    $value['truncated'] = true;
                }

                $row[$side] = $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One value by the field type.
     *
     * @return array<string, mixed>
     */
    private function value(Field $field, mixed $value): array
    {
        if ($value === null) {
            return ['kind' => 'empty'];
        }

        if ($field instanceof File) {
            // Names only: paths are never sent. A truncated list (a string) shows no names.
            return ['kind' => 'files', 'files' => is_array($value)
                ? array_values(array_filter(array_map(fn ($file) => is_array($file) && is_string($file['name'] ?? null) ? $file['name'] : null, $value)))
                : []];
        }

        if ($field instanceof BelongsTo && (is_int($value) || is_string($value))) {
            return ['kind' => 'record', 'id' => $value] + $this->recordTitle($field->relatedEntity(), (string) $value);
        }

        if ($field instanceof Boolean && (is_bool($value) || $value === 0 || $value === 1)) {
            return ['kind' => 'boolean', 'value' => (bool) $value];
        }

        if ($field instanceof DateTime && is_string($value)) {
            return ['kind' => 'datetime', 'value' => $value];
        }

        if ($field instanceof Date && is_string($value)) {
            return ['kind' => 'text', 'text' => preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : $value];
        }

        if ($field instanceof Select && is_scalar($value)) {
            foreach ($field->resolvedOptions() as $option) {
                if ((string) $option['value'] === (string) $value) {
                    return ['kind' => 'text', 'text' => $option['label']];
                }
            }
        }

        return match (true) {
            is_bool($value) => ['kind' => 'boolean', 'value' => $value],
            is_scalar($value) => ['kind' => 'text', 'text' => (string) $value],
            default => ['kind' => 'text', 'text' => AuditValues::json($value)],
        };
    }

    /**
     * The action of an "action" entry: its name at run time and the values of the current action's
     * fields the viewer may see (none when the action no longer exists).
     *
     * @param  class-string<Entity>  $entity
     * @param  array<string, mixed>  $meta
     * @return array{name: string, fields: list<array<string, mixed>>}
     */
    private function action(string $entity, array $meta): array
    {
        $key = is_string($meta['action'] ?? null) ? $meta['action'] : '';
        $name = is_string($meta['action_name'] ?? null) ? $meta['action_name'] : $key;
        $values = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];

        $this->actions[$entity] ??= ActionRegistry::for($entity, $this->request);
        $action = $this->actions[$entity][$key] ?? null;
        $fields = [];

        if ($action !== null && $values !== []) {
            foreach ($action->fieldPrototypes($this->request) as $field) {
                if (! array_key_exists($field->attribute, $values) || ! $field->isAuthorizedToSee($this->request)) {
                    continue;
                }

                $value = $values[$field->attribute];

                $fields[] = $field->isSensitive() || (is_array($value) && ($value['masked'] ?? false) === true)
                    ? ['label' => $field->name, 'masked' => true]
                    : ['label' => $field->name, 'value' => $this->value($field, $value)];
            }
        }

        return ['name' => $name, 'fields' => $fields];
    }

    /**
     * The related record of an attach/detach entry. Its title and link are shown only when the panel
     * field is visible to the viewer and the record is in the related entity's scope.
     *
     * @param  class-string<Entity>  $entity
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function related(string $entity, array $meta): ?array
    {
        $relatedKey = is_string($meta['related_entity'] ?? null) ? $meta['related_entity'] : null;
        $id = is_string($meta['related_id'] ?? null) || is_int($meta['related_id'] ?? null) ? (string) $meta['related_id'] : null;

        if ($relatedKey === null || $id === null) {
            return null;
        }

        $related = Larsadmin::entityForKey($relatedKey);
        $listable = $related !== null && ($this->listable[$related] ??= $related::authorizedToViewAny($this->request));
        $panel = $this->fields($entity)->get(is_string($meta['field'] ?? null) ? $meta['field'] : '');
        $visible = $listable && $panel !== null && $panel->isRelation();

        return [
            'entity' => $relatedKey,
            'entityLabel' => $listable ? $related::label() : $relatedKey,
            'id' => $id,
            ...($visible ? $this->recordTitle($related, $id) : ['title' => null, 'url' => null]),
        ];
    }

    /**
     * The audited record on the global page.
     *
     * @param  class-string<Entity>  $entity
     * @return array{id: string, title: string|null, url: string|null}
     */
    private function record(string $entity, string $id, bool $sameModel): array
    {
        return ['id' => $id, ...($sameModel ? $this->recordTitle($entity, $id) : ['title' => null, 'url' => null])];
    }

    /**
     * The title and link of a record, registered for the batch lookup in the first pass.
     *
     * @param  class-string<Entity>  $entity
     * @return array{title: string|null, url: string|null}
     */
    private function recordTitle(string $entity, string $key): array
    {
        if ($this->collecting) {
            $this->pending[$entity][$key] = true;

            return ['title' => null, 'url' => null];
        }

        $record = $this->resolved[$entity][$key] ?? null;

        return ['title' => $record['title'] ?? null, 'url' => $record['url'] ?? null];
    }

    /**
     * Resolve the registered keys: one query per entity through its detail scope, then the view ability
     * per record. Entities that are not registered or not listable resolve nothing.
     */
    private function resolve(): void
    {
        foreach ($this->pending as $entity => $keys) {
            $this->resolved[$entity] = [];

            if (Larsadmin::entityForKey($entity::uriKey()) !== $entity || ! ($this->listable[$entity] ??= $entity::authorizedToViewAny($this->request))) {
                continue;
            }

            $model = $entity::newModel();
            $integerKey = $model->getIncrementing() && in_array($model->getKeyType(), ['int', 'integer'], true);
            $keys = array_values(array_filter(
                array_map('strval', array_keys($keys)),
                fn (string $key) => ! $integerKey || (ctype_digit($key) && strlen($key) <= 18),
            ));

            if ($keys === []) {
                continue;
            }

            // Eager loads as on the detail page, so title() and the policy do not lazy load per row.
            $records = EntityQuery::for($entity, $this->request)->scope('detail')->builder()
                ->with($entity::$with)
                ->whereKey($integerKey ? array_map('intval', $keys) : $keys)
                ->get();

            foreach ($records as $record) {
                /** @var Model $record */
                $instance = $entity::make($record);

                if ($instance->authorizedToView($this->request)) {
                    $this->resolved[$entity][(string) $record->getKey()] = ['title' => $instance->title(), 'url' => $instance->url('show')];
                }
            }
        }

        $this->pending = [];
    }
}
