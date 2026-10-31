<?php

namespace Maxcxam\Larsadmin\Scaffolding;

/**
 * What a generator would write (ADR-039): the files with their contents and status, and what to do next. Built by
 * Scaffolder::plan(), written by Scaffolder::write().
 *
 * @internal
 */
final class ScaffoldPlan
{
    /**
     * @param  string  $kind  one of Scaffolder::KINDS
     * @param  string  $root  the directory the files belong to, relative to base_path(): "." for the application
     * @param  list<ScaffoldFile>  $files
     * @param  string  $label  what is generated: the class type ("Entity") or the class / package name
     * @param  string|null  $key  the component key of fields, cards and tools
     * @param  list<string>  $registration  the lines that register the Vue components in defineExtension()
     * @param  list<string>  $phpRegistration  the PHP lines that register the class (tools)
     * @param  list<string>  $nextSteps  short hints of what to do after writing
     * @param  int  $directoryMode  the mode of created directories
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $root,
        public readonly array $files,
        public readonly string $label,
        public readonly ?string $key = null,
        public readonly array $registration = [],
        public readonly array $phpRegistration = [],
        public readonly array $nextSteps = [],
        public readonly int $directoryMode = 0755,
    ) {}

    /**
     * The files that already exist, as paths relative to the plan root.
     *
     * @return list<string>
     */
    public function existing(): array
    {
        return array_values(array_map(
            fn (ScaffoldFile $file) => $file->local,
            array_filter($this->files, fn (ScaffoldFile $file) => $file->exists),
        ));
    }

    /**
     * The plan as data: the files with their status (and contents when asked) and the next steps.
     *
     * @return array{kind: string, files: list<array{path: string, status: string, contents?: string}>, nextSteps: list<string>}
     */
    public function toArray(bool $force = false, bool $contents = true): array
    {
        return [
            'kind' => $this->kind,
            'files' => array_map(fn (ScaffoldFile $file) => [
                'path' => $file->path,
                'status' => $file->status($force),
                ...($contents ? ['contents' => $file->contents] : []),
            ], $this->files),
            'nextSteps' => $this->nextSteps,
        ];
    }
}
