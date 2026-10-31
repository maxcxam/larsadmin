<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:field')]
class FieldCommand extends ExtensionArtifactCommand
{
    protected $signature = 'larsadmin:field
        {name : The field class name, e.g. Rating}
        {--extension= : The extension package directory, relative to the application root (default: the application)}
        {--force : Overwrite existing files}';

    protected $description = 'Create a custom Larsadmin field: a PHP class with its form and display Vue components';

    protected function kind(): string
    {
        return 'field';
    }

    protected function label(): string
    {
        return 'Field';
    }
}
