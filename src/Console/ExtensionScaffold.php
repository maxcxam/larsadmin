<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Extensions\Extension;

/**
 * Shared logic of the extension scaffolds (larsadmin:extension, larsadmin:field, larsadmin:card): safe target
 * paths inside the application, stub rendering and all-or-nothing writes that never overwrite without --force.
 *
 * @internal
 */
final class ExtensionScaffold
{
    public const string STUBS = __DIR__.'/../../stubs';

    /**
     * A PHP namespace: identifiers separated by single backslashes.
     */
    public const string NAMESPACE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/';

    public function __construct(protected Filesystem $files = new Filesystem) {}

    /**
     * Resolve a directory given relative to the application root; absolute paths, ".." segments, backslashes
     * and anything outside the root are refused.
     *
     * @throws InvalidArgumentException
     */
    public static function relativeDirectory(string $path, string $option): string
    {
        $path = trim($path);

        if ($path === '' || preg_match('#^([A-Za-z]:|/|\\\\)#', $path) === 1 || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidArgumentException("The {$option} must be a directory relative to the application root.");
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $segment) => $segment !== '' && $segment !== '.'));

        foreach ($segments as $segment) {
            if ($segment === '..' || preg_match('/^[A-Za-z0-9_.@-]+\z/', $segment) !== 1) {
                throw new InvalidArgumentException("The {$option} [{$path}] must stay inside the application root.");
            }
        }

        if ($segments === []) {
            throw new InvalidArgumentException("The {$option} must be a directory relative to the application root.");
        }

        $relative = implode('/', $segments);

        // Lexically safe is not enough: a symlink or junction below the root may point elsewhere.
        if (! self::insideBase(base_path($relative))) {
            throw new InvalidArgumentException("The {$option} [{$path}] leads outside the application root.");
        }

        return $relative;
    }

    /**
     * Whether a path (existing or not) stays inside the application root once links are resolved: the realpath
     * of its deepest existing ancestor (the path itself when it exists) lies inside realpath(base_path()).
     */
    public static function insideBase(string $path): bool
    {
        $base = realpath(base_path());

        if ($base === false) {
            return false;
        }

        // No link (dangling ones included, which file_exists() does not see) below the root: writes would follow it.
        $root = rtrim(base_path(), '/\\');

        for ($segment = $path; strlen($segment) > strlen($root) && dirname($segment) !== $segment; $segment = dirname($segment)) {
            if (is_link($segment)) {
                return false;
            }
        }

        $existing = $path;

        while (! file_exists($existing)) {
            $parent = dirname($existing);

            if ($parent === $existing) {
                return false;
            }

            $existing = $parent;
        }

        $real = realpath($existing);

        return $real !== false && ($real === $base || str_starts_with($real, rtrim($base, '/\\').DIRECTORY_SEPARATOR));
    }

    /**
     * A class name for generated files: StudlyCase letters and digits.
     *
     * @throws InvalidArgumentException
     */
    public static function className(string $name): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*\z/', $name) !== 1) {
            throw new InvalidArgumentException("The name [{$name}] must start with a letter and contain letters and digits only.");
        }

        return Str::studly($name);
    }

    /**
     * A component key segment ([a-z0-9-]+) derived from a vendor or package name.
     */
    public static function keySegment(string $value): string
    {
        $segment = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');

        return $segment === '' ? 'x' : $segment;
    }

    /**
     * The relative path from a directory under the application root to the larsadmin kit in the root's vendor/.
     */
    public static function hostKitPath(string $relativeDirectory): string
    {
        return str_repeat('../', substr_count($relativeDirectory, '/') + 1).'vendor/maxcxam/larsadmin/kit';
    }

    /**
     * Read an extension package's composer.json: the package name and the PSR-4 namespace of src/.
     *
     * @return array{name: string, namespace: string}
     *
     * @throws InvalidArgumentException when the directory is not an extension package
     */
    public function package(string $directory): array
    {
        $composer = $directory.'/composer.json';
        $json = is_file($composer) ? json_decode((string) file_get_contents($composer), true) : null;

        if (! is_array($json) || ! is_string($json['name'] ?? null) || preg_match(Extension::NAME_PATTERN, $json['name']) !== 1) {
            throw new InvalidArgumentException("No extension package (composer.json with a vendor/name) in [{$directory}].");
        }

        foreach ((array) ($json['autoload']['psr-4'] ?? []) as $namespace => $source) {
            if (is_string($namespace) && is_string($source) && in_array(rtrim($source, '/'), ['src', './src'], true)) {
                $namespace = rtrim($namespace, '\\');

                // The namespace is written into generated PHP: only a syntactically valid namespace is accepted.
                if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
                    throw new InvalidArgumentException("The PSR-4 namespace [{$namespace}] in [{$directory}/composer.json] is not a valid PHP namespace.");
                }

                return ['name' => $json['name'], 'namespace' => $namespace];
            }
        }

        throw new InvalidArgumentException("The composer.json in [{$directory}] has no PSR-4 namespace for src/.");
    }

    /**
     * Render a stub with {{ key }} placeholders.
     *
     * @param  array<string, string>  $replace
     */
    public function render(string $stub, array $replace): string
    {
        $pairs = [];

        foreach ($replace as $key => $value) {
            $pairs['{{ '.$key.' }}'] = $value;
        }

        return strtr((string) file_get_contents(self::STUBS.'/'.$stub), $pairs);
    }

    /**
     * The files of $files (relative path => contents) that already exist in $root.
     *
     * @param  array<string, string>  $files
     * @return list<string>
     */
    public function existing(string $root, array $files): array
    {
        return array_values(array_filter(array_keys($files), fn (string $path) => $this->files->exists($root.'/'.$path)));
    }

    /**
     * Write every file below $root (relative path => contents).
     *
     * @param  array<string, string>  $files
     */
    public function write(string $root, array $files): void
    {
        // Checked for every target before anything is written: a link inside the target must not lead out.
        foreach (array_keys($files) as $path) {
            if (! self::insideBase($root.'/'.$path)) {
                throw new InvalidArgumentException("The file [{$path}] would be written outside the application root.");
            }
        }

        foreach ($files as $path => $contents) {
            $target = $root.'/'.$path;
            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->put($target, $contents);
        }
    }
}
