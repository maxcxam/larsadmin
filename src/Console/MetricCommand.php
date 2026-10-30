<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Base of the metric generators: larsadmin:value, larsadmin:trend, larsadmin:partition and larsadmin:progress.
 */
abstract class MetricCommand extends GeneratorCommand
{
    /**
     * The stub file name, without directory.
     */
    abstract protected function stubName(): string;

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/'.$this->stubName();
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Larsadmin\\Metrics';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the '.strtolower($this->type).' already exists'],
        ];
    }
}
