<?php

namespace Maxcxam\Larsadmin\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Base of larsadmin:field and larsadmin:card: a PHP class plus Vue components, inside an extension package
 * (--extension=packages/acme/larsadmin-rating) or in the application (app/Larsadmin, resources/larsadmin/js).
 * extension.js is never edited: the command prints the line to register the components.
 */
abstract class ExtensionArtifactCommand extends Command
{
    /**
     * The sub-directory of the kind: 'Fields' or 'Cards'.
     */
    abstract protected function kind(): string;

    /**
     * The files to create, relative path => contents.
     *
     * @param  array{root: string, namespace: string, key: string, php: string, js: string}  $target
     * @return array<string, string>
     */
    abstract protected function files(ExtensionScaffold $scaffold, string $class, array $target): array;

    /**
     * The lines that register the components in defineExtension().
     *
     * @param  array{root: string, namespace: string, key: string, php: string, js: string}  $target
     * @return list<string>
     */
    abstract protected function registration(string $class, array $target): array;

    public function handle(ExtensionScaffold $scaffold): int
    {
        try {
            $class = ExtensionScaffold::className((string) $this->argument('name'));
            $target = $this->target($scaffold, $class);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $files = $this->files($scaffold, $class, $target);
        $root = base_path($target['root']);

        if (! $this->option('force') && ($existing = $scaffold->existing($root, $files)) !== []) {
            $this->components->error('Already exists: '.implode(', ', $existing).'. Use --force to overwrite.');

            return self::FAILURE;
        }

        try {
            $scaffold->write($root, $files);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(Str::singular($this->kind())." [{$class}] created with the component key [{$target['key']}].");
        $this->line('  Register it in '.($target['root'] === '.' ? 'resources/larsadmin/js/extension.js' : $target['root'].'/resources/js/extension.js').':');
        $this->newLine();

        foreach ($this->registration($class, $target) as $line) {
            $this->line('    '.$line);
        }

        $this->newLine();

        if (($php = $this->phpRegistration($class, $target)) !== []) {
            $this->line('  And in the boot() method of '.($target['root'] === '.' ? 'your LarsadminServiceProvider' : "the package's service provider").':');
            $this->newLine();

            foreach ($php as $line) {
                $this->line('    '.$line);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * The PHP lines that register the class, if it needs registering (tools).
     *
     * @param  array{root: string, namespace: string, key: string, php: string, js: string}  $target
     * @return list<string>
     */
    protected function phpRegistration(string $class, array $target): array
    {
        return [];
    }

    /**
     * Where the files go: inside the extension package given with --extension, or the application.
     *
     * @return array{root: string, namespace: string, key: string, php: string, js: string}
     *
     * @throws InvalidArgumentException
     */
    protected function target(ExtensionScaffold $scaffold, string $class): array
    {
        $extension = $this->option('extension');
        $key = Str::kebab($class);

        if (is_string($extension) && $extension !== '') {
            $directory = ExtensionScaffold::relativeDirectory($extension, '--extension');
            $package = $scaffold->package(base_path($directory));

            return [
                'root' => $directory,
                'namespace' => $package['namespace'].'\\'.$this->kind(),
                'key' => ExtensionScaffold::keySegment(explode('/', $package['name'])[0]).'/'.$key,
                'php' => 'src/'.$this->kind(),
                'js' => 'resources/js/'.strtolower($this->kind()),
            ];
        }

        return [
            'root' => '.',
            'namespace' => rtrim(app()->getNamespace(), '\\').'\\Larsadmin\\'.$this->kind(),
            'key' => 'app/'.$key,
            'php' => 'app/Larsadmin/'.$this->kind(),
            'js' => 'resources/larsadmin/js/'.strtolower($this->kind()),
        ];
    }
}
