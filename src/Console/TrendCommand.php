<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'larsadmin:trend')]
class TrendCommand extends MetricCommand
{
    protected $name = 'larsadmin:trend';

    protected $description = 'Create a new Larsadmin trend metric class';

    protected $type = 'Trend metric';

    protected function stubName(): string
    {
        return 'trend.stub';
    }
}
