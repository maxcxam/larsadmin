<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:dashboard')]
class DashboardCommand extends ClassGeneratorCommand
{
    protected $name = 'larsadmin:dashboard';

    protected $description = 'Create a new Larsadmin dashboard class';

    protected $type = 'Dashboard';

    protected function kind(): string
    {
        return 'dashboard';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the dashboard already exists'],
        ];
    }
}
