<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

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
        return 'Tools';
    }

    protected function files(ExtensionScaffold $scaffold, string $class, array $target): array
    {
        return [
            "{$target['php']}/{$class}.php" => $scaffold->render('tool.stub', ['namespace' => $target['namespace'], 'class' => $class, 'component' => $target['key']]),
            "{$target['js']}/{$this->vueName($class)}.vue" => $scaffold->render('tool.vue.stub', ['component' => $target['key']]),
        ];
    }

    protected function registration(string $class, array $target): array
    {
        $vue = $this->vueName($class);

        return [
            "import {$vue} from './tools/{$vue}.vue'",
            "tools: { '{$target['key']}': {$vue} },",
        ];
    }

    protected function phpRegistration(string $class, array $target): array
    {
        return ["Larsadmin::tools([\\{$target['namespace']}\\{$class}::class]);"];
    }

    protected function vueName(string $class): string
    {
        return str_ends_with($class, 'Page') ? $class : $class.'Page';
    }
}
