<?php

use Illuminate\Filesystem\Filesystem;
use Maxcxam\Larsadmin\Extensions\Extension;
use Maxcxam\Larsadmin\Extensions\ExtensionAssets;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\FixtureExtensions;

it('registers an extension with its real directory and the default files', function () {
    $extension = Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');

    expect($extension->name)->toBe('acme/demo')
        ->and($extension->path)->toBe(realpath(FixtureExtensions::DEMO.'/dist'))
        ->and($extension->scriptFile())->toBe('extension.js')
        ->and($extension->styleFile())->toBe('extension.css')
        ->and($extension->translationGroups())->toBe([])
        ->and(Larsadmin::registeredExtensions())->toBe(['acme/demo' => $extension])
        ->and(Larsadmin::extensionFor('acme/demo'))->toBe($extension)
        ->and(Larsadmin::extensionFor('acme/other'))->toBeNull();
});

it('returns the same extension when registered again with the same directory', function () {
    $first = Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');
    $second = Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/../acme-demo/dist/');

    expect($second)->toBe($first)->and(Larsadmin::registeredExtensions())->toHaveCount(1);
});

it('refuses the same name with another directory', function () {
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');

    Larsadmin::extension('acme/demo', FixtureExtensions::PLAIN.'/dist');
})->throws(InvalidArgumentException::class, 'already registered');

it('refuses invalid package names', function (string $name) {
    Larsadmin::extension($name, FixtureExtensions::DEMO.'/dist');
})->with([
    'no vendor' => ['demo'],
    'upper case' => ['Acme/Demo'],
    'two slashes' => ['acme/demo/x'],
    'traversal' => ['../demo'],
    'empty package' => ['acme/'],
    'leading dash' => ['acme/-demo'],
    'space' => ['acme/de mo'],
    'trailing new line' => ["acme/demo\n"],
])->throws(InvalidArgumentException::class, 'not a valid Composer package name');

it('accepts Composer package names with dots, dashes and underscores', function () {
    expect(Larsadmin::extension('acme.co/larsadmin--rating_x', FixtureExtensions::DEMO.'/dist')->name)->toBe('acme.co/larsadmin--rating_x');
});

it('refuses a missing directory, a file and a NUL byte', function (string $path) {
    Larsadmin::extension('acme/demo', $path);
})->with([
    'missing' => [FixtureExtensions::DEMO.'/missing'],
    'file' => [FixtureExtensions::DEMO.'/dist/extension.js'],
    'nul byte' => [FixtureExtensions::DEMO."/dist\0/x"],
])->throws(InvalidArgumentException::class);

it('refuses a directory that is a symbolic link', function () {
    $link = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ext-link-'.getmypid();
    @unlink($link);
    @rmdir($link);

    $linked = @symlink(realpath(FixtureExtensions::DEMO.'/dist'), $link);

    if (! $linked && PHP_OS_FAMILY === 'Windows') {
        // Without symlink rights: a junction, which is_link() does not report but must be refused too.
        exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg(realpath(FixtureExtensions::DEMO.'/dist')).' 2>NUL', $output, $code);
        $linked = $code === 0 && is_dir($link);
    }

    if (! $linked) {
        $this->markTestSkipped('Directory links are not available.');
    }

    try {
        expect(fn () => Larsadmin::extension('acme/demo', $link))->toThrow(InvalidArgumentException::class, 'symbolic link');
    } finally {
        PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
    }
});

it('versions the files by the manifest, the entry script and the stylesheet', function () {
    $extension = Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');
    $dist = FixtureExtensions::DEMO.'/dist';

    expect($extension->version())->toBe(substr(md5(md5_file($dist.'/.vite/manifest.json').'|'.md5_file($dist.'/extension.js').'|'.md5_file($dist.'/extension.css')), 0, 12))
        ->and($extension->version())->toMatch('/^[a-f0-9]{12}$/');
});

it('versions the files by the entry script and style without a manifest', function () {
    $extension = Larsadmin::extension('acme/plain', FixtureExtensions::PLAIN.'/dist');
    $script = md5_file(FixtureExtensions::PLAIN.'/dist/extension.js');

    expect($extension->version())->toBe(substr(md5('|'.$script.'|'), 0, 12))
        ->and($extension->styleFile())->toBeNull();

    $extension->style(null)->script('extension.js');

    expect($extension->version())->toBe(substr(md5('|'.$script.'|'), 0, 12));

    $extension->script('missing.js');

    expect($extension->version())->toBe(substr(md5('||'), 0, 12));
});

it('changes the version and the URLs when a rebuild changes the entry files, not when it is identical', function (string $file) {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-rebuild-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($dir.'/.vite', 0777, true);
    $build = function (string $changed = '') use ($dir, $file) {
        // Vite keeps the entry names fixed (extension.js / extension.css), so the manifest does not change.
        file_put_contents($dir.'/.vite/manifest.json', '{"resources/js/extension.js":{"file":"extension.js","css":["extension.css"]}}');
        file_put_contents($dir.'/extension.js', 'export default 1'.($file === 'extension.js' ? $changed : ''));
        file_put_contents($dir.'/extension.css', '.a{}'.($file === 'extension.css' ? $changed : ''));
    };

    try {
        $build();
        $first = Larsadmin::extension('acme/rebuild', $dir);
        $version = $first->version();
        $url = $first->url('extension.js');
        $assets = Larsadmin::assetsVersion();

        // An identical rebuild, then a new boot: same version.
        $build();
        Larsadmin::flushState();

        expect(Larsadmin::extension('acme/rebuild', $dir)->version())->toBe($version);

        // A rebuild with other contents, then a new boot: new version, URL and Inertia asset version.
        $build('/* changed */');
        Larsadmin::flushState();
        $rebuilt = Larsadmin::extension('acme/rebuild', $dir);

        expect($rebuilt->version())->not->toBe($version)
            ->and($rebuilt->url('extension.js'))->not->toBe($url)
            ->and(Larsadmin::assetsVersion())->not->toBe($assets);
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }
})->with([
    'script' => ['extension.js'],
    'style' => ['extension.css'],
]);

it('memoizes the version per process', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ext-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($dir);
    file_put_contents($dir.'/extension.js', 'export default 1');

    try {
        $extension = Larsadmin::extension('acme/temp', $dir);
        $version = $extension->version();
        file_put_contents($dir.'/extension.js', 'export default 2');

        expect($extension->version())->toBe($version);
    } finally {
        @unlink($dir.'/extension.js');
        @rmdir($dir);
    }
});

it('customizes the script, the style and the translations', function () {
    $extension = Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist')
        ->script('sub/nested.mjs')
        ->style('missing.css')
        ->translations('acme-demo')
        ->translations('acme-extra', 'messages');

    expect($extension->scriptFile())->toBe('sub/nested.mjs')
        ->and($extension->styleFile())->toBeNull()
        ->and($extension->translationGroups())->toBe(['acme-demo' => 'ui', 'acme-extra' => 'messages']);
});

it('refuses unsafe script and style paths', function (string $file) {
    expect(fn () => Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist')->script($file))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist')->style($file))->toThrow(InvalidArgumentException::class);
})->with([
    'traversal' => ['../secret.php'],
    'absolute' => ['/etc/passwd'],
    'backslash' => ['chunks\\lazy.js'],
    'drive' => ['C:/x.js'],
    'dotfile' => ['.vite/manifest.json'],
    'empty' => [''],
    'nul' => ["extension.js\0.css"],
    'encoded' => ['%2e%2e/secret.php'],
]);

it('refuses invalid translation namespaces', function (string $namespace, string $group) {
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist')->translations($namespace, $group);
})->with([
    'namespace with colons' => ['acme::x', 'ui'],
    'empty namespace' => ['', 'ui'],
    'group with slash' => ['acme', 'ui/x'],
])->throws(InvalidArgumentException::class);

it('builds versioned URLs inside the panel path', function () {
    $extension = Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');
    $version = $extension->version();

    expect($extension->url('extension.js'))->toBe("/admin/extensions/acme/demo/{$version}/extension.js")
        ->and($extension->url('chunks/lazy.js'))->toBe("/admin/extensions/acme/demo/{$version}/chunks/lazy.js")
        ->and($extension->url('/a b.js'))->toBe("/admin/extensions/acme/demo/{$version}/a%20b.js")
        ->and($extension->toClient())->toBe([
            'name' => 'acme/demo',
            'script' => "/admin/extensions/acme/demo/{$version}/extension.js",
            'style' => "/admin/extensions/acme/demo/{$version}/extension.css",
        ]);
});

it('forgets extensions, extension routes and tools on flushState', function () {
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');
    Larsadmin::routes(fn () => null);

    expect(Larsadmin::extensionRoutes())->toHaveCount(1);

    Larsadmin::flushState();

    expect(Larsadmin::registeredExtensions())->toBe([])
        ->and(Larsadmin::extensionRoutes())->toBe([])
        ->and(Larsadmin::registeredTools())->toBe([]);
});

it('keeps the extension route callbacks in order', function () {
    $first = fn () => 'first';
    $second = fn () => 'second';

    Larsadmin::routes($first);
    Larsadmin::routes($second);

    expect(Larsadmin::extensionRoutes())->toBe([$first, $second]);
});

it('exposes the API version', function () {
    expect(Larsadmin::API_VERSION)->toBe('1.6');
});

it('maps the runtime specifiers to the published panel modules', function () {
    $map = ExtensionAssets::importMap();

    expect(array_keys($map['imports']))->toBe(['vue', '@inertiajs/vue3', 'larsadmin'])
        ->and($map['imports']['vue'])->toBe(Larsadmin::asset('runtime/vue.js'))
        ->and($map['imports']['larsadmin'])->toEndWith('/vendor/larsadmin/runtime/larsadmin.js');
});

it('builds the extension directly with make()', function () {
    expect(Extension::make('acme/demo', FixtureExtensions::DEMO.'/dist'))->toBeInstanceOf(Extension::class)
        ->and(Larsadmin::registeredExtensions())->toBe([]);
});
