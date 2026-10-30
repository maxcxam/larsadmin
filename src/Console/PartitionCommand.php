<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'larsadmin:partition')]
class PartitionCommand extends MetricCommand
{
    protected $name = 'larsadmin:partition';

    protected $description = 'Create a new Larsadmin partition metric class';

    protected $type = 'Partition metric';

    protected function stubName(): string
    {
        return 'partition.stub';
    }
}
