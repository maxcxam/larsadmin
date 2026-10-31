<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Scaffolding\Scaffolder;

/**
 * Base of larsadmin:field, larsadmin:card and larsadmin:tool: a PHP class plus Vue components, inside an extension
 * package (--extension=packages/acme/larsadmin-rating) or in the application (app/Larsadmin, resources/larsadmin/js),
 * planned and written by the shared Scaffolder (ADR-039). extension.js is never edited: the command prints the lines
 * to register the components.
 *
 * @internal
 */
abstract class ExtensionArtifactCommand extends Command
{
    /**
     * The Scaffolder kind: "field", "card", "data-card" or "tool".
     */
    abstract protected function kind(): string;

    /**
     * What is generated, as shown in the output: "Field", "Card" or "Tool".
     */
    abstract protected function label(): string;

    public function handle(Scaffolder $scaffolder): int
    {
        try {
            $plan = $scaffolder->plan($this->kind(), (string) $this->argument('name'), ['extension' => $this->option('extension')]);

            if (! $this->option('force') && ($existing = $plan->existing()) !== []) {
                $this->components->error('Already exists: '.implode(', ', $existing).'. Use --force to overwrite.');

                return self::FAILURE;
            }

            $scaffolder->write($plan, (bool) $this->option('force'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $application = $plan->root === '.';

        $this->components->info("{$this->label()} [{$plan->label}] created with the component key [{$plan->key}].");
        $this->line('  Register it in '.($application ? 'resources/larsadmin/js/extension.js' : $plan->root.'/resources/js/extension.js').':');
        $this->newLine();

        foreach ($plan->registration as $line) {
            $this->line('    '.$line);
        }

        $this->newLine();

        if ($plan->phpRegistration !== []) {
            $this->line('  And in the boot() method of '.($application ? 'your LarsadminServiceProvider' : "the package's service provider").':');
            $this->newLine();

            foreach ($plan->phpRegistration as $line) {
                $this->line('    '.$line);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
