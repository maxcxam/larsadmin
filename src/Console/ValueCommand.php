<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:value')]
class ValueCommand extends MetricCommand
{
    protected $name = 'larsadmin:value';

    protected $description = 'Create a new Larsadmin value metric class';

    protected $type = 'Value metric';

    protected function kind(): string
    {
        return 'value';
    }
}
