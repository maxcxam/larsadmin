<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

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
        return 'Fields';
    }

    protected function files(ExtensionScaffold $scaffold, string $class, array $target): array
    {
        return [
            "{$target['php']}/{$class}.php" => $scaffold->render('field.stub', ['namespace' => $target['namespace'], 'class' => $class, 'component' => $target['key']]),
            "{$target['js']}/{$class}Input.vue" => $scaffold->render('field-form.vue.stub', ['component' => $target['key']]),
            "{$target['js']}/{$class}Display.vue" => $scaffold->render('field-display.vue.stub', ['component' => $target['key']]),
        ];
    }

    protected function registration(string $class, array $target): array
    {
        return [
            "import {$class}Input from './fields/{$class}Input.vue'",
            "import {$class}Display from './fields/{$class}Display.vue'",
            "fields: { '{$target['key']}': { form: {$class}Input, display: {$class}Display } },",
        ];
    }
}
