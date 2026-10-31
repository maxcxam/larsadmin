<?php

namespace Maxcxam\Larsadmin\Extensions;

use Illuminate\Support\Facades\Vite;
use Maxcxam\Larsadmin\Larsadmin;

/**
 * Paths, types, URLs and the import map of extension files (ADR-030, ADR-031).
 *
 * @internal
 */
final class ExtensionAssets
{
    /**
     * The file types served from an extension directory, as extension => Content-Type. Anything else is a 404.
     * "map" is served only when "larsadmin.extensions.source_maps" is on.
     *
     * @var array<string, string>
     */
    public const array TYPES = [
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'map' => 'application/json',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
    ];

    /**
     * The panel modules an extension imports by bare specifier, as specifier => published file.
     *
     * @var array<string, string>
     */
    public const array RUNTIME = [
        'vue' => 'runtime/vue.js',
        '@inertiajs/vue3' => 'runtime/inertia.js',
        'larsadmin' => 'runtime/larsadmin.js',
    ];

    /**
     * Normalize a relative file path inside an extension directory; null when it is unsafe: empty, absolute,
     * with a backslash, a colon, a NUL byte, a control character, a "%", an empty segment, or a segment
     * starting with "." (dotfiles, "." and "..", ".vite/").
     */
    public static function normalizeFile(string $file): ?string
    {
        if ($file === '' || strlen($file) > 1024 || preg_match('/[\x00-\x1F\x7F\\\\:%]/', $file) === 1) {
            return null;
        }

        foreach (explode('/', $file) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return null;
            }
        }

        return $file;
    }

    /**
     * Resolve a requested file to its real path inside the extension directory: a regular file whose realpath
     * lies inside the directory (so symlinks pointing outside are refused); null otherwise.
     */
    public static function resolve(Extension $extension, string $file): ?string
    {
        $file = self::normalizeFile($file);

        if ($file === null) {
            return null;
        }

        $root = rtrim($extension->path, '/\\').DIRECTORY_SEPARATOR;
        $real = realpath($extension->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file));

        if ($real === false || ! str_starts_with($real, $root) || ! is_file($real)) {
            return null;
        }

        // The resolved path is checked again (SECX-01): a Windows 8.3 short name ("VITE~1/manifest.json") or a
        // link inside the directory may resolve to a dotfile or ".vite/" that the requested path did not show.
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root)));

        return self::normalizeFile($relative) === null ? null : $real;
    }

    /**
     * The <link rel="modulepreload"> tags for the static import closure of the panel's app.js, read from the
     * published Vite manifest (CRX-05), each preceded by a newline and indented; '' without a manifest.
     * The chunks are linked by the URLs app.js imports them with (hashed names, no query string).
     */
    public static function modulePreloadTags(): string
    {
        $manifest = public_path('vendor/larsadmin/.vite/manifest.json');
        $hash = is_file($manifest) ? @md5_file($manifest) : false;

        if ($hash === false) {
            return '';
        }

        $files = self::$preloads[$hash] ??= self::preloadFiles($manifest);
        $nonce = self::nonceAttribute();
        $tags = '';

        foreach ($files as $file) {
            $tags .= "\n    <link rel=\"modulepreload\" href=\"".e(asset('vendor/larsadmin/'.$file)).'"'.$nonce.'>';
        }

        return $tags;
    }

    /**
     * The preloaded chunk files, per manifest hash (published assets change on deploy only).
     *
     * @var array<string, list<string>>
     */
    private static array $preloads = [];

    /**
     * The files of the chunks app.js imports statically, recursively, in import order.
     *
     * @return list<string>
     */
    private static function preloadFiles(string $manifest): array
    {
        $chunks = json_decode((string) @file_get_contents($manifest), true);

        if (! is_array($chunks)) {
            return [];
        }

        $entry = null;

        foreach ($chunks as $key => $chunk) {
            if (is_array($chunk) && ($chunk['isEntry'] ?? false) === true && ($chunk['file'] ?? null) === 'app.js') {
                $entry = $key;

                break;
            }
        }

        $files = [];
        $visited = [];
        $visit = function (string $key) use (&$visit, &$files, &$visited, $chunks, $entry): void {
            if (isset($visited[$key]) || ! is_array($chunks[$key] ?? null)) {
                return;
            }

            $visited[$key] = true;

            foreach ((array) ($chunks[$key]['imports'] ?? []) as $import) {
                if (is_string($import)) {
                    $visit($import);
                }
            }

            $file = $chunks[$key]['file'] ?? null;

            // Only safe relative JavaScript files; the entry itself is loaded by its <script> tag.
            if ($key !== $entry && is_string($file) && str_ends_with($file, '.js') && self::normalizeFile($file) !== null) {
                $files[] = $file;
            }
        };

        if ($entry !== null) {
            $visit($entry);
        }

        return $files;
    }

    /**
     * The Content-Type of a served file, by its extension; null for a type that is not served.
     */
    public static function contentType(string $file): ?string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if ($extension === 'map' && ! filter_var(config('larsadmin.extensions.source_maps', false), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return self::TYPES[$extension] ?? null;
    }

    /**
     * The versioned URL (path only) of a file of the extension: {path}/extensions/{vendor}/{package}/{version}/{file}.
     */
    public static function url(Extension $extension, string $file): string
    {
        [$vendor, $package] = explode('/', $extension->name, 2);

        return route('larsadmin.extensions.asset', [$vendor, $package, $extension->version(), ltrim($file, '/')], false);
    }

    /**
     * The extensions as the client loads them, in registration order.
     *
     * @return list<array{name: string, script: string, style: string|null}>
     */
    public static function clientList(): array
    {
        return array_values(array_map(fn (Extension $extension) => $extension->toClient(), Larsadmin::registeredExtensions()));
    }

    /**
     * The translation groups of the extensions for the current locale, keyed by namespace.
     *
     * @return array<string, mixed>
     */
    public static function translations(): array
    {
        $translations = [];

        foreach (Larsadmin::registeredExtensions() as $extension) {
            foreach ($extension->translationGroups() as $namespace => $group) {
                $lines = trans("{$namespace}::{$group}");
                $translations[$namespace] = is_array($lines) ? $lines : [];
            }
        }

        return $translations;
    }

    /**
     * Identifies the translation groups and versions of the registered extensions (the client's cache key).
     */
    public static function fingerprint(): string
    {
        $parts = [];

        foreach (Larsadmin::registeredExtensions() as $extension) {
            $parts[] = [$extension->name, $extension->version(), $extension->translationGroups()];
        }

        return substr(md5(json_encode($parts, JSON_THROW_ON_ERROR)), 0, 12);
    }

    /**
     * The import map of the panel runtime modules.
     *
     * @return array{imports: array<string, string>}
     */
    public static function importMap(): array
    {
        $imports = [];

        foreach (self::RUNTIME as $specifier => $file) {
            $imports[$specifier] = Larsadmin::asset($file);
        }

        return ['imports' => $imports];
    }

    /**
     * The import map tag for the root view, preceded by a newline and indented; '' without extensions so the
     * page stays exactly as in 1.4. The JSON escapes "<", ">", "&" and quotes, so it cannot close the tag.
     */
    public static function importMapTag(): string
    {
        if (Larsadmin::registeredExtensions() === []) {
            return '';
        }

        $json = json_encode(self::importMap(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        return "\n    <script type=\"importmap\"".self::nonceAttribute().'>'.$json.'</script>';
    }

    /**
     * The nonce attribute of the panel's script tags (' nonce="..."'), or '' when the application did not
     * call Vite::useCspNonce().
     */
    public static function nonceAttribute(): string
    {
        $nonce = Vite::cspNonce();

        return is_string($nonce) && $nonce !== '' ? ' nonce="'.e($nonce).'"' : '';
    }
}
