<?php

namespace Maxcxam\Larsadmin\Scaffolding;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Maxcxam\Larsadmin\Extensions\Extension;
use Maxcxam\Larsadmin\Larsadmin;
use ReflectionClass;

/**
 * The one implementation of the Larsadmin generators (ADR-039), used by the larsadmin:* artisan commands and the MCP
 * "generate" tool: names are validated, stubs rendered and target paths checked here, so both produce the same
 * bytes. plan() never writes; write() writes a plan, never overwrites without force, checks every target before
 * the first write and refuses paths outside the application root and links (dangling ones and junctions included).
 *
 * @internal
 */
final class Scaffolder
{
    public const string STUBS = __DIR__.'/../../stubs';

    /**
     * A PHP namespace: identifiers separated by single backslashes.
     */
    public const string NAMESPACE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/';

    /**
     * A class name in strict mode (MCP): StudlyCase segments, "/" for sub-namespaces.
     */
    public const string CLASS_PATTERN = '/^[A-Z][A-Za-z0-9]*(\/[A-Z][A-Za-z0-9]*)*\z/';

    /**
     * A model class in strict mode: identifiers separated by "\" or "/".
     */
    public const string MODEL_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*([\\\\\/][A-Za-z_][A-Za-z0-9_]*)*\z/';

    /**
     * The kinds and their options (name => type), the same as the options of the artisan commands.
     *
     * @var array<string, array<string, 'string'|'bool'>>
     */
    public const array KINDS = [
        'entity' => ['model' => 'string'],
        'action' => ['destructive' => 'bool'],
        'filter' => [],
        'dashboard' => [],
        'value' => [],
        'trend' => [],
        'partition' => [],
        'progress' => [],
        'field' => ['extension' => 'string'],
        'card' => ['extension' => 'string'],
        'data-card' => ['extension' => 'string'],
        'tool' => ['extension' => 'string'],
        'extension' => ['path' => 'string', 'field' => 'string', 'card' => 'string'],
    ];

    /**
     * The application class kinds: [stub, namespace below the root namespace, type shown by the command].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const array CLASSES = [
        'entity' => ['entity.stub', 'Larsadmin', 'Entity'],
        'action' => ['action.stub', 'Larsadmin\\Actions', 'Action'],
        'filter' => ['filter.stub', 'Larsadmin\\Filters', 'Filter'],
        'dashboard' => ['dashboard.stub', 'Larsadmin\\Dashboards', 'Dashboard'],
        'value' => ['value.stub', 'Larsadmin\\Metrics', 'Value metric'],
        'trend' => ['trend.stub', 'Larsadmin\\Metrics', 'Trend metric'],
        'partition' => ['partition.stub', 'Larsadmin\\Metrics', 'Partition metric'],
        'progress' => ['progress.stub', 'Larsadmin\\Metrics', 'Progress metric'],
    ];

    /**
     * The kinds made of a PHP class and Vue components, and their sub-directory.
     *
     * @var array<string, string>
     */
    public const array ARTIFACTS = [
        'field' => 'Fields',
        'card' => 'Cards',
        'data-card' => 'Cards',
        'tool' => 'Tools',
    ];

    /**
     * The first directory an extension may be generated in by the MCP tool (strict mode): the default of
     * larsadmin:extension --path and the conventional extensions root.
     *
     * @var list<string>
     */
    public const array STRICT_EXTENSION_ROOTS = ['packages', 'extensions'];

    /**
     * Directories no extension is generated in, anywhere on its path (compared case-insensitively).
     *
     * @var list<string>
     */
    public const array FORBIDDEN_DIRECTORIES = ['vendor', 'node_modules', 'storage', 'bootstrap', 'public'];

    public function __construct(protected Filesystem $files = new Filesystem) {}

    /**
     * What generating a kind would write. Strict mode (the MCP tool) also refuses unknown or mistyped options,
     * class names outside CLASS_PATTERN, unsafe model names and application classes outside app/Larsadmin.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    public function plan(string $kind, string $name, array $options = [], bool $strict = false): ScaffoldPlan
    {
        if (! array_key_exists($kind, self::KINDS)) {
            throw new InvalidArgumentException("Unknown kind [{$kind}]. Use one of: ".implode(', ', array_keys(self::KINDS)).'.');
        }

        if ($strict) {
            $this->validateStrict($kind, $name, $options);
        }

        return match (true) {
            isset(self::CLASSES[$kind]) => $this->classPlan($kind, $name, $options, $strict),
            isset(self::ARTIFACTS[$kind]) => $this->artifactPlan($kind, $name, $options, $strict),
            default => $this->extensionPlan($name, $options, $strict),
        };
    }

    /**
     * Write a plan: nothing that exists is changed without $force, and every target is checked before the first
     * write. The written paths, relative to base_path().
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public function write(ScaffoldPlan $plan, bool $force = false): array
    {
        if (! $force && ($existing = $plan->existing()) !== []) {
            throw new InvalidArgumentException('Already exists: '.implode(', ', $existing).'. Use --force to overwrite.');
        }

        // Checked for every target before anything is written: a link inside the target must not lead out, and no
        // segment may be one that Win32 normalises away (dots only, a trailing dot).
        foreach ($plan->files as $file) {
            foreach (explode('/', $file->path) as $segment) {
                if ($segment === '' || str_ends_with($segment, '.')) {
                    throw new InvalidArgumentException("The file [{$file->local}] has an invalid path segment.");
                }
            }

            if (! self::insideBase($file->absolute)) {
                throw new InvalidArgumentException("The file [{$file->local}] would be written outside the application root.");
            }
        }

        foreach ($plan->files as $file) {
            $directory = dirname($file->absolute);

            if (! $this->files->isDirectory($directory)) {
                $this->files->makeDirectory($directory, $plan->directoryMode, true, true);
            }

            $this->files->put($file->absolute, $file->contents);
        }

        return array_map(fn (ScaffoldFile $file) => $file->path, $plan->files);
    }

    /**
     * Resolve a directory given relative to the application root; absolute paths, ".." segments, backslashes
     * and anything outside the root are refused.
     *
     * @throws InvalidArgumentException
     */
    public static function relativeDirectory(string $path, string $option): string
    {
        $path = trim($path);

        if ($path === '' || preg_match('#^([A-Za-z]:|/|\\\\)#', $path) === 1 || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidArgumentException("The {$option} must be a directory relative to the application root.");
        }

        $segments = array_values(array_filter(explode('/', $path), fn (string $segment) => $segment !== '' && $segment !== '.'));

        foreach ($segments as $segment) {
            // Segments of dots only or ending in a dot are normalised away by Win32 ("..." is the directory itself).
            if (str_ends_with($segment, '.') || preg_match('/^[A-Za-z0-9_.@-]+\z/', $segment) !== 1) {
                throw new InvalidArgumentException("The {$option} [{$path}] must stay inside the application root.");
            }
        }

        if ($segments === []) {
            throw new InvalidArgumentException("The {$option} must be a directory relative to the application root.");
        }

        $relative = implode('/', $segments);

        // Lexically safe is not enough: a symlink or junction below the root may point elsewhere.
        if (! self::insideBase(base_path($relative))) {
            throw new InvalidArgumentException("The {$option} [{$path}] leads outside the application root.");
        }

        return $relative;
    }

    /**
     * Whether a path (existing or not) stays inside the application root once links are resolved: no link below
     * the root on the way to it, and the realpath of its deepest existing ancestor (the path itself when it
     * exists) lies inside realpath(base_path()).
     */
    public static function insideBase(string $path): bool
    {
        $base = realpath(base_path());

        if ($base === false) {
            return false;
        }

        // No link (dangling ones included, which file_exists() does not see) below the root: writes would follow it.
        $root = rtrim(base_path(), '/\\');

        for ($segment = $path; strlen($segment) > strlen($root) && dirname($segment) !== $segment; $segment = dirname($segment)) {
            if (self::isLink($segment)) {
                return false;
            }
        }

        $existing = $path;

        while (! file_exists($existing)) {
            $parent = dirname($existing);

            if ($parent === $existing) {
                return false;
            }

            $existing = $parent;
        }

        $real = realpath($existing);

        return $real !== false && ($real === $base || str_starts_with($real, rtrim($base, '/\\').DIRECTORY_SEPARATOR));
    }

    /**
     * Whether a path is a link: a symlink, or anything that is neither a plain file nor a plain directory (PHP
     * on Windows reports a junction as type "unknown" and is_link() false for it).
     */
    public static function isLink(string $path): bool
    {
        if (is_link($path)) {
            return true;
        }

        $type = @filetype($path);

        return $type !== false && $type !== 'dir' && $type !== 'file';
    }

    /**
     * A class name for generated files: StudlyCase letters and digits.
     *
     * @throws InvalidArgumentException
     */
    public static function className(string $name): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*\z/', $name) !== 1) {
            throw new InvalidArgumentException("The name [{$name}] must start with a letter and contain letters and digits only.");
        }

        $class = Str::studly($name);

        if (self::isReservedName($class)) {
            throw new InvalidArgumentException("The name [{$class}] is reserved by PHP.");
        }

        return $class;
    }

    /**
     * Whether a class name is reserved by PHP: the list of Laravel's GeneratorCommand, which the artisan class
     * generators check too, compared case-insensitively.
     */
    public static function isReservedName(string $name): bool
    {
        static $reserved = null;

        $reserved ??= array_map('strtolower', (array) ((new ReflectionClass(GeneratorCommand::class))->getDefaultProperties()['reservedNames'] ?? []));

        return in_array(strtolower($name), $reserved, true);
    }

    /**
     * A component key segment ([a-z0-9-]+) derived from a vendor or package name.
     */
    public static function keySegment(string $value): string
    {
        $segment = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');

        return $segment === '' ? 'x' : $segment;
    }

    /**
     * The relative path from a directory under the application root to the larsadmin kit in the root's vendor/.
     */
    public static function hostKitPath(string $relativeDirectory): string
    {
        return str_repeat('../', substr_count($relativeDirectory, '/') + 1).'vendor/maxcxam/larsadmin/kit';
    }

    /**
     * Read an extension package's composer.json: the package name and the PSR-4 namespace of src/.
     *
     * @return array{name: string, namespace: string}
     *
     * @throws InvalidArgumentException when the directory is not an extension package
     */
    public function package(string $directory): array
    {
        $composer = $directory.'/composer.json';
        $json = is_file($composer) ? json_decode((string) file_get_contents($composer), true) : null;

        if (! is_array($json) || ! is_string($json['name'] ?? null) || preg_match(Extension::NAME_PATTERN, $json['name']) !== 1) {
            throw new InvalidArgumentException("No extension package (composer.json with a vendor/name) in [{$directory}].");
        }

        foreach ((array) ($json['autoload']['psr-4'] ?? []) as $namespace => $source) {
            if (is_string($namespace) && is_string($source) && in_array(rtrim($source, '/'), ['src', './src'], true)) {
                $namespace = rtrim($namespace, '\\');

                // The namespace is written into generated PHP: only a syntactically valid namespace is accepted.
                if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
                    throw new InvalidArgumentException("The PSR-4 namespace [{$namespace}] in [{$directory}/composer.json] is not a valid PHP namespace.");
                }

                return ['name' => $json['name'], 'namespace' => $namespace];
            }
        }

        throw new InvalidArgumentException("The composer.json in [{$directory}] has no PSR-4 namespace for src/.");
    }

    /**
     * Render a stub with {{ key }} placeholders.
     *
     * @param  array<string, string>  $replace
     */
    public function render(string $stub, array $replace): string
    {
        $pairs = [];

        foreach ($replace as $key => $value) {
            $pairs['{{ '.$key.' }}'] = $value;
        }

        return strtr($this->files->get(self::STUBS.'/'.$stub), $pairs);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    protected function validateStrict(string $kind, string $name, array $options): void
    {
        foreach ($options as $key => $value) {
            $type = self::KINDS[$kind][$key] ?? null;

            if ($type === null) {
                $allowed = array_keys(self::KINDS[$kind]);

                throw new InvalidArgumentException("Unknown option [{$key}] for [{$kind}]".($allowed === [] ? ': it takes no options.' : '. Use: '.implode(', ', $allowed).'.'));
            }

            if ($type === 'bool' ? ! is_bool($value) : ! is_string($value)) {
                throw new InvalidArgumentException("The option [{$key}] must be a {$type}.");
            }
        }

        foreach ($kind === 'extension' ? [] : explode('/', $name) as $segment) {
            if (self::isReservedName($segment)) {
                throw new InvalidArgumentException("The name [{$segment}] is reserved by PHP.");
            }
        }

        if ($kind !== 'extension' && preg_match(self::CLASS_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("The name [{$name}] must be a StudlyCase class name (sub-namespaces with \"/\").");
        }

        if (isset($options['model']) && $options['model'] !== '' && preg_match(self::MODEL_PATTERN, ltrim($options['model'], '\\/')) !== 1) {
            throw new InvalidArgumentException("The model [{$options['model']}] must be a class name.");
        }
    }

    /**
     * An application class generated like Laravel's GeneratorCommand (the commands' 1.5 implementation).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    protected function classPlan(string $kind, string $name, array $options, bool $strict): ScaffoldPlan
    {
        [$stub, $namespace, $type] = self::CLASSES[$kind];

        $rootNamespace = app()->getNamespace();
        $class = $this->qualifyClass($name, $rootNamespace, $namespace);
        $absolute = app('path').'/'.str_replace('\\', '/', Str::replaceFirst($rootNamespace, '', $class)).'.php';

        if ($strict && ! str_starts_with(self::slashes($absolute), self::slashes(app_path('Larsadmin')).'/')) {
            throw new InvalidArgumentException("The class [{$class}] would be generated outside app/Larsadmin.");
        }

        $contents = $this->buildClass($stub, $class, $rootNamespace);

        if ($kind === 'entity') {
            $model = (string) ($options['model'] ?? '') ?: class_basename($class);
            $contents = str_replace('{{ model }}', ltrim($this->qualifyModel($model, $rootNamespace), '\\'), $contents);
        }

        $contents = str_replace('{{ label }}', Str::headline(class_basename($class)), $contents);

        if ($kind === 'action') {
            $contents = str_replace('{{ destructive }}', ($options['destructive'] ?? false) ? 'true' : 'false', $contents);
        }

        $path = self::relative($absolute);

        return new ScaffoldPlan(
            kind: $kind,
            root: '.',
            files: [new ScaffoldFile($path, $path, $absolute, $this->sortImports($contents), $this->files->exists($absolute))],
            label: $type,
            nextSteps: $this->classNextSteps($kind, $class),
            directoryMode: 0777,
        );
    }

    /**
     * A field, card, data card or tool: a PHP class and its Vue components, in an extension package (option
     * "extension") or in the application (app/Larsadmin, resources/larsadmin/js).
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    protected function artifactPlan(string $kind, string $name, array $options, bool $strict = false): ScaffoldPlan
    {
        $class = self::className($name);
        $target = $this->artifactTarget(self::ARTIFACTS[$kind], $class, $options['extension'] ?? null, $strict);
        $replace = ['namespace' => $target['namespace'], 'class' => $class, 'component' => $target['key'], 'label' => Str::headline($class)];
        $component = ['component' => $target['key']];

        [$files, $registration, $php] = match ($kind) {
            'field' => [
                [
                    "{$target['php']}/{$class}.php" => $this->render('field.stub', $replace),
                    "{$target['js']}/{$class}Input.vue" => $this->render('field-form.vue.stub', $component),
                    "{$target['js']}/{$class}Display.vue" => $this->render('field-display.vue.stub', $component),
                ],
                [
                    "import {$class}Input from './fields/{$class}Input.vue'",
                    "import {$class}Display from './fields/{$class}Display.vue'",
                    "fields: { '{$target['key']}': { form: {$class}Input, display: {$class}Display } },",
                ],
                [],
            ],
            'card', 'data-card' => [
                [
                    "{$target['php']}/{$class}.php" => $this->render($kind === 'data-card' ? 'data-card.stub' : 'card.stub', $replace),
                    "{$target['js']}/{$this->suffixed($class, 'Card')}.vue" => $this->render($kind === 'data-card' ? 'data-card.vue.stub' : 'card.vue.stub', $component),
                ],
                [
                    "import {$this->suffixed($class, 'Card')} from './cards/{$this->suffixed($class, 'Card')}.vue'",
                    "cards: { '{$target['key']}': {$this->suffixed($class, 'Card')} },",
                ],
                [],
            ],
            default => [
                [
                    "{$target['php']}/{$class}.php" => $this->render('tool.stub', $replace),
                    "{$target['js']}/{$this->suffixed($class, 'Page')}.vue" => $this->render('tool.vue.stub', $component),
                ],
                [
                    "import {$this->suffixed($class, 'Page')} from './tools/{$this->suffixed($class, 'Page')}.vue'",
                    "tools: { '{$target['key']}': {$this->suffixed($class, 'Page')} },",
                ],
                ["Larsadmin::tools([\\{$target['namespace']}\\{$class}::class]);"],
            ],
        };

        $application = $target['root'] === '.';
        $nextSteps = ['Register it in '.($application ? 'resources/larsadmin/js/extension.js' : $target['root'].'/resources/js/extension.js').":\n".implode("\n", $registration)];

        if ($php !== []) {
            $nextSteps[] = 'And in the boot() method of '.($application ? 'your LarsadminServiceProvider' : "the package's service provider").":\n".implode("\n", $php);
        }

        return new ScaffoldPlan(
            kind: $kind,
            root: $target['root'],
            files: $this->files($target['root'], $files),
            label: $class,
            key: $target['key'],
            registration: $registration,
            phpRegistration: $php,
            nextSteps: $nextSteps,
        );
    }

    /**
     * An extension package directory (--path / --extension): relative and inside the root, never in a dot-folder
     * or a dependency, build or storage directory; in strict mode (MCP) only below STRICT_EXTENSION_ROOTS.
     *
     * @throws InvalidArgumentException
     */
    protected function extensionDirectory(string $path, string $option, bool $strict): string
    {
        $directory = self::relativeDirectory($path, $option);
        $segments = array_map('strtolower', explode('/', $directory));

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.') || in_array($segment, self::FORBIDDEN_DIRECTORIES, true)) {
                throw new InvalidArgumentException("The {$option} [{$path}] must not be in [{$segment}].");
            }
        }

        if ($strict && ! in_array($segments[0], self::STRICT_EXTENSION_ROOTS, true)) {
            throw new InvalidArgumentException("The {$option} [{$path}] must be below ".implode('/ or ', self::STRICT_EXTENSION_ROOTS).'/.');
        }

        return $directory;
    }

    /**
     * Where the files of a field, card or tool go.
     *
     * @return array{root: string, namespace: string, key: string, php: string, js: string}
     *
     * @throws InvalidArgumentException
     */
    protected function artifactTarget(string $kind, string $class, mixed $extension, bool $strict = false): array
    {
        $key = Str::kebab($class);

        if (is_string($extension) && $extension !== '') {
            $directory = $this->extensionDirectory($extension, '--extension', $strict);
            $package = $this->package(base_path($directory));

            return [
                'root' => $directory,
                'namespace' => $package['namespace'].'\\'.$kind,
                'key' => self::keySegment(explode('/', $package['name'])[0]).'/'.$key,
                'php' => 'src/'.$kind,
                'js' => 'resources/js/'.strtolower($kind),
            ];
        }

        return [
            'root' => '.',
            'namespace' => rtrim(app()->getNamespace(), '\\').'\\Larsadmin\\'.$kind,
            'key' => 'app/'.$key,
            'php' => 'app/Larsadmin/'.$kind,
            'js' => 'resources/larsadmin/js/'.strtolower($kind),
        ];
    }

    /**
     * An extension package with an example field and data card.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    protected function extensionPlan(string $name, array $options, bool $strict = false): ScaffoldPlan
    {
        if (preg_match(Extension::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("The name [{$name}] is not a valid Composer package name (vendor/package).");
        }

        $directory = $this->extensionDirectory((string) ($options['path'] ?? 'packages'), '--path', $strict).'/'.$name;
        $field = self::className((string) ($options['field'] ?? 'ExampleField'));
        $card = self::className((string) ($options['card'] ?? 'ExampleCard'));

        if ($field === $card) {
            throw new InvalidArgumentException('The example field and card need different names.');
        }

        [$vendor, $package] = explode('/', $name, 2);

        $base = preg_replace('/^larsadmin-(?=.)/', '', $package);
        $namespace = $this->studly($vendor).'\\'.$this->studly($package);
        $keyVendor = self::keySegment($vendor);
        $repository = self::keySegment(str_replace('/', '-', $name));

        $replace = [
            'name' => $name,
            'namespace' => $namespace,
            'namespaceJson' => str_replace('\\', '\\\\', $namespace),
            'provider' => $this->studly($base).'ServiceProvider',
            'apiVersion' => Larsadmin::API_VERSION,
            'npmName' => '@'.$name,
            'hostKit' => self::hostKitPath($directory),
            'langNamespace' => $keyVendor.'-'.self::keySegment($base),
            'field' => $field,
            'attribute' => Str::snake($field),
            'fieldKey' => $keyVendor.'/'.Str::kebab($field),
            'card' => $card,
            'cardVue' => $this->suffixed($card, 'Card'),
            'cardKey' => $keyVendor.'/'.Str::kebab($card),
            'cardUriKey' => Str::kebab($card),
            'repository' => $repository,
            'directory' => $directory,
        ];

        $component = fn (string $key) => ['component' => $replace[$key]];

        $files = [
            'composer.json' => $this->render('extension/composer.json.stub', $replace),
            'package.json' => $this->render('extension/package.json.stub', $replace),
            'vite.config.js' => $this->render('extension/vite.config.js.stub', $replace),
            '.gitignore' => $this->render('extension/gitignore.stub', $replace),
            'README.md' => $this->render('extension/README.md.stub', $replace),
            "src/{$replace['provider']}.php" => $this->render('extension/provider.stub', $replace),
            "src/Fields/{$field}.php" => $this->render('field.stub', ['namespace' => $namespace.'\\Fields', 'class' => $field, ...$component('fieldKey')]),
            "src/Cards/{$card}.php" => $this->render('data-card.stub', ['namespace' => $namespace.'\\Cards', 'class' => $card, ...$component('cardKey')]),
            'resources/js/extension.js' => $this->render('extension/extension.js.stub', $replace),
            "resources/js/fields/{$field}Input.vue" => $this->render('field-form.vue.stub', $component('fieldKey')),
            "resources/js/fields/{$field}Display.vue" => $this->render('field-display.vue.stub', $component('fieldKey')),
            "resources/js/fields/{$field}Input.test.js" => $this->render('extension/field-input.test.js.stub', $replace),
            "resources/js/cards/{$replace['cardVue']}.vue" => $this->render('data-card.vue.stub', $component('cardKey')),
            'resources/css/extension.css' => $this->render('extension/extension.css.stub', $replace),
            'lang/en/ui.php' => $this->render('extension/lang-en.stub', $replace),
            'lang/uk/ui.php' => $this->render('extension/lang-uk.stub', $replace),
            'tests/Pest.php' => $this->render('extension/Pest.php.stub', $replace),
            'tests/TestCase.php' => $this->render('extension/TestCase.php.stub', $replace),
            'tests/Fixtures/User.php' => $this->render('extension/User.php.stub', $replace),
            'tests/Fixtures/TestDashboard.php' => $this->render('extension/TestDashboard.php.stub', $replace),
            'tests/FieldTest.php' => $this->render('extension/FieldTest.php.stub', $replace),
            'tests/CardTest.php' => $this->render('extension/CardTest.php.stub', $replace),
            'tests/HttpTest.php' => $this->render('extension/HttpTest.php.stub', $replace),
        ];

        return new ScaffoldPlan(
            kind: 'extension',
            root: $directory,
            files: $this->files($directory, $files),
            label: $name,
            nextSteps: [
                "composer config repositories.{$repository} path {$directory}",
                "composer require {$name}:@dev",
                "cd {$directory} && npm install && npm run build",
            ],
        );
    }

    /**
     * The plan files of a root directory (relative path => contents).
     *
     * @param  array<string, string>  $files
     * @return list<ScaffoldFile>
     */
    protected function files(string $root, array $files): array
    {
        $directory = base_path($root);
        $plan = [];

        foreach ($files as $local => $contents) {
            $absolute = $directory.'/'.$local;
            $plan[] = new ScaffoldFile(
                $root === '.' ? $local : $root.'/'.$local,
                $local,
                $absolute,
                $contents,
                $this->files->exists($absolute) || self::isLink($absolute),
            );
        }

        return $plan;
    }

    /**
     * Short hints after generating an application class.
     *
     * @return list<string>
     */
    protected function classNextSteps(string $kind, string $class): array
    {
        return match ($kind) {
            'entity' => ['Entities in app/Larsadmin are registered automatically. Create a policy for the model; without one, access follows larsadmin.allow_without_policy (default: allowed).'],
            'action' => ["Return it from an entity's actions(Request \$request): \\{$class}::make()"],
            'filter' => ["Return it from an entity's filters(Request \$request): \\{$class}::make()"],
            'dashboard' => ["Add it to dashboards() in app/Providers/LarsadminServiceProvider.php: \\{$class}::make()"],
            default => ["Return it from an entity's or a dashboard's cards(Request \$request): \\{$class}::make()"],
        };
    }

    /**
     * GeneratorCommand::qualifyClass().
     */
    protected function qualifyClass(string $name, string $rootNamespace, string $namespace): string
    {
        $name = str_replace('/', '\\', ltrim($name, '\\/'));

        if (Str::startsWith($name, $rootNamespace)) {
            return $name;
        }

        return $this->qualifyClass(trim($rootNamespace, '\\').'\\'.$namespace.'\\'.$name, $rootNamespace, $namespace);
    }

    /**
     * GeneratorCommand::qualifyModel().
     */
    protected function qualifyModel(string $model, string $rootNamespace): string
    {
        $model = str_replace('/', '\\', ltrim($model, '\\/'));

        if (Str::startsWith($model, $rootNamespace)) {
            return $model;
        }

        return is_dir(app_path('Models')) ? $rootNamespace.'Models\\'.$model : $rootNamespace.$model;
    }

    /**
     * GeneratorCommand::buildClass(): the namespace and class placeholders of a stub.
     */
    protected function buildClass(string $stub, string $class, string $rootNamespace): string
    {
        $contents = $this->files->get(self::STUBS.'/'.$stub);
        $namespace = trim(implode('\\', array_slice(explode('\\', $class), 0, -1)), '\\');
        $config = app('config');
        $userModel = $config->get('auth.providers.'.$config->get('auth.guards.'.$config->get('auth.defaults.guard').'.provider').'.model');

        foreach ([
            ['DummyNamespace', 'DummyRootNamespace', 'NamespacedDummyUserModel'],
            ['{{ namespace }}', '{{ rootNamespace }}', '{{ namespacedUserModel }}'],
            ['{{namespace}}', '{{rootNamespace}}', '{{namespacedUserModel}}'],
        ] as $search) {
            $contents = str_replace($search, [$namespace, $rootNamespace, $userModel], $contents);
        }

        return str_replace(['DummyClass', '{{ class }}', '{{class}}'], str_replace($namespace.'\\', '', $class), $contents);
    }

    /**
     * GeneratorCommand::sortImports().
     */
    protected function sortImports(string $contents): string
    {
        if (preg_match('/(?P<imports>(?:^use [^;{]+;$\n?)+)/m', $contents, $match)) {
            $imports = explode("\n", trim($match['imports']));

            sort($imports);

            return str_replace(trim($match['imports']), implode("\n", $imports), $contents);
        }

        return $contents;
    }

    /**
     * A PHP identifier from a package name segment.
     */
    protected function studly(string $value): string
    {
        $studly = Str::studly((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $value));

        return preg_match('/^[A-Za-z]/', $studly) === 1 ? $studly : 'Ext'.$studly;
    }

    protected function suffixed(string $class, string $suffix): string
    {
        return str_ends_with($class, $suffix) ? $class : $class.$suffix;
    }

    protected static function slashes(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * A path relative to base_path() with forward slashes, or the absolute path when it is outside.
     */
    protected static function relative(string $absolute): string
    {
        $base = self::slashes(base_path());
        $path = str_replace('\\', '/', $absolute);

        return str_starts_with($path, $base.'/') ? substr($path, strlen($base) + 1) : $path;
    }
}
