<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Input\InputOption;

/**
 * Base of the metric generators: larsadmin:value, larsadmin:trend, larsadmin:partition and larsadmin:progress.
 *
 * @internal
 */
abstract class MetricCommand extends ClassGeneratorCommand
{
    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the '.strtolower($this->type).' already exists'],
        ];
    }
}
