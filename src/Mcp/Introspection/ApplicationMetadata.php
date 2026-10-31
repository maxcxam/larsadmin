<?php

namespace Maxcxam\Larsadmin\Mcp\Introspection;

use Closure;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Dashboard;
use Maxcxam\Larsadmin\Extensions\Extension;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;
use Maxcxam\Larsadmin\Mcp\McpGate;
use Maxcxam\Larsadmin\Realtime\Realtime;
use Maxcxam\Larsadmin\Tools\Tool;
use Throwable;

/**
 * The application-level metadata of MCP introspection (ADR-038): the panel configuration read through an
 * explicit key allow-list, the dashboards and the extensions with their tools. No connection, host, port,
 * key, secret, password, token, DSN or URL is ever read; Tool::props(), canSee and card data are never run.
 *
 * @internal
 */
final class ApplicationMetadata
{
    /**
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        $entities = Larsadmin::registeredEntities();

        return [
            'larsadmin' => McpGate::packageVersion(),
            'apiVersion' => Larsadmin::API_VERSION,
            'laravel' => app()->version(),
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'panel' => [
                'name' => self::string(config('larsadmin.name')),
                'path' => trim((string) self::string(config('larsadmin.path')), '/'),
                'domainSet' => self::string(config('larsadmin.domain')) !== null,
                'guard' => self::string(config('larsadmin.guard')),
                'locale' => self::string(config('larsadmin.locale')),
                'locales' => array_values(array_filter(array_keys((array) config('larsadmin.locales', [])), 'is_string')),
                'timezone' => self::string(config('larsadmin.timezone')) ?? self::string(config('app.timezone')),
                'filesDisk' => self::string(config('larsadmin.files.disk')),
                'queue' => self::string(config('larsadmin.actions.queue')),
            ],
            'features' => [
                'audit' => Larsadmin::auditEnabled(),
                'notifications' => Larsadmin::notificationsEnabled(),
                'realtime' => self::realtime(),
                'scout' => class_exists('Laravel\Scout\Builder'),
                'globalSearch' => array_filter($entities, fn (string $entity) => self::flag(fn () => $entity::$globallySearchable)) !== [],
            ],
            'counts' => [
                'entities' => count($entities),
                'dashboards' => count(Larsadmin::registeredDashboards()),
                'tools' => count(Larsadmin::registeredTools()),
                'extensions' => count(Larsadmin::registeredExtensions()),
            ],
            'mcp' => ['writesAllowed' => McpGate::enabled()],
            'providers' => [
                'app' => self::applicationProvider(),
                'entitiesDirectory' => is_dir(app_path('Larsadmin')) ? Metadata::relativePath(app_path('Larsadmin')) : null,
            ],
        ];
    }

    /**
     * The list-dashboards rows: the first registered dashboard is the home page for whoever may see it
     * (Larsadmin::home() is not called: it needs a user).
     *
     * @return list<array<string, mixed>>
     */
    public static function dashboards(Request $request): array
    {
        $rows = [];

        foreach (array_values(Larsadmin::registeredDashboards()) as $index => $dashboard) {
            $row = [
                'key' => self::call(fn () => $dashboard->uriKey()),
                'class' => Metadata::className($dashboard),
                'file' => Metadata::classFile($dashboard),
                'name' => self::call(fn () => $dashboard->name()),
                'inNavigation' => $dashboard->displayInNavigation,
                'isHome' => $index === 0,
                'canSee' => Metadata::callbackFlag(Metadata::property($dashboard, 'canSee', Dashboard::class), $dashboard, 'authorizedToSee', Dashboard::class),
            ];

            try {
                $row['cards'] = CardMetadata::describeAll((array) $dashboard->cards($request));
            } catch (Throwable $e) {
                $row['cards'] = null;
                $row['unavailable'] = [['unavailable' => 'cards', 'reason' => Metadata::reason($e)]];
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The list-extensions payload: the extensions (directory, built files, version hash, translation groups)
     * and the tools (never props()).
     *
     * @return array{extensions: list<array<string, mixed>>, tools: list<array<string, mixed>>}
     */
    public static function extensions(): array
    {
        $extensions = array_map(fn (Extension $extension) => [
            'name' => $extension->name,
            'path' => Metadata::relativePath($extension->path),
            'version' => self::call(fn () => $extension->version()),
            'script' => $extension->scriptFile(),
            'style' => $extension->styleFile(),
            'translations' => $extension->translationGroups(),
            'package' => self::package($extension),
        ], array_values(Larsadmin::registeredExtensions()));

        $tools = array_map(fn (Tool $tool) => [
            'uriKey' => self::call(fn () => $tool->uriKey()),
            'class' => Metadata::className($tool),
            'file' => Metadata::classFile($tool),
            'name' => self::call(fn () => $tool->name()),
            'component' => $tool->component,
            'icon' => self::call(fn () => $tool->navigationIcon()),
            'canSee' => Metadata::callbackFlag(Metadata::property($tool, 'canSee', Tool::class), $tool, 'authorizedToSee', Tool::class),
        ], Larsadmin::registeredTools());

        return ['extensions' => $extensions, 'tools' => $tools];
    }

    /**
     * Where the extension's PHP classes live, read as data from Composer metadata (no extension code runs): the
     * package's PSR-4 namespaces from vendor/composer/installed.json, else from the composer.json of the package
     * found within three directories above the registered path whose "name" is the extension name. Only the name
     * and the namespaces are returned; the components an extension registers are in its JavaScript and not listed.
     *
     * @return array{name: string, namespaces: list<string>, source: string}|null
     */
    private static function package(Extension $extension): ?array
    {
        $installed = self::json(base_path('vendor/composer/installed.json'), 50 * 1024 * 1024);
        $packages = is_array($installed['packages'] ?? null) ? $installed['packages'] : (array_is_list((array) $installed) ? (array) $installed : []);

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === $extension->name) {
                return ['name' => $extension->name, 'namespaces' => self::namespaces($package), 'source' => 'installed.json'];
            }
        }

        $directory = $extension->path;

        for ($level = 0; $level <= 3; $level++) {
            $composer = self::json($directory.DIRECTORY_SEPARATOR.'composer.json', 1024 * 1024);

            if (($composer['name'] ?? null) === $extension->name) {
                return ['name' => $extension->name, 'namespaces' => self::namespaces($composer), 'source' => 'composer.json'];
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return null;
    }

    /**
     * The PSR-4 namespaces of a Composer package definition (valid namespace names only).
     *
     * @param  array<mixed>  $package
     * @return list<string>
     */
    private static function namespaces(array $package): array
    {
        $psr4 = $package['autoload']['psr-4'] ?? [];

        return array_values(array_filter(
            is_array($psr4) ? array_map('strval', array_keys($psr4)) : [],
            fn (string $namespace) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\\z/', $namespace) === 1,
        ));
    }

    /**
     * A JSON file decoded to an array, or null when missing, too large or invalid.
     *
     * @return array<mixed>|null
     */
    private static function json(string $path, int $maxBytes): ?array
    {
        if (! is_file($path) || ($size = @filesize($path)) === false || $size > $maxBytes) {
            return null;
        }

        $contents = @file_get_contents($path);
        $decoded = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The file of the application's Larsadmin provider (a loaded LarsadminApplicationServiceProvider subclass).
     */
    private static function applicationProvider(): ?string
    {
        foreach (array_keys(app()->getLoadedProviders()) as $provider) {
            if (is_subclass_of($provider, LarsadminApplicationServiceProvider::class)) {
                return Metadata::classFile($provider);
            }
        }

        return null;
    }

    private static function realtime(): bool
    {
        try {
            return Realtime::enabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A non-empty string configuration value, else null.
     */
    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function flag(Closure $callback): bool
    {
        try {
            return $callback() === true;
        } catch (Throwable) {
            return false;
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
