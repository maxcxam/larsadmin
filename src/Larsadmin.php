<?php

namespace Maxcxam\Larsadmin;

use Closure;
use DateTimeZone;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Dashboards\Dashboard;
use Maxcxam\Larsadmin\Dashboards\Main;
use Maxcxam\Larsadmin\Metrics\Metric;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class Larsadmin
{
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
     * Reset the static state, e.g. between tests or Octane requests.
     */
    public static function flushState(): void
    {
        static::$authUsing = null;
        static::$localeUsing = null;
        static::$dashboards = [];
        static::$timezoneUsing = null;
        static::$metricCacheScopeUsing = null;
        static::$entities = [];
        static::$hashes = [];
        static::$scoutModels = [];
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

        return static::fileHash($manifest);
    }

    /**
     * Published assets only change on deploy, so each file is hashed once per process.
     *
     * @var array<string, string>
     */
    protected static array $hashes = [];

    protected static function fileHash(string $path): ?string
    {
        if (! isset(static::$hashes[$path]) && is_file($path) && ($hash = md5_file($path)) !== false) {
            static::$hashes[$path] = $hash;
        }

        return static::$hashes[$path] ?? null;
    }
}
