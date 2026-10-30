<?php

namespace Maxcxam\Larsadmin\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Filters\DateRangeFilter;
use Maxcxam\Larsadmin\Filters\Filter;
use Maxcxam\Larsadmin\Filters\SelectFilter;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * The filters of the global audit page (entity, event, actor, date range), in the Filter JSON shape of
 * the index. Invalid values are ignored, as on the index (NFR-028).
 *
 * @internal Not part of the public API.
 */
final class AuditFilters
{
    public const array EVENTS = ['created', 'updated', 'deleted', 'action', 'attached', 'detached'];

    /**
     * The most actors offered in the actor filter.
     */
    public const int MAX_ACTORS = 200;

    /**
     * How long the actor options are cached per viewer.
     */
    public const int CACHE_SECONDS = 300;

    /**
     * @var array<string, Filter>
     */
    private array $filters;

    /**
     * @var array<string, mixed>
     */
    private array $active = [];

    /**
     * @param  array<int, class-string<Entity>>  $entities  the entities the viewer may list
     */
    public function __construct(private readonly Request $request, private readonly array $entities)
    {
        $this->filters = [
            'entity' => SelectFilter::make(__('larsadmin::ui.audit_filter_entity'), 'entity', 'entity')
                ->withOptions(collect($entities)->mapWithKeys(fn (string $entity) => [$entity::uriKey() => $entity::label()])->all()),
            'event' => SelectFilter::make(__('larsadmin::ui.audit_filter_event'), 'event', 'event')
                ->withOptions(collect(self::EVENTS)->mapWithKeys(fn (string $event) => [$event => self::eventLabel($event)])->all()),
            // Validated by format ("type|id" or "system"), not by the option list: actors beyond the
            // option cap stay filterable by URL.
            'actor' => (new class(__('larsadmin::ui.audit_filter_actor'), 'actor_id', 'actor') extends SelectFilter
            {
                public function normalize(Request $request, mixed $raw): mixed
                {
                    return is_string($raw) && ($raw === 'system' || preg_match('/^[^|\x00-\x1F]{1,255}\|[^|\x00-\x1F]{1,64}$/', $raw) === 1)
                        ? $raw
                        : null;
                }
            })->withOptions(fn () => $this->actorOptions()),
            'date' => DateRangeFilter::make(__('larsadmin::ui.audit_filter_date'), 'created_at', 'date'),
        ];
    }

    /**
     * The entries the viewer may see: entities registered now that the viewer may list.
     *
     * @return Builder<AuditEntry>
     */
    public function scope(): Builder
    {
        return AuditEntry::query()->whereIn('entity', array_map(fn (string $entity) => $entity::uriKey(), $this->entities));
    }

    /**
     * Apply the valid filters of the request (`filters[key]=value`) to the query.
     *
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public function apply(Builder $query): Builder
    {
        $raw = $this->request->query('filters');
        $raw = is_array($raw) ? $raw : [];

        foreach ($this->filters as $key => $filter) {
            $value = array_key_exists($key, $raw) ? $filter->normalize($this->request, $raw[$key]) : null;

            if ($value === null) {
                continue;
            }

            $this->active[$key] = $value;

            match ($key) {
                'entity', 'event' => $query->where($key, $value),
                'actor' => $this->applyActor($query, $value),
                'date' => $this->applyDate($query, $value),
            };
        }

        return $query;
    }

    /**
     * The valid values applied by apply(), keyed by filter key.
     *
     * @return array<string, mixed>
     */
    public function active(): array
    {
        return $this->active;
    }

    /**
     * The `filters` prop.
     *
     * @return list<array<string, mixed>>
     */
    public function serialize(): array
    {
        return collect($this->filters)
            ->map(fn (Filter $filter, string $key) => $filter->serialize($this->request, $this->active[$key] ?? null))
            ->values()
            ->all();
    }

    /**
     * The label of an event in the current locale, without the placeholders of the entry texts.
     */
    public static function eventLabel(string $event): string
    {
        $text = __("larsadmin::ui.audit_event_{$event}");

        // "Дія «:action»", "Attached :entity: :title": keep the words before the first placeholder.
        $label = trim((string) preg_replace('/[\s«“"\'„]*:[a-z]+.*$/su', '', $text));

        return $label !== '' ? $label : $event;
    }

    /**
     * The actors of the visible entries ("type|id" => label, at most MAX_ACTORS, sorted by label), plus
     * the system. The aggregate is cached per viewer and entity set for CACHE_SECONDS; a label shared
     * by several actors gets their id appended.
     *
     * @return array<string, string>
     */
    private function actorOptions(): array
    {
        $user = $this->request->user(Larsadmin::guard());
        $key = 'larsadmin.audit.actors.'.hash('xxh128', implode('|', [
            $user !== null ? $user::class.':'.$user->getAuthIdentifier() : '',
            ...array_map(fn (string $entity) => $entity::uriKey(), $this->entities),
        ]));

        /** @var list<array{value: string, id: string, label: string}> $actors */
        $actors = Cache::remember($key, self::CACHE_SECONDS, fn () => $this->scope()->toBase()
            ->whereNotNull('actor_id')
            ->select('actor_type', 'actor_id')
            ->selectRaw('max(actor_label) as label')
            ->groupBy('actor_type', 'actor_id')
            ->limit(self::MAX_ACTORS)
            ->get()
            ->map(fn (object $actor) => [
                'value' => $actor->actor_type.'|'.$actor->actor_id,
                'id' => (string) $actor->actor_id,
                'label' => (string) ($actor->label ?? '#'.$actor->actor_id),
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all());

        $counts = array_count_values(array_column($actors, 'label'));
        $options = [];

        foreach ($actors as $actor) {
            $options[$actor['value']] = $counts[$actor['label']] > 1 ? "{$actor['label']} (#{$actor['id']})" : $actor['label'];
        }

        return $options + ['system' => __('larsadmin::ui.audit_system')];
    }

    /**
     * @param  Builder<AuditEntry>  $query
     */
    private function applyActor(Builder $query, string $value): void
    {
        if ($value === 'system') {
            $query->whereNull('actor_id');

            return;
        }

        $separator = strrpos($value, '|');

        $query->where('actor_type', substr($value, 0, (int) $separator))->where('actor_id', substr($value, (int) $separator + 1));
    }

    /**
     * Dates are days in the panel timezone; "to" is inclusive.
     *
     * @param  Builder<AuditEntry>  $query
     * @param  array{from: ?string, to: ?string}  $value
     */
    private function applyDate(Builder $query, array $value): void
    {
        $timezone = Larsadmin::timezone($this->request);
        $storage = (string) config('app.timezone', 'UTC');

        if ($value['from'] !== null) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $value['from'], $timezone)->setTimezone($storage));
        }

        if ($value['to'] !== null) {
            $query->where('created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $value['to'], $timezone)->addDay()->setTimezone($storage));
        }
    }
}
