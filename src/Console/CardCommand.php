<?php

namespace Maxcxam\Larsadmin\Console;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
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
        return $this->option('data') ? 'data-card' : 'card';
    }

    protected function label(): string
    {
        return 'Card';
    }
}
