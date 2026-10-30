<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'larsadmin:progress')]
class ProgressCommand extends MetricCommand
{
    protected $name = 'larsadmin:progress';

    protected $description = 'Create a new Larsadmin progress metric class';

    protected $type = 'Progress metric';

    protected function stubName(): string
    {
        return 'progress.stub';
    }
}
