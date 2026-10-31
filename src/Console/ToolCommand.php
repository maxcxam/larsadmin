<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:tool')]
class ToolCommand extends ExtensionArtifactCommand
{
    protected $signature = 'larsadmin:tool
        {name : The tool class name, e.g. Backups}
        {--extension= : The extension package directory, relative to the application root (default: the application)}
        {--force : Overwrite existing files}';

    protected $description = 'Create a Larsadmin tool: a sidebar page (PHP class) rendered by a Vue component';

    protected function kind(): string
    {
        return 'tool';
    }

    protected function label(): string
    {
        return 'Tool';
    }
}
