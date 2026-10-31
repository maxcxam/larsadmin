<?php

namespace Maxcxam\Larsadmin\Mcp\Introspection;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Filters\BooleanFilter;
use Maxcxam\Larsadmin\Filters\Filter;
use Maxcxam\Larsadmin\Filters\SelectFilter;
use Maxcxam\Larsadmin\Larsadmin;
use Throwable;

/**
 * The allow-listed metadata of a registered entity (ADR-038), built from its statics and the unresolved
 * prototypes of fields(), actions(), filters() and cards() called with a synthetic request without a user.
 * No record is loaded, no query, resolve(), default, visibility or option closure runs; a failing host
 * method yields { unavailable, reason } with the exception class only.
 *
 * @internal
 */
final class EntityMetadata
{
    /**
     * The registered entity for a uriKey or class name, or null.
     *
     * @return class-string<Entity>|null
     */
    public static function find(string $entity): ?string
    {
        $entity = ltrim($entity, '\\');

        foreach (Larsadmin::registeredEntities() as $class) {
            if ($class === $entity || self::uriKey($class) === $entity) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The uriKeys of the registered entities (for error messages).
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_filter(array_map(self::uriKey(...), Larsadmin::registeredEntities())));
    }

    /**
     * The list-entities row of an entity.
     *
     * @param  class-string<Entity>  $class
     * @return array<string, mixed>
     */
    public static function summary(string $class, Request $request): array
    {
        $parts = self::parts($class, $request);

        $row = [
            'key' => self::uriKey($class),
            'class' => $class,
            'file' => Metadata::classFile($class),
            'model' => self::static($class, 'model'),
            'label' => self::call(fn () => $class::label()),
            'singularLabel' => self::call(fn () => $class::singularLabel()),
            'group' => self::static($class, 'group'),
            'inNavigation' => self::static($class, 'displayInNavigation'),
            'globallySearchable' => self::static($class, 'globallySearchable'),
            'auditable' => self::static($class, 'auditable'),
            'counts' => [],
        ];

        foreach (['fields', 'actions', 'filters', 'cards'] as $part) {
            $row['counts'][$part] = is_array($parts[$part]) ? count($parts[$part]) : null;
        }

        return $row + self::unavailable($parts);
    }

    /**
     * The describe-entity payload of an entity.
     *
     * @param  class-string<Entity>  $class
     * @return array<string, mixed>
     */
    public static function describe(string $class, Request $request): array
    {
        $parts = self::parts($class, $request);
        $model = self::static($class, 'model');

        $described = [
            'key' => self::uriKey($class),
            'class' => $class,
            'file' => Metadata::classFile($class),
            'model' => $model,
            'statics' => [
                'title' => self::static($class, 'title'),
                'search' => self::static($class, 'search'),
                'with' => self::static($class, 'with'),
                'sort' => self::static($class, 'sort'),
                'perPage' => self::static($class, 'perPage'),
                'perPageOptions' => self::static($class, 'perPageOptions'),
                'group' => self::static($class, 'group'),
                'inNavigation' => self::static($class, 'displayInNavigation'),
                'globallySearchable' => self::static($class, 'globallySearchable'),
                'subtitle' => self::static($class, 'subtitle'),
                'auditable' => self::static($class, 'auditable'),
            ],
            'scoping' => [
                'indexQuery' => Metadata::overridden($class, 'indexQuery', Entity::class),
                'detailQuery' => Metadata::overridden($class, 'detailQuery', Entity::class),
                'relatableQuery' => Metadata::overridden($class, 'relatableQuery', Entity::class),
            ],
            'policy' => self::policy($model),
            'fields' => is_array($parts['fields']) ? array_map(FieldMetadata::describe(...), $parts['fields']) : null,
            'layout' => is_array($parts['layout']) ? self::layout($parts['layout']) : null,
            'actions' => is_array($parts['actions']) ? array_map(fn (Action $action) => self::action($action, $request, $class), $parts['actions']) : null,
            'filters' => is_array($parts['filters']) ? array_map(self::filter(...), $parts['filters']) : null,
            'cards' => is_array($parts['cards']) ? CardMetadata::describeAll($parts['cards']) : null,
        ];

        return $described + self::unavailable($parts) + ['warnings' => self::warnings($class, $described)];
    }

    /**
     * The uriKey of an entity class, or null when it cannot be computed.
     */
    public static function uriKey(string $class): ?string
    {
        return self::call(fn () => $class::uriKey());
    }

    /**
     * The unresolved prototypes of the entity: a list per part, or a Throwable when the host method failed.
     *
     * @param  class-string<Entity>  $class
     * @return array{fields: list<Field>|Throwable, layout: list<array<string, mixed>>|Throwable, actions: list<Action>|Throwable, filters: list<Filter>|Throwable, cards: list<mixed>|Throwable}
     */
    private static function parts(string $class, Request $request): array
    {
        try {
            $entity = $class::make();
        } catch (Throwable $e) {
            return ['fields' => $e, 'layout' => $e, 'actions' => $e, 'filters' => $e, 'cards' => $e];
        }

        $parts = [];

        try {
            $parts['fields'] = $entity->fieldPrototypes($request)->all();
            $parts['layout'] = $entity->fieldLayout($request);
        } catch (Throwable $e) {
            $parts['fields'] = $parts['layout'] = $e;
        }

        foreach (['actions' => Action::class, 'filters' => Filter::class, 'cards' => null] as $part => $type) {
            try {
                $items = array_values((array) $entity->{$part}($request));
                $parts[$part] = $type === null ? $items : array_values(array_filter($items, fn ($item) => $item instanceof $type));
            } catch (Throwable $e) {
                $parts[$part] = $e;
            }
        }

        return $parts;
    }

    /**
     * The "unavailable" entries for the parts whose host method failed.
     *
     * @param  array<string, mixed>  $parts
     * @return array{unavailable?: list<array{unavailable: string, reason: string}>}
     */
    private static function unavailable(array $parts): array
    {
        $unavailable = [];

        foreach (['fields', 'actions', 'filters', 'cards'] as $part) {
            if ($parts[$part] instanceof Throwable) {
                $unavailable[] = ['unavailable' => $part, 'reason' => Metadata::reason($parts[$part])];
            }
        }

        return $unavailable === [] ? [] : ['unavailable' => $unavailable];
    }

    /**
     * The policy of the model as class and file (Gate::getPolicyFor()); no ability is checked.
     *
     * @return array{class: string|null, file: string|null}|null
     */
    private static function policy(mixed $model): ?array
    {
        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        try {
            $policy = Gate::getPolicyFor($model);
        } catch (Throwable) {
            return null;
        }

        return [
            'class' => $policy === null ? null : Metadata::className($policy),
            'file' => $policy === null ? null : Metadata::classFile($policy),
        ];
    }

    /**
     * The layout tree with attributes only: panels (name, columns, collapsible) and tabs, no descriptions.
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    private static function layout(array $tree): array
    {
        $panel = fn (array $node) => [
            'type' => 'panel',
            'name' => is_string($node['name'] ?? null) ? $node['name'] : null,
            'columns' => is_int($node['columns'] ?? null) ? $node['columns'] : 1,
            'collapsible' => (bool) ($node['collapsible'] ?? false),
            'fields' => array_values(array_filter((array) ($node['fields'] ?? []), 'is_string')),
        ];

        return array_map(fn (array $node) => ($node['type'] ?? null) === 'tabs'
            ? [
                'type' => 'tabs',
                'name' => is_string($node['name'] ?? null) ? $node['name'] : null,
                'tabs' => array_map($panel, (array) ($node['tabs'] ?? [])),
            ]
            : $panel($node), $tree);
    }

    /**
     * An action's metadata: flags, how canSee/canRun are decided and its field prototypes; never the
     * confirmation texts, callbacks or field defaults.
     *
     * @return array<string, mixed>
     */
    public static function action(Action $action, Request $request, ?string $entity = null): array
    {
        $showOn = array_keys(array_filter([
            'index' => $action->showOnIndex,
            'detail' => $action->showOnDetail,
            'inline' => $action->showInline,
        ]));

        $described = [
            'uriKey' => self::call(fn () => $action->uriKey()),
            'class' => Metadata::className($action),
            'file' => Metadata::classFile($action),
            'name' => self::call(fn () => $action->name()),
            'destructive' => $action->destructive,
            'standalone' => $action->standalone,
            'queued' => $action->queued,
            'showOn' => $showOn,
            'canSee' => Metadata::callbackFlag(Metadata::property($action, 'canSeeCallback', Action::class), $action, 'authorizedToSee', Action::class),
            'canRun' => Metadata::callbackFlag(Metadata::property($action, 'canRunCallback', Action::class), $action, 'authorizedToRun', Action::class),
        ];

        try {
            if ($entity !== null) {
                $action->bindTo($entity);
            }

            $fields = $action->fieldPrototypes($request)->all();
            $described['fields'] = array_map(FieldMetadata::describe(...), $fields);
        } catch (Throwable $e) {
            $described['fields'] = null;
            $described['unavailable'] = [['unavailable' => 'fields', 'reason' => Metadata::reason($e)]];
        }

        return $described;
    }

    /**
     * A filter's metadata: key, name, component and how its options are given (count or "closure").
     *
     * @return array<string, mixed>
     */
    public static function filter(Filter $filter): array
    {
        return [
            'key' => self::call(fn () => $filter->key()),
            'class' => Metadata::className($filter),
            'file' => Metadata::classFile($filter),
            'name' => self::call(fn () => $filter->name()),
            'component' => $filter->component,
            'canSee' => Metadata::callbackFlag(Metadata::property($filter, 'canSeeCallback', Filter::class), $filter, 'authorizedToSee', Filter::class),
            'options' => self::filterOptions($filter),
        ];
    }

    /**
     * @return array{source: string, count: int|null}|null
     */
    private static function filterOptions(Filter $filter): ?array
    {
        $declaring = Metadata::declaringClass($filter, 'options');

        if ($declaring === Filter::class && Metadata::declaringClass($filter, 'hasOptions') !== Filter::class) {
            return null;
        }

        return match ($declaring) {
            Filter::class => ['source' => 'none', 'count' => 0],
            BooleanFilter::class => ['source' => 'builtin', 'count' => 2],
            SelectFilter::class => match (true) {
                is_array($source = Metadata::property($filter, 'optionSource', SelectFilter::class)) => ['source' => 'array', 'count' => count($source)],
                $source instanceof Closure => ['source' => 'closure', 'count' => null],
                default => ['source' => 'unknown', 'count' => null],
            },
            default => ['source' => 'method', 'count' => null],
        };
    }

    /**
     * Static hints: no policy, a global search without columns.
     *
     * @param  class-string<Entity>  $class
     * @param  array<string, mixed>  $described
     * @return list<string>
     */
    private static function warnings(string $class, array $described): array
    {
        $warnings = [];
        $model = $described['model'];

        if (is_string($model) && ($described['policy']['class'] ?? null) === null) {
            $warnings[] = config('larsadmin.allow_without_policy', true)
                ? "No policy is registered for {$model}: every ability is allowed (larsadmin.allow_without_policy is true)."
                : "No policy is registered for {$model}: every ability is denied (fail closed).";
        }

        if ($described['statics']['globallySearchable'] === true && $described['statics']['search'] === []) {
            $warnings[] = 'The entity is globally searchable but $search is empty: the global search finds nothing.';
        }

        if (is_array($described['filters']) && $described['filters'] !== [] && $described['scoping']['indexQuery'] === 'inherited') {
            $warnings[] = 'Hint: filters narrow indexQuery(), which is not overridden; row scoping, if needed, belongs in indexQuery().';
        }

        return $warnings;
    }

    /**
     * A public static property of the entity, null when it is not initialized.
     */
    private static function static(string $class, string $name): mixed
    {
        try {
            return $class::${$name};
        } catch (Throwable) {
            return null;
        }
    }

    private static function call(Closure $callback): ?string
    {
        try {
            $value = $callback();
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
