<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:filter')]
class FilterCommand extends ClassGeneratorCommand
{
    protected $name = 'larsadmin:filter';

    protected $description = 'Create a new Larsadmin filter class';

    protected $type = 'Filter';

    protected function kind(): string
    {
        return 'filter';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the filter already exists'],
        ];
    }
}
