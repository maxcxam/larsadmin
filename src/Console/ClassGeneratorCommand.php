<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\GeneratorCommand;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Scaffolding\Scaffolder;

/**
 * Base of the application class generators (entity, action, filter, dashboard and the metrics). It keeps Laravel's
 * GeneratorCommand interface (name prompt, reserved names, --force, messages) while the class is planned and written
 * by the shared Scaffolder (ADR-039), the same as the MCP "generate" tool.
 *
 * @internal
 */
abstract class ClassGeneratorCommand extends GeneratorCommand
{
    /**
     * The Scaffolder kind, e.g. "entity".
     */
    abstract protected function kind(): string;

    /**
     * The options passed to the Scaffolder.
     *
     * @return array<string, mixed>
     */
    protected function scaffoldOptions(): array
    {
        return [];
    }

    protected function getStub(): string
    {
        return Scaffolder::STUBS.'/'.Scaffolder::CLASSES[$this->kind()][0];
    }

    /**
     * @return bool|int|null false when nothing was generated (as GeneratorCommand)
     */
    public function handle()
    {
        $name = $this->getNameInput();

        if ($this->isReservedName($name)) {
            $this->components->error('The name "'.$name.'" is reserved by PHP.');

            return false;
        }

        $scaffolder = $this->laravel->make(Scaffolder::class);
        $force = $this->hasOption('force') && $this->option('force');

        try {
            $plan = $scaffolder->plan($this->kind(), $name, $this->scaffoldOptions());

            if (! $force && $plan->existing() !== []) {
                $this->components->error($this->type.' already exists.');

                return false;
            }

            $scaffolder->write($plan, $force);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $path = $plan->files[0]->absolute;

        if (windows_os()) {
            $path = str_replace('/', '\\', $path);
        }

        $this->components->info(sprintf('%s [%s] created successfully.', $this->type, $path));

        return null;
    }
}
