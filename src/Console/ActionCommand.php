<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'larsadmin:action')]
class ActionCommand extends GeneratorCommand
{
    protected $name = 'larsadmin:action';

    protected $description = 'Create a new Larsadmin action class';

    protected $type = 'Action';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/action.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Larsadmin\\Actions';
    }

    protected function buildClass($name): string
    {
        return str_replace(
            '{{ destructive }}',
            $this->option('destructive') ? 'true' : 'false',
            parent::buildClass($name),
        );
    }

    protected function getOptions(): array
    {
        return [
            ['destructive', null, InputOption::VALUE_NONE, 'Mark the action as destructive'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the action already exists'],
        ];
    }
}
