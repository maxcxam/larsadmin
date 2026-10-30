<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'larsadmin:dashboard')]
class DashboardCommand extends GeneratorCommand
{
    protected $name = 'larsadmin:dashboard';

    protected $description = 'Create a new Larsadmin dashboard class';

    protected $type = 'Dashboard';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/dashboard.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Larsadmin\\Dashboards';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the dashboard already exists'],
        ];
    }
}
