<?php

namespace Maxcxam\Larsadmin\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Extensions\Extension;
use Maxcxam\Larsadmin\Larsadmin;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

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

    public function handle(ExtensionScaffold $scaffold): int
    {
        $name = (string) $this->argument('name');

        try {
            if (preg_match(Extension::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException("The name [{$name}] is not a valid Composer package name (vendor/package).");
            }

            $directory = ExtensionScaffold::relativeDirectory((string) $this->option('path'), '--path').'/'.$name;
            $field = ExtensionScaffold::className((string) $this->option('field'));
            $card = ExtensionScaffold::className((string) $this->option('card'));

            if ($field === $card) {
                throw new InvalidArgumentException('The example field and card need different names.');
            }

            $files = $this->files($scaffold, $name, $directory, $field, $card);
            $root = base_path($directory);

            if (! $this->option('force') && ($existing = $scaffold->existing($root, $files)) !== []) {
                throw new InvalidArgumentException("{$directory} already contains: ".implode(', ', $existing).'. Use --force to overwrite.');
            }

            $scaffold->write($root, $files);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Extension [{$name}] created in [{$directory}].");
        $this->components->bulletList([
            "composer config repositories.{$this->repository($name)} path {$directory}",
            "composer require {$name}:@dev",
            "cd {$directory} && npm install && npm run build",
        ]);

        if (($version = $this->panelVersion()) !== null && str_contains($version, 'dev')) {
            $this->newLine();
            $this->warn("  Larsadmin is installed as [{$version}], while the package requires maxcxam/larsadmin:^".Larsadmin::API_VERSION.'.');
            $this->line('  Only the main branch is aliased as 1.x-dev. For another branch or a local checkout, use a path repository');
            $this->line('  that declares the version, e.g. "options": {"versions": {"maxcxam/larsadmin": "1.5.x-dev"}},');
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

    /**
     * The files of the package, relative path => contents.
     *
     * @return array<string, string>
     */
    protected function files(ExtensionScaffold $scaffold, string $name, string $directory, string $field, string $card): array
    {
        [$vendor, $package] = explode('/', $name, 2);

        $base = preg_replace('/^larsadmin-(?=.)/', '', $package);
        $namespace = $this->studly($vendor).'\\'.$this->studly($package);
        $cardVue = str_ends_with($card, 'Card') ? $card : $card.'Card';
        $keyVendor = ExtensionScaffold::keySegment($vendor);

        $replace = [
            'name' => $name,
            'namespace' => $namespace,
            'namespaceJson' => str_replace('\\', '\\\\', $namespace),
            'provider' => $this->studly($base).'ServiceProvider',
            'apiVersion' => Larsadmin::API_VERSION,
            'npmName' => '@'.$name,
            'hostKit' => ExtensionScaffold::hostKitPath($directory),
            'langNamespace' => $keyVendor.'-'.ExtensionScaffold::keySegment($base),
            'field' => $field,
            'attribute' => Str::snake($field),
            'fieldKey' => $keyVendor.'/'.Str::kebab($field),
            'card' => $card,
            'cardVue' => $cardVue,
            'cardKey' => $keyVendor.'/'.Str::kebab($card),
            'cardUriKey' => Str::kebab($card),
            'repository' => $this->repository($name),
            'directory' => $directory,
        ];

        $component = fn (string $key) => ['component' => $replace[$key]];

        return [
            'composer.json' => $scaffold->render('extension/composer.json.stub', $replace),
            'package.json' => $scaffold->render('extension/package.json.stub', $replace),
            'vite.config.js' => $scaffold->render('extension/vite.config.js.stub', $replace),
            '.gitignore' => $scaffold->render('extension/gitignore.stub', $replace),
            'README.md' => $scaffold->render('extension/README.md.stub', $replace),
            "src/{$replace['provider']}.php" => $scaffold->render('extension/provider.stub', $replace),
            "src/Fields/{$field}.php" => $scaffold->render('field.stub', ['namespace' => $namespace.'\\Fields', 'class' => $field, ...$component('fieldKey')]),
            "src/Cards/{$card}.php" => $scaffold->render('data-card.stub', ['namespace' => $namespace.'\\Cards', 'class' => $card, ...$component('cardKey')]),
            'resources/js/extension.js' => $scaffold->render('extension/extension.js.stub', $replace),
            "resources/js/fields/{$field}Input.vue" => $scaffold->render('field-form.vue.stub', $component('fieldKey')),
            "resources/js/fields/{$field}Display.vue" => $scaffold->render('field-display.vue.stub', $component('fieldKey')),
            "resources/js/fields/{$field}Input.test.js" => $scaffold->render('extension/field-input.test.js.stub', $replace),
            "resources/js/cards/{$cardVue}.vue" => $scaffold->render('data-card.vue.stub', $component('cardKey')),
            'resources/css/extension.css' => $scaffold->render('extension/extension.css.stub', $replace),
            'lang/en/ui.php' => $scaffold->render('extension/lang-en.stub', $replace),
            'lang/uk/ui.php' => $scaffold->render('extension/lang-uk.stub', $replace),
            'tests/Pest.php' => $scaffold->render('extension/Pest.php.stub', $replace),
            'tests/TestCase.php' => $scaffold->render('extension/TestCase.php.stub', $replace),
            'tests/Fixtures/User.php' => $scaffold->render('extension/User.php.stub', $replace),
            'tests/Fixtures/TestDashboard.php' => $scaffold->render('extension/TestDashboard.php.stub', $replace),
            'tests/FieldTest.php' => $scaffold->render('extension/FieldTest.php.stub', $replace),
            'tests/CardTest.php' => $scaffold->render('extension/CardTest.php.stub', $replace),
            'tests/HttpTest.php' => $scaffold->render('extension/HttpTest.php.stub', $replace),
        ];
    }

    /**
     * A PHP identifier from a package name segment.
     */
    protected function studly(string $value): string
    {
        $studly = Str::studly((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $value));

        return preg_match('/^[A-Za-z]/', $studly) === 1 ? $studly : 'Ext'.$studly;
    }

    protected function repository(string $name): string
    {
        return ExtensionScaffold::keySegment(str_replace('/', '-', $name));
    }
}
