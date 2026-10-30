<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'larsadmin:entity')]
class EntityCommand extends GeneratorCommand
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

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/entity.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Larsadmin';
    }

    protected function buildClass($name): string
    {
        $model = $this->option('model') ?: class_basename($name);

        return str_replace(
            '{{ model }}',
            ltrim($this->qualifyModel($model), '\\'),
            parent::buildClass($name),
        );
    }

    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The model the entity corresponds to'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the entity already exists'],
        ];
    }
}
