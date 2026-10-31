<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:entity')]
class EntityCommand extends ClassGeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'larsadmin:entity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Larsadmin entity class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Entity';

    protected function kind(): string
    {
        return 'entity';
    }

    protected function scaffoldOptions(): array
    {
        return ['model' => (string) $this->option('model')];
    }

    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The model the entity corresponds to'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the entity already exists'],
        ];
    }
}
