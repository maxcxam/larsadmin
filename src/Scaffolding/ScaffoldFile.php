<?php

namespace Maxcxam\Larsadmin\Scaffolding;

/**
 * One file of a scaffold plan.
 *
 * @internal
 */
final class ScaffoldFile
{
    /**
     * @param  string  $path  the path relative to base_path(), with forward slashes
     * @param  string  $local  the path relative to the plan root (the application or the extension package)
     * @param  string  $absolute  the absolute path the file is written to
     * @param  bool  $exists  whether something (a file, a directory or a link) is already at the path
     */
    public function __construct(
        public readonly string $path,
        public readonly string $local,
        public readonly string $absolute,
        public readonly string $contents,
        public readonly bool $exists,
    ) {}

    /**
     * What writing the plan does to the file: "create", "overwrite" (with force) or "exists" (left untouched).
     */
    public function status(bool $force): string
    {
        if (! $this->exists) {
            return 'create';
        }

        return $force ? 'overwrite' : 'exists';
    }
}
