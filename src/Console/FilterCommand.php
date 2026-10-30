<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'larsadmin:filter')]
class FilterCommand extends GeneratorCommand
{
    protected $name = 'larsadmin:filter';

    protected $description = 'Create a new Larsadmin filter class';

    protected $type = 'Filter';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/filter.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Larsadmin\\Filters';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the filter already exists'],
        ];
    }
}
