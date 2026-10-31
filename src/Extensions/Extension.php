<?php

namespace Maxcxam\Larsadmin\Extensions;

use InvalidArgumentException;

/**
 * A registered extension: a Composer package name and the directory with its built files (ADR-031).
 * Created by Larsadmin::extension(); the fluent setters customize which files the panel loads.
 *
 * @api stable since 1.5
 */
final class Extension
{
    /**
     * The format of a Composer package name.
     *
     * @internal
     */
    public const string NAME_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z/';

    /**
     * The format of a translation namespace and group exposed to JavaScript.
     */
    protected const string TRANSLATION_PATTERN = '/^[A-Za-z0-9_.-]+\z/';

    /**
     * The entry module, relative to $path.
     */
    protected string $script = 'extension.js';

    /**
     * The stylesheet, relative to $path; null for none.
     */
    protected ?string $style = 'extension.css';

    /**
     * The translation groups exposed to JavaScript, as namespace => group.
     *
     * @var array<string, string>
     */
    protected array $translations = [];

    /**
     * The memoized version hash.
     */
    protected ?string $version = null;

    /**
     * @param  string  $name  the Composer package name, e.g. 'acme/larsadmin-rating'
     * @param  string  $path  the realpath of the directory with the built files
     *
     * @internal built by make(), through Larsadmin::extension()
     */
    private function __construct(
        public readonly string $name,
        public readonly string $path,
    ) {}

    /**
     * Validate the name and the directory and build the extension (the path is stored as its realpath).
     *
     * @throws InvalidArgumentException for an invalid package name, a missing directory or a symlinked one
     *
     * @internal use Larsadmin::extension()
     */
    public static function make(string $name, string $path): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("The extension name [{$name}] is not a valid Composer package name (vendor/package).");
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException("The directory of the extension [{$name}] is not valid.");
        }

        // realpath('') and relative paths resolve against the working directory (public/ under FPM): refuse them.
        if (! self::isAbsolute($path)) {
            throw new InvalidArgumentException("The directory of the extension [{$name}] must be an absolute path, e.g. __DIR__.'/../dist'.");
        }

        $real = realpath($path);

        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException("The directory [{$path}] of the extension [{$name}] does not exist.");
        }

        // The directory itself must not be a symlink (or a Windows junction): what is served is decided by the
        // real directory only. Links above it (e.g. a deployment's "current" symlink) are fine.
        if (self::isLinked(rtrim($path, '/\\'), $real)) {
            throw new InvalidArgumentException("The directory of the extension [{$name}] must not be a symbolic link.");
        }

        return new self($name, $real);
    }

    /**
     * Whether the last component of $path is a link: is_link(), or (for junctions, which is_link() does not
     * report) its realpath differs from the realpath of its parent plus its own name.
     */
    protected static function isLinked(string $path, string $real): bool
    {
        if (is_link($path)) {
            return true;
        }

        $name = basename($path);
        $parent = realpath(dirname($path));

        if ($parent === false || in_array($name, ['', '.', '..'], true)) {
            return false;
        }

        $expected = rtrim($parent, '/\\').DIRECTORY_SEPARATOR.$name;

        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($expected, $real) !== 0 : $expected !== $real;
    }

    /**
     * Whether the path is absolute: "/..." or, on Windows, "C:\..." / "C:/..." / "\\server\share". Stream
     * wrappers ("phar://") are not absolute local paths.
     */
    protected static function isAbsolute(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return PHP_OS_FAMILY === 'Windows' && preg_match('#^([A-Za-z]:[\\\\/]|\\\\\\\\)#', $path) === 1;
    }

    /**
     * The entry module the panel imports, relative to the directory.
     */
    public function script(string $file = 'extension.js'): static
    {
        $this->script = ExtensionAssets::normalizeFile($file)
            ?? throw new InvalidArgumentException("The script [{$file}] of the extension [{$this->name}] is not a valid relative path.");
        $this->version = null;

        return $this;
    }

    /**
     * The stylesheet the panel links, relative to the directory; null for none. A missing file is skipped.
     */
    public function style(?string $file = 'extension.css'): static
    {
        $this->style = $file === null ? null : (ExtensionAssets::normalizeFile($file)
            ?? throw new InvalidArgumentException("The style [{$file}] of the extension [{$this->name}] is not a valid relative path."));
        $this->version = null;

        return $this;
    }

    /**
     * Expose the translation group trans("{$namespace}::{$group}") to JavaScript as t('{namespace}::key').
     * The lang files are registered by the extension's provider with loadTranslationsFrom().
     */
    public function translations(string $namespace, string $group = 'ui'): static
    {
        if (preg_match(self::TRANSLATION_PATTERN, $namespace) !== 1 || preg_match(self::TRANSLATION_PATTERN, $group) !== 1) {
            throw new InvalidArgumentException("The translations [{$namespace}::{$group}] of the extension [{$this->name}] are not valid.");
        }

        $this->translations[$namespace] = $group;

        return $this;
    }

    /**
     * The translation groups exposed to JavaScript, as namespace => group.
     *
     * @return array<string, string>
     *
     * @internal
     */
    public function translationGroups(): array
    {
        return $this->translations;
    }

    /**
     * The entry module, relative to the directory.
     *
     * @internal
     */
    public function scriptFile(): string
    {
        return $this->script;
    }

    /**
     * The stylesheet relative to the directory, or null when none is set or the file does not exist.
     *
     * @internal
     */
    public function styleFile(): ?string
    {
        return $this->style !== null && is_file($this->path.DIRECTORY_SEPARATOR.$this->style) ? $this->style : null;
    }

    /**
     * The version of the built files: 12 hex chars of the md5 of the contents of the Vite manifest (it lists the
     * hashed chunk names), the entry script and the stylesheet. The entry files keep fixed names across builds,
     * so their contents must be part of it, or a rebuild would keep the URL its stale files are cached under.
     * Computed once per registration (each boot; deployments replace the process).
     */
    public function version(): string
    {
        if ($this->version !== null) {
            return $this->version;
        }

        $parts = [];

        foreach (['.vite/manifest.json', $this->script, $this->style] as $file) {
            $path = $file === null ? null : $this->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            $hash = $path !== null && is_file($path) ? @md5_file($path) : false;
            $parts[] = $hash === false ? '' : $hash;
        }

        return $this->version = substr(md5(implode('|', $parts)), 0, 12);
    }

    /**
     * The versioned URL (path only) of a file in the directory.
     */
    public function url(string $file): string
    {
        return ExtensionAssets::url($this, $file);
    }

    /**
     * The extension as the client loads it: name, script and style URLs.
     *
     * @return array{name: string, script: string, style: string|null}
     *
     * @internal
     */
    public function toClient(): array
    {
        $style = $this->styleFile();

        return [
            'name' => $this->name,
            'script' => $this->url($this->script),
            'style' => $style === null ? null : $this->url($style),
        ];
    }
}
