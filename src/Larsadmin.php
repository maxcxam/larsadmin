<?php

namespace Maxcxam\Larsadmin;

use Closure;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Audit\AuditActor;
use Maxcxam\Larsadmin\Audit\AuditRecorder;
use Maxcxam\Larsadmin\Audit\Snapshot;
use Maxcxam\Larsadmin\Dashboards\Dashboard;
use Maxcxam\Larsadmin\Dashboards\Main;
use Maxcxam\Larsadmin\Extensions\Extension;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Metrics\Card;
use Maxcxam\Larsadmin\Metrics\Metric;
use Maxcxam\Larsadmin\Query\EntityQuery;
use Maxcxam\Larsadmin\Tools\Tool;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Symfony\Component\Finder\Finder;

class Larsadmin
{
    /**
     * The version of the public extension API: MAJOR.MINOR of the release that last extended it (ADR-034).
     */
    public const string API_VERSION = '1.5';

    /**
     * The callback that determines if a request may access the panel.
     */
    protected static ?Closure $authUsing = null;

    /**
     * The registered entity classes.
     *
     * @var array<int, class-string<Entity>>
     */
    protected static array $entities = [];

    /**
     * Register the callback that determines if a request may access the panel.
     */
    public static function auth(?Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Determine if the given request may access the panel.
     */
    public static function check(Request $request): bool
    {
        $callback = static::$authUsing ?? fn () => config('larsadmin.allow_local', true) && app()->environment('local');

        return (bool) $callback($request);
    }

    /**
     * The callback that resolves the panel locale of a request.
     */
    protected static ?Closure $localeUsing = null;

    /**
     * Register the callback that resolves the panel locale, e.g. a per-user preference.
     */
    public static function resolveLocaleUsing(?Closure $callback): void
    {
        static::$localeUsing = $callback;
    }

    /**
     * Resolve the panel locale: callback, then the session choice, then config, then the app locale.
     */
    public static function locale(Request $request): string
    {
        $available = array_keys(static::locales());

        $candidates = [
            static::$localeUsing ? (static::$localeUsing)($request) : null,
            $request->hasSession() ? $request->session()->get('larsadmin.locale') : null,
            config('larsadmin.locale'),
        ];

        foreach ($candidates as $index => $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            // The session value is user-controlled input: only configured locales are honoured.
            if ($index === 1 && ! in_array($candidate, $available, true)) {
                continue;
            }

            return $candidate;
        }

        return app()->getLocale();
    }

    /**
     * Get the locales users can switch to, as code => label.
     *
     * @return array<string, string>
     */
    public static function locales(): array
    {
        return (array) config('larsadmin.locales', []);
    }

    /**
     * Register the given entity classes.
     *
     * @param  array<int, class-string<Entity>>  $entities
     */
    public static function entities(array $entities): void
    {
        static::$entities = array_values(array_unique(array_merge(static::$entities, $entities)));
    }

    /**
     * Register every entity class found in the given application directory.
     */
    public static function entitiesIn(string $directory): void
    {
        $namespace = app()->getNamespace();
        $appPath = realpath(app_path()).DIRECTORY_SEPARATOR;
        $entities = [];

        foreach (Finder::create()->in($directory)->files()->name('*.php') as $file) {
            $class = $namespace.str_replace(
                ['/', '\\', '.php'],
                ['\\', '\\', ''],
                Str::after($file->getRealPath(), $appPath),
            );

            if (is_subclass_of($class, Entity::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $entities[] = $class;
            }
        }

        sort($entities);

        static::entities($entities);
    }

    /**
     * @return array<int, class-string<Entity>>
     */
    public static function registeredEntities(): array
    {
        return static::$entities;
    }

    /**
     * Find the entity class for the given URI key.
     *
     * @return class-string<Entity>|null
     */
    public static function entityForKey(string $key): ?string
    {
        foreach (static::$entities as $entity) {
            if ($entity::uriKey() === $key) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Find the registered entity class for the given model class.
     *
     * @return class-string<Entity>|null
     */
    public static function entityForModel(string $model): ?string
    {
        foreach (static::$entities as $entity) {
            if ($entity::$model === $model) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Get the entities the request may see in the navigation.
     */
    public static function navigation(Request $request): array
    {
        return collect(static::$entities)
            ->filter(fn ($entity) => $entity::$displayInNavigation && $entity::authorizedToViewAny($request))
            ->map(fn ($entity) => [
                'uriKey' => $entity::uriKey(),
                'label' => $entity::label(),
                'group' => $entity::$group,
            ])
            ->values()
            ->all();
    }

    /**
     * The registered entities in sidebar order: navigation groups in order of first appearance,
     * entities in registration order within a group (as the layout builds the menu).
     *
     * @return array<int, class-string<Entity>>
     */
    public static function searchOrder(): array
    {
        $groups = [];

        foreach (static::$entities as $entity) {
            $groups[$entity::$group === null ? ':' : 'group:'.$entity::$group][] = $entity;
        }

        return array_merge(...array_values($groups));
    }

    /**
     * Whether a model uses Laravel Scout's Searchable trait while Scout is installed, memoized per
     * class (reset by flushState()). Scout is referenced by name only: it is not a dependency.
     *
     * @param  class-string  $model
     */
    public static function modelUsesScout(string $model): bool
    {
        return static::$scoutModels[$model] ??= class_exists('Laravel\Scout\Builder')
            && in_array('Laravel\Scout\Searchable', class_uses_recursive($model), true);
    }

    /**
     * @var array<class-string, bool>
     */
    protected static array $scoutModels = [];

    /**
     * The registered dashboards, in menu order.
     *
     * @var array<int, Dashboard>
     */
    protected static array $dashboards = [];

    /**
     * The callback that resolves the timezone metrics are aligned to.
     */
    protected static ?Closure $timezoneUsing = null;

    /**
     * Register the dashboards of the panel (replacing earlier ones); the order is the menu order.
     * A class string is instantiated with make().
     *
     * @param  array<int, Dashboard|class-string<Dashboard>>  $dashboards
     *
     * @throws InvalidArgumentException for anything that is not a dashboard, or a uriKey used twice
     */
    public static function dashboards(array $dashboards): void
    {
        $registered = [];

        foreach ($dashboards as $dashboard) {
            if (is_string($dashboard) && is_subclass_of($dashboard, Dashboard::class)) {
                $dashboard = $dashboard::make();
            }

            if (! $dashboard instanceof Dashboard) {
                $given = is_object($dashboard) ? $dashboard::class : get_debug_type($dashboard).(is_string($dashboard) ? " [{$dashboard}]" : '');

                throw new InvalidArgumentException("Larsadmin dashboards must be Dashboard instances or classes; got {$given}.");
            }

            if (isset($registered[$dashboard->uriKey()])) {
                throw new InvalidArgumentException("Two dashboards use the uriKey [{$dashboard->uriKey()}]; override uriKey() on one of them.");
            }

            $registered[$dashboard->uriKey()] = $dashboard;
        }

        static::$dashboards = array_values($registered);
    }

    /**
     * The registered dashboards; the empty "Main" one when none were registered.
     *
     * @return array<int, Dashboard>
     */
    public static function registeredDashboards(): array
    {
        return static::$dashboards === [] ? [new Main] : static::$dashboards;
    }

    /**
     * Find a registered dashboard by its URI key (visibility is NOT checked).
     */
    public static function dashboardForKey(string $key): ?Dashboard
    {
        foreach (static::registeredDashboards() as $dashboard) {
            if ($dashboard->uriKey() === $key) {
                return $dashboard;
            }
        }

        return null;
    }

    /**
     * The dashboard shown at the panel root: the first one the request may see.
     */
    public static function home(Request $request): ?Dashboard
    {
        foreach (static::registeredDashboards() as $dashboard) {
            if ($dashboard->authorizedToSee($request)) {
                return $dashboard;
            }
        }

        return null;
    }

    /**
     * The sidebar entries of the dashboards the request may see. The home dashboard points at the
     * panel root, so the root URL is "active" for it.
     *
     * @return list<array{uriKey: string, name: string, url: string}>
     */
    public static function dashboardNavigation(Request $request): array
    {
        $home = static::home($request);
        $entries = [];

        foreach (static::registeredDashboards() as $dashboard) {
            if ($dashboard->displayInNavigation && $dashboard->authorizedToSee($request)) {
                $entries[] = [
                    'uriKey' => $dashboard->uriKey(),
                    'name' => $dashboard->name(),
                    'url' => $dashboard === $home
                        ? route('larsadmin.dashboard', [], false)
                        : route('larsadmin.dashboards.show', $dashboard->uriKey(), false),
                ];
            }
        }

        return $entries;
    }

    /**
     * Register the callback that resolves the metrics timezone, e.g. a per-user setting: fn (Request): ?string.
     */
    public static function timezoneUsing(?Closure $callback): void
    {
        static::$timezoneUsing = $callback;
    }

    /**
     * The callback whose result is added to the cache key of every cached metric.
     */
    protected static ?Closure $metricCacheScopeUsing = null;

    /**
     * Register the request context every cached metric depends on, e.g. the current tenant or team:
     * fn (Request $request, Metric $metric): mixed (JSON encodable). Its result is part of the cache key,
     * so a user switching tenant never sees the previous tenant's cached numbers.
     */
    public static function metricCacheScopeUsing(?Closure $callback): void
    {
        static::$metricCacheScopeUsing = $callback;
    }

    /**
     * The global cache scope of a metric (null without a callback).
     */
    public static function metricCacheScope(Request $request, Metric $metric): mixed
    {
        return static::$metricCacheScopeUsing ? (static::$metricCacheScopeUsing)($request, $metric) : null;
    }

    /**
     * Whether the metricCacheScopeUsing() callback accepts the card as its second argument: no callback, no
     * second parameter, an untyped one, or a type the card satisfies (mixed, object, Card, DataCard, a union...).
     * A callback typed for Metric only does not: such a data card is then never cached (fail closed), rather
     * than cached without the scope or breaking the callback.
     *
     * @internal
     */
    public static function cacheScopeAccepts(Card $card): bool
    {
        if (static::$metricCacheScopeUsing === null) {
            return true;
        }

        $parameter = (new ReflectionFunction(static::$metricCacheScopeUsing))->getParameters()[1] ?? null;

        return $parameter === null || static::typeAccepts($parameter->getType(), $card);
    }

    /**
     * The global cache scope of a data card; only call it when cacheScopeAccepts() is true.
     *
     * @internal
     */
    public static function cardCacheScope(Request $request, Card $card): mixed
    {
        return static::$metricCacheScopeUsing ? (static::$metricCacheScopeUsing)($request, $card) : null;
    }

    protected static function typeAccepts(?ReflectionType $type, object $value): bool
    {
        if ($type === null) {
            return true;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (static::typeAccepts($member, $value)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (! static::typeAccepts($member, $value)) {
                    return false;
                }
            }

            return true;
        }

        if (! $type instanceof ReflectionNamedType) {
            return false;
        }

        $name = $type->getName();

        return match (true) {
            $type->isBuiltin() => in_array($name, ['mixed', 'object'], true),
            in_array($name, ['self', 'static'], true) => false,
            default => $value instanceof $name,
        };
    }

    /**
     * Resolve the timezone metrics are aligned to: callback, then config, then the app timezone.
     * Unknown identifiers are skipped.
     */
    public static function timezone(Request $request): string
    {
        $candidates = [
            static::$timezoneUsing ? (static::$timezoneUsing)($request) : null,
            config('larsadmin.timezone'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                try {
                    new DateTimeZone($candidate);
                } catch (Exception) {
                    continue;
                }

                return $candidate;
            }
        }

        return (string) config('app.timezone', 'UTC');
    }

    /**
     * The callback that resolves the actor label stored with audit entries.
     */
    protected static ?Closure $auditActorLabelUsing = null;

    /**
     * Whether the audit log is enabled (config "larsadmin.audit.enabled").
     */
    public static function auditEnabled(): bool
    {
        return (bool) config('larsadmin.audit.enabled', false);
    }

    /**
     * Whether the bell and queued actions are enabled (config "larsadmin.notifications.enabled").
     */
    public static function notificationsEnabled(): bool
    {
        return filter_var(config('larsadmin.notifications.enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Customize the actor label snapshot stored with each entry: fn (Authenticatable $user): ?string.
     * Default: $user->name, else $user->email, else "#{id}". Null resets.
     */
    public static function auditActorLabelUsing(?Closure $callback): void
    {
        static::$auditActorLabelUsing = $callback;
    }

    /**
     * The actor label of an audit entry for the user.
     *
     * @internal
     */
    public static function auditActorLabel(Authenticatable $user): ?string
    {
        if (static::$auditActorLabelUsing !== null) {
            $label = (static::$auditActorLabelUsing)($user);

            return is_scalar($label) && (string) $label !== '' ? (string) $label : null;
        }

        foreach (['name', 'email'] as $attribute) {
            $value = $user->{$attribute} ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '#'.$user->getAuthIdentifier();
    }

    /**
     * The registered extensions, keyed by name, in registration order.
     *
     * @var array<string, Extension>
     */
    protected static array $extensions = [];

    /**
     * The route callbacks of extensions (Larsadmin::routes()).
     *
     * @var list<Closure>
     */
    protected static array $extensionRoutes = [];

    /**
     * The registered tools, keyed by uriKey, in registration order.
     *
     * @var array<string, Tool>
     */
    protected static array $tools = [];

    /**
     * Register an extension: a Composer package name and the directory with its built files (ADR-031).
     * Registering the same name with the same directory again returns the registered extension.
     *
     * @throws InvalidArgumentException for an invalid name or directory, or a name registered with another directory
     */
    public static function extension(string $name, string $path): Extension
    {
        $extension = Extension::make($name, $path);

        if (isset(static::$extensions[$name])) {
            if (static::$extensions[$name]->path !== $extension->path) {
                throw new InvalidArgumentException("The extension [{$name}] is already registered with another directory.");
            }

            return static::$extensions[$name];
        }

        return static::$extensions[$name] = $extension;
    }

    /**
     * @return array<string, Extension> keyed by name, in registration order
     */
    public static function registeredExtensions(): array
    {
        return static::$extensions;
    }

    public static function extensionFor(string $name): ?Extension
    {
        return static::$extensions[$name] ?? null;
    }

    /**
     * Register HTTP routes of an extension inside the panel's authenticated route group
     * ({path}/ext, named "larsadmin.ext.", behind "larsadmin.middleware" and "larsadmin.auth_middleware").
     * Only the panel gate is guaranteed: authorize records with findRecord().
     */
    public static function routes(Closure $routes): void
    {
        // The same Closure object is registered once (providers booted twice, Octane, tests).
        if (! in_array($routes, static::$extensionRoutes, true)) {
            static::$extensionRoutes[] = $routes;
        }
    }

    /**
     * Run a change to a record outside the entity form (an extension route) and audit it as "updated":
     * snapshot of the entity's audited fields before, $change($record), snapshot after, diff, masking as usual.
     * One transaction; no entry when nothing changed, the audit log is off or the entity is not auditable.
     * Returns the closure's result.
     *
     * @param  string  $entity  the entity's uriKey (an unknown one is a 404)
     * @param  Closure(Model): mixed  $change
     */
    public static function audited(Request $request, string $entity, Model $record, Closure $change): mixed
    {
        $entityClass = static::entityForKey($entity) ?? abort(404);

        if (! $record instanceof $entityClass::$model) {
            throw new InvalidArgumentException('The record ['.$record::class."] is not a model of the entity [{$entity}].");
        }

        return $record->getConnection()->transaction(function () use ($request, $entityClass, $record, $change) {
            $recorder = $entityClass::audited() ? app(AuditRecorder::class) : null;

            // An outer operation (an action batch) that covers the record records it itself.
            if ($recorder !== null && $recorder->covers($entityClass, $record)) {
                $recorder = null;
            }

            $instance = $entityClass::make($record);
            $before = $recorder === null ? null : Snapshot::capture($instance, $request);

            $result = $change($record);

            if ($recorder !== null && $before !== null) {
                $changes = $before->diff(Snapshot::capture($instance, $request));

                if ($changes !== []) {
                    $recorder->record('updated', $entityClass, $record, $changes, [], AuditActor::fromRequest($request));
                }
            }

            return $result;
        });
    }

    /**
     * A field of the entity for the record, as the panel resolves it: bound (bindTo), shown in $context for this
     * user and record (404 otherwise) and, for create / update, not read-only (403). Call after findRecord().
     * Returns a clone, so the caller may resolve it.
     *
     * @param  string  $entity  the entity's uriKey (an unknown one is a 404)
     *
     * @throws InvalidArgumentException for an unknown context
     */
    public static function findField(Request $request, string $entity, Model $record, string $attribute, string $context = Field::UPDATE): Field
    {
        if (! in_array($context, [Field::INDEX, Field::DETAIL, Field::CREATE, Field::UPDATE], true)) {
            throw new InvalidArgumentException("Unknown field context [{$context}].");
        }

        $entityClass = static::entityForKey($entity) ?? abort(404);

        $field = $entityClass::make($record)->fieldPrototypes($request)
            ->first(fn (Field $candidate) => $candidate->attribute === $attribute
                && $candidate->isShownOn($context, $request, $context === Field::INDEX ? null : $record));

        abort_unless($field instanceof Field, 404);

        if (in_array($context, [Field::CREATE, Field::UPDATE], true)) {
            abort_if($field->isReadonlyFor($request, $record), 403);
        }

        return clone $field;
    }

    /**
     * The route callbacks registered with routes().
     *
     * @return list<Closure>
     *
     * @internal
     */
    public static function extensionRoutes(): array
    {
        return static::$extensionRoutes;
    }

    /**
     * Find a record of an entity the way the panel does: viewAny (403), the entity's detail scope
     * (404 for rows outside it), then the policy ability on the record (403). Fail closed.
     *
     * @param  string  $entity  the entity's uriKey (an unknown one is a 404)
     * @param  string  $ability  'view', 'update' or 'delete'
     *
     * @throws InvalidArgumentException for another ability
     */
    public static function findRecord(Request $request, string $entity, int|string $id, string $ability = 'view'): Model
    {
        if (! in_array($ability, ['view', 'update', 'delete'], true)) {
            throw new InvalidArgumentException("Unsupported ability [{$ability}]; use view, update or delete.");
        }

        $entityClass = static::entityForKey($entity) ?? abort(404);

        abort_unless($entityClass::authorizedToViewAny($request), 403);

        $record = EntityQuery::for($entityClass, $request)->scope('detail')->findOrFail((string) $id);

        $instance = $entityClass::make($record);

        abort_unless(match ($ability) {
            'view' => $instance->authorizedToView($request),
            'update' => $instance->authorizedToUpdate($request),
            'delete' => $instance->authorizedToDelete($request),
        }, 403);

        return $record;
    }

    /**
     * Register tools shown in the sidebar, after the ones registered before. A class string is instantiated
     * with make(). Registering the same class again replaces it.
     *
     * @param  array<int, Tool|class-string<Tool>>  $tools
     *
     * @throws InvalidArgumentException for anything that is not a tool, a component key that is not "vendor/name",
     *                                  or a uriKey used by another tool class
     */
    public static function tools(array $tools): void
    {
        foreach ($tools as $tool) {
            if (is_string($tool) && is_subclass_of($tool, Tool::class)) {
                $tool = $tool::make();
            }

            if (! $tool instanceof Tool) {
                $given = is_object($tool) ? $tool::class : get_debug_type($tool).(is_string($tool) ? " [{$tool}]" : '');

                throw new InvalidArgumentException("Larsadmin tools must be Tool instances or classes; got {$given}.");
            }

            if (preg_match(Tool::COMPONENT_PATTERN, $tool->component) !== 1) {
                throw new InvalidArgumentException('The component of the tool ['.$tool::class."] must be an extension key like \"vendor/name\"; got [{$tool->component}].");
            }

            $key = $tool->uriKey();

            if (isset(static::$tools[$key]) && static::$tools[$key]::class !== $tool::class) {
                throw new InvalidArgumentException("Two tools use the uriKey [{$key}]; override uriKey() on one of them.");
            }

            static::$tools[$key] = $tool;
        }
    }

    /**
     * @return list<Tool> in registration order
     */
    public static function registeredTools(): array
    {
        return array_values(static::$tools);
    }

    /**
     * Find a registered tool by its URI key (visibility is NOT checked).
     */
    public static function toolForKey(string $key): ?Tool
    {
        return static::$tools[$key] ?? null;
    }

    /**
     * Reset the static state, e.g. between tests or Octane requests.
     */
    public static function flushState(): void
    {
        static::$authUsing = null;
        static::$auditActorLabelUsing = null;
        static::$localeUsing = null;
        static::$dashboards = [];
        static::$timezoneUsing = null;
        static::$metricCacheScopeUsing = null;
        static::$entities = [];
        static::$hashes = [];
        static::$scoutModels = [];
        static::$extensions = [];
        static::$extensionRoutes = [];
        static::$tools = [];
    }

    /**
     * Get the panel name.
     */
    public static function name(): string
    {
        return config('larsadmin.name');
    }

    /**
     * Get the URI path prefix of the panel.
     */
    public static function path(): string
    {
        return '/'.trim(config('larsadmin.path'), '/');
    }

    /**
     * Get the authentication guard used by the panel.
     */
    public static function guard(): ?string
    {
        return config('larsadmin.guard');
    }

    /**
     * Get the versioned URL of a published panel asset.
     */
    public static function asset(string $file): string
    {
        $path = public_path('vendor/larsadmin/'.$file);

        $hash = static::fileHash($path);

        return asset('vendor/larsadmin/'.$file).($hash !== null ? '?id='.substr($hash, 0, 12) : '');
    }

    /**
     * Get the hash of the published assets, used as the Inertia asset version.
     */
    public static function assetsVersion(): ?string
    {
        $manifest = public_path('vendor/larsadmin/.vite/manifest.json');

        $hash = static::fileHash($manifest);

        // Without extensions the version is exactly the 1.4 one.
        if (static::$extensions === []) {
            return $hash;
        }

        $versions = array_map(fn (Extension $extension) => $extension->name.'@'.$extension->version(), static::$extensions);

        return md5(($hash ?? '').'|'.implode('|', $versions));
    }

    /**
     * Published assets only change on deploy, so each file is hashed once per process.
     *
     * @var array<string, string>
     */
    protected static array $hashes = [];

    protected static function fileHash(string $path): ?string
    {
        // A file replaced or removed between is_file() and md5_file() (a deploy) counts as missing, without a warning.
        if (! isset(static::$hashes[$path]) && is_file($path) && ($hash = @md5_file($path)) !== false) {
            static::$hashes[$path] = $hash;
        }

        return static::$hashes[$path] ?? null;
    }
}
