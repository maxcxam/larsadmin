<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:action')]
class ActionCommand extends ClassGeneratorCommand
{
    protected $name = 'larsadmin:action';

    protected $description = 'Create a new Larsadmin action class';

    protected $type = 'Action';

    protected function kind(): string
    {
        return 'action';
    }

    protected function scaffoldOptions(): array
    {
        return ['destructive' => (bool) $this->option('destructive')];
    }

    protected function getOptions(): array
    {
        return [
            ['destructive', null, InputOption::VALUE_NONE, 'Mark the action as destructive'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the action already exists'],
        ];
    }
}
