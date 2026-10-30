<?php

namespace Maxcxam\Larsadmin;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
     * Reset the static state, e.g. between tests or Octane requests.
     */
    public static function flushState(): void
    {
        static::$authUsing = null;
        static::$localeUsing = null;
        static::$entities = [];
        static::$hashes = [];
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
