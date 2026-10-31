<?php

namespace Maxcxam\Larsadmin\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Scaffolding\Scaffolder;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'larsadmin:extension')]
class ExtensionCommand extends Command
{
    protected $signature = 'larsadmin:extension
        {name : The Composer package name, e.g. acme/larsadmin-rating}
        {--path=packages : The directory, relative to the application root, the package goes to PATH/VENDOR/PACKAGE}
        {--field=ExampleField : The class name of the example field}
        {--card=ExampleCard : The class name of the example data card}
        {--force : Overwrite existing files}';

    protected $description = 'Create a Larsadmin extension package with an example field and data card';

    public function handle(Scaffolder $scaffolder): int
    {
        $name = (string) $this->argument('name');

        try {
            $plan = $scaffolder->plan('extension', $name, [
                'path' => (string) $this->option('path'),
                'field' => (string) $this->option('field'),
                'card' => (string) $this->option('card'),
            ]);

            if (! $this->option('force') && ($existing = $plan->existing()) !== []) {
                throw new InvalidArgumentException("{$plan->root} already contains: ".implode(', ', $existing).'. Use --force to overwrite.');
            }

            $scaffolder->write($plan, (bool) $this->option('force'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Extension [{$name}] created in [{$plan->root}].");
        $this->components->bulletList($plan->nextSteps);

        if (($version = $this->panelVersion()) !== null && str_contains($version, 'dev')) {
            $this->newLine();
            $this->warn("  Larsadmin is installed as [{$version}], while the package requires maxcxam/larsadmin:^".Larsadmin::API_VERSION.'.');
            $this->line('  Only the main branch is aliased as 1.x-dev. For another branch or a local checkout, use a path repository');
            $this->line('  that declares the version, e.g. "options": {"versions": {"maxcxam/larsadmin": "'.Larsadmin::API_VERSION.'.x-dev"}},');
            $this->line("  allow dev stability, and require the package with: composer require {$name}:@dev -W");
        }

        return self::SUCCESS;
    }

    /**
     * The installed version of the panel, if Composer knows it.
     */
    protected function panelVersion(): ?string
    {
        try {
            return InstalledVersions::isInstalled('maxcxam/larsadmin') ? InstalledVersions::getPrettyVersion('maxcxam/larsadmin') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
