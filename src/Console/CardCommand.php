<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'larsadmin:card')]
class CardCommand extends ExtensionArtifactCommand
{
    protected $signature = 'larsadmin:card
        {name : The card class name, e.g. TopRated}
        {--extension= : The extension package directory, relative to the application root (default: the application)}
        {--data : Create a DataCard that loads its data from the card endpoint}
        {--force : Overwrite existing files}';

    protected $description = 'Create a custom Larsadmin card: a PHP class (Card or DataCard) with its Vue component';

    protected function kind(): string
    {
        return 'Cards';
    }

    protected function files(ExtensionScaffold $scaffold, string $class, array $target): array
    {
        $data = (bool) $this->option('data');

        return [
            "{$target['php']}/{$class}.php" => $scaffold->render($data ? 'data-card.stub' : 'card.stub', ['namespace' => $target['namespace'], 'class' => $class, 'component' => $target['key']]),
            "{$target['js']}/{$this->vueName($class)}.vue" => $scaffold->render($data ? 'data-card.vue.stub' : 'card.vue.stub', ['component' => $target['key']]),
        ];
    }

    protected function registration(string $class, array $target): array
    {
        $vue = $this->vueName($class);

        return [
            "import {$vue} from './cards/{$vue}.vue'",
            "cards: { '{$target['key']}': {$vue} },",
        ];
    }

    protected function vueName(string $class): string
    {
        return str_ends_with($class, 'Card') ? $class : $class.'Card';
    }
}
