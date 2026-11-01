<?php

use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Console\ExtensionScaffold;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

uses(InteractsWithLarsadmin::class);

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-scaffold-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($this->root.'/app', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    $this->originalBase = $this->app->basePath();
    $this->app->setBasePath($this->root);
});

afterEach(function () {
    $this->app->setBasePath($this->originalBase);
    (new Filesystem)->deleteDirectory($this->root);
});

/**
 * Assert that a generated PHP file has no syntax errors.
 */
function assertLints(string $file): void
{
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $code);

    expect($code)->toBe(0, implode("\n", $output));
}

function scaffolded(string $root): array
{
    $files = [];

    foreach ((new Filesystem)->allFiles($root, true) as $file) {
        $files[] = str_replace('\\', '/', $file->getRelativePathname());
    }

    sort($files);

    return $files;
}

it('creates an extension package skeleton', function () {
    $this->artisan('larsadmin:extension', ['name' => 'acme/larsadmin-rating'])
        ->expectsOutputToContain('composer require acme/larsadmin-rating:@dev')
        ->assertSuccessful();

    $dir = $this->root.'/packages/acme/larsadmin-rating';

    expect(scaffolded($dir))->toBe([
        '.gitignore',
        'README.md',
        'composer.json',
        'lang/en/ui.php',
        'lang/uk/ui.php',
        'package.json',
        'resources/css/extension.css',
        'resources/js/cards/ExampleCard.vue',
        'resources/js/extension.js',
        'resources/js/fields/ExampleFieldDisplay.vue',
        'resources/js/fields/ExampleFieldInput.test.js',
        'resources/js/fields/ExampleFieldInput.vue',
        'src/Cards/ExampleCard.php',
        'src/Fields/ExampleField.php',
        'src/RatingServiceProvider.php',
        'tests/CardTest.php',
        'tests/FieldTest.php',
        'tests/Fixtures/TestDashboard.php',
        'tests/Fixtures/User.php',
        'tests/HttpTest.php',
        'tests/Pest.php',
        'tests/TestCase.php',
        'vite.config.js',
    ]);

    foreach (scaffolded($dir) as $file) {
        if (str_ends_with($file, '.php')) {
            assertLints($dir.'/'.$file);
        }
    }

    $composer = json_decode(file_get_contents($dir.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $package = json_decode(file_get_contents($dir.'/package.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($composer['name'])->toBe('acme/larsadmin-rating')
        ->and($composer['require']['maxcxam/larsadmin'])->toBe('^1.6')
        ->and($composer['autoload']['psr-4'])->toBe(['Acme\\LarsadminRating\\' => 'src/'])
        ->and($composer['autoload-dev']['psr-4'])->toBe(['Acme\\LarsadminRating\\Tests\\' => 'tests/'])
        ->and($composer['extra']['laravel']['providers'])->toBe(['Acme\\LarsadminRating\\RatingServiceProvider'])
        ->and($package['name'])->toBe('@acme/larsadmin-rating')
        ->and($package['devDependencies'])->toHaveKeys(['vite', '@vitejs/plugin-vue', 'vue', '@inertiajs/vue3', 'vitest', 'jsdom', '@vue/test-utils', 'tailwindcss', '@tailwindcss/vite']);

    $extension = file_get_contents($dir.'/resources/js/extension.js');
    $provider = file_get_contents($dir.'/src/RatingServiceProvider.php');
    $css = file_get_contents($dir.'/resources/css/extension.css');
    $testCase = file_get_contents($dir.'/tests/TestCase.php');

    // The example components are not named after the package, and their keys use the package's vendor.
    expect($extension)->toContain("import { defineExtension } from 'larsadmin'")
        ->toContain("name: 'acme/larsadmin-rating'")
        ->toContain("requires: '^1.6'")
        ->toContain("'acme/example-field': { form: ExampleFieldInput, display: ExampleFieldDisplay }")
        ->toContain("'acme/example-card': ExampleCard")
        ->not->toContain('larsadmin/')
        ->and(file_get_contents($dir.'/vite.config.js'))->toContain("'../../../vendor/maxcxam/larsadmin/kit'")->toContain('larsadmin()')
        ->and($provider)->toContain("Larsadmin::extension('acme/larsadmin-rating', __DIR__.'/../dist')")
        ->toContain("->translations('acme-rating')")
        ->toContain('// Larsadmin::tools([Tools\\Backups::class]);')
        ->toContain('larsadmin:tool Backups --extension=packages/acme/larsadmin-rating')
        ->and($css)->toContain('@import "tailwindcss/utilities" layer(utilities.extensions) source(none);')
        ->toContain('@import "larsadmin-kit/theme.css";')
        ->toContain('@source "../js";')
        ->not->toContain('@theme')
        ->and($testCase)->toContain("'database' => ':memory:'")
        ->toContain('#[WithMigration]')->not->toContain('loadLaravelMigrations')
        ->toContain('Larsadmin::auth(fn () => true)')
        ->toContain("Gate::define('viewLarsadmin'")
        ->and(file_get_contents($dir.'/tests/HttpTest.php'))->toContain('/admin/api/dashboards/test-dashboard/metrics/example-card?limit=2')
        ->toContain("extensionAssetResponse('acme/larsadmin-rating', 'extension.js')")
        ->and(file_get_contents($dir.'/src/Fields/ExampleField.php'))->toContain("public string \$component = 'acme/example-field';")
        ->and(file_get_contents($dir.'/src/Cards/ExampleCard.php'))->toContain('extends DataCard')->toContain('$this->entityQuery($request')
        ->and(file_get_contents($dir.'/resources/js/fields/ExampleFieldInput.vue'))->toContain(':id="field.attribute"')->toContain('errorsByKey: { type: Object')
        ->and(file_get_contents($dir.'/resources/js/cards/ExampleCard.vue'))->toContain("import { CardShell, useCardData } from 'larsadmin'");

    // No stub placeholder is left anywhere (Vue interpolations such as {{ item }} are expected).
    $placeholders = '/\{\{ (name|namespace|namespaceJson|provider|apiVersion|npmName|hostKit|langNamespace|field|attribute|fieldKey|card|cardVue|cardKey|cardUriKey|repository|directory|class|component) \}\}/';

    foreach (scaffolded($dir) as $file) {
        expect(file_get_contents($dir.'/'.$file))->not->toMatch($placeholders);
    }
});

it('names the example field and card with --field and --card', function () {
    $this->artisan('larsadmin:extension', ['name' => 'acme/stars', '--field' => 'star-rating', '--card' => 'TopRated'])->assertSuccessful();

    $dir = $this->root.'/packages/acme/stars';

    expect(file_get_contents($dir.'/resources/js/extension.js'))
        ->toContain("'acme/star-rating': { form: StarRatingInput, display: StarRatingDisplay }")
        ->toContain("'acme/top-rated': TopRatedCard")
        ->and(is_file($dir.'/src/Fields/StarRating.php'))->toBeTrue()
        ->and(is_file($dir.'/resources/js/cards/TopRatedCard.vue'))->toBeTrue()
        ->and(file_get_contents($dir.'/tests/FieldTest.php'))->toContain("StarRating::make('StarRating', 'star_rating')");
});

it('refuses invalid or equal example names', function (array $options) {
    $this->artisan('larsadmin:extension', ['name' => 'acme/stars', ...$options])->assertFailed();

    expect(is_dir($this->root.'/packages'))->toBeFalse();
})->with([
    'field quote' => [['--field' => "Bad'Name"]],
    'card digit' => [['--card' => '1Card']],
    'same names' => [['--field' => 'Same', '--card' => 'Same']],
]);

it('warns how to require the package when the panel is a dev version', function () {
    $version = InstalledVersions::getPrettyVersion('maxcxam/larsadmin');
    $command = $this->artisan('larsadmin:extension', ['name' => 'acme/stars'])->expectsOutputToContain('composer require acme/stars:@dev');

    if (str_contains((string) $version, 'dev')) {
        $command->expectsOutputToContain('Larsadmin is installed as');
    } else {
        $command->doesntExpectOutputToContain('Larsadmin is installed as');
    }

    $command->assertSuccessful();
});

it('runs the generated PHP side through the public API, as the skeleton tests do', function () {
    $this->artisan('larsadmin:extension', ['name' => 'acme/larsadmin-smoke'])->assertSuccessful();

    $dir = $this->root.'/packages/acme/larsadmin-smoke';

    foreach (['src/Fields/ExampleField.php', 'src/Cards/ExampleCard.php', 'src/SmokeServiceProvider.php', 'tests/Fixtures/TestDashboard.php'] as $file) {
        require_once $dir.'/'.$file;
    }

    $field = 'Acme\\LarsadminSmoke\\Fields\\ExampleField';
    $card = 'Acme\\LarsadminSmoke\\Cards\\ExampleCard';
    $provider = 'Acme\\LarsadminSmoke\\SmokeServiceProvider';
    $dashboard = 'Acme\\LarsadminSmoke\\Tests\\Fixtures\\TestDashboard';

    // FieldTest.php and CardTest.php.
    $record = new class extends Model
    {
        protected $guarded = [];
    };
    $record->forceFill(['example_field' => 'value']);

    expect($this->serializeField($field::make('ExampleField', 'example_field'), $record))
        ->toMatchArray(['component' => 'acme/example-field', 'value' => 'value'])
        ->and($field::make('ExampleField', 'example_field')->rulesFor('update'))->toBe(['example_field' => ['string', 'max:255']])
        ->and($this->cardData($card::make(), ['limit' => 3]))->toBe(['items' => [], 'limit' => 3])
        ->and(fn () => $this->cardData($card::make(), ['limit' => 'many']))->toThrow(ValidationException::class);

    // HttpTest.php: the card endpoint behind the panel gate.
    Larsadmin::auth(fn () => true);
    Larsadmin::dashboards([$dashboard]);

    $this->getJson('/admin/api/dashboards/test-dashboard/metrics/example-card?limit=2')->assertUnauthorized();

    $user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($user)->getJson('/admin/api/dashboards/test-dashboard/metrics/example-card?limit=2')
        ->assertOk()
        ->assertJsonPath('data.limit', 2);

    // The provider registers the extension once dist/ exists (after npm run build), then serves it.
    (new $provider($this->app))->boot();
    expect(Larsadmin::extensionFor('acme/larsadmin-smoke'))->toBeNull();

    mkdir($dir.'/dist');
    file_put_contents($dir.'/dist/extension.js', 'export default {}');
    (new $provider($this->app))->boot();

    $this->assertExtensionRegistered('acme/larsadmin-smoke');

    expect(Larsadmin::extensionFor('acme/larsadmin-smoke')->translationGroups())->toBe(['acme-smoke' => 'ui'])
        ->and(trans('acme-smoke::ui.empty', [], 'en'))->toBe('Nothing here yet');

    $this->extensionAssetResponse('acme/larsadmin-smoke', 'extension.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
});

it('creates the package in another directory and keeps the vendor key safe', function () {
    $this->artisan('larsadmin:extension', ['name' => 'acme.co/stars', '--path' => 'extensions/local'])->assertSuccessful();

    $dir = $this->root.'/extensions/local/acme.co/stars';

    expect(file_get_contents($dir.'/resources/js/extension.js'))->toContain("'acme-co/example-field': { form: ExampleFieldInput")
        ->and(file_get_contents($dir.'/vite.config.js'))->toContain("'../../../../vendor/maxcxam/larsadmin/kit'")
        ->and(json_decode(file_get_contents($dir.'/composer.json'), true)['autoload']['psr-4'])->toBe(['AcmeCo\\Stars\\' => 'src/']);

    assertLints($dir.'/src/StarsServiceProvider.php');
});

it('creates a tool in an extension package and in the application', function () {
    $this->artisan('larsadmin:extension', ['name' => 'acme/larsadmin-rating'])->assertSuccessful();

    $this->artisan('larsadmin:tool', ['name' => 'Backups', '--extension' => 'packages/acme/larsadmin-rating'])
        ->expectsOutputToContain("tools: { 'acme/backups': BackupsPage },")
        ->expectsOutputToContain('Larsadmin::tools([\\Acme\\LarsadminRating\\Tools\\Backups::class]);')
        ->assertSuccessful();

    $dir = $this->root.'/packages/acme/larsadmin-rating';
    $php = file_get_contents($dir.'/src/Tools/Backups.php');

    expect($php)->toContain('namespace Acme\\LarsadminRating\\Tools;')
        ->toContain('class Backups extends Tool')
        ->toContain("public string \$component = 'acme/backups';")
        ->and(file_get_contents($dir.'/resources/js/tools/BackupsPage.vue'))->toContain('tool: { type: Object, required: true }');

    assertLints($dir.'/src/Tools/Backups.php');

    $this->artisan('larsadmin:tool', ['name' => 'SystemPage'])
        ->expectsOutputToContain("tools: { 'app/system-page': SystemPage },")
        ->expectsOutputToContain('your LarsadminServiceProvider')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/app/Larsadmin/Tools/SystemPage.php'))->toContain('namespace App\\Larsadmin\\Tools;')
        ->and(is_file($this->root.'/resources/larsadmin/js/tools/SystemPage.vue'))->toBeTrue();

    $this->artisan('larsadmin:tool', ['name' => 'SystemPage'])->assertFailed();
});

it('registers the generated tool class like any tool', function () {
    $this->artisan('larsadmin:tool', ['name' => 'Reports'])->assertSuccessful();

    require_once $this->root.'/app/Larsadmin/Tools/Reports.php';
    $tool = 'App\\Larsadmin\\Tools\\Reports';

    Larsadmin::tools([$tool]);

    expect(Larsadmin::toolForKey('reports')?->component)->toBe('app/reports')
        ->and(Larsadmin::toolForKey('reports')->props(request()))->toBe(['message' => 'Reports']);
});

it('refuses invalid package names and paths outside the application', function (array $arguments) {
    $this->artisan('larsadmin:extension', $arguments)->assertFailed();

    expect(is_dir($this->root.'/packages'))->toBeFalse();
})->with([
    'no vendor' => [['name' => 'rating']],
    'upper case' => [['name' => 'Acme/Rating']],
    'traversal name' => [['name' => '../evil']],
    'parent path' => [['name' => 'acme/rating', '--path' => '../outside']],
    'nested parent path' => [['name' => 'acme/rating', '--path' => 'packages/../../outside']],
    'absolute path' => [['name' => 'acme/rating', '--path' => '/tmp/outside']],
    'drive path' => [['name' => 'acme/rating', '--path' => 'C:/outside']],
    'backslash path' => [['name' => 'acme/rating', '--path' => 'packages\\x']],
    'empty path' => [['name' => 'acme/rating', '--path' => '.']],
]);

it('does not overwrite an existing package without --force', function () {
    $this->artisan('larsadmin:extension', ['name' => 'acme/rating'])->assertSuccessful();

    $readme = $this->root.'/packages/acme/rating/README.md';
    file_put_contents($readme, 'mine');
    unlink($this->root.'/packages/acme/rating/vite.config.js');

    $this->artisan('larsadmin:extension', ['name' => 'acme/rating'])
        ->expectsOutputToContain('Use --force to overwrite')
        ->assertFailed();

    // Nothing was written: not even the missing file.
    expect(file_get_contents($readme))->toBe('mine')
        ->and(is_file($this->root.'/packages/acme/rating/vite.config.js'))->toBeFalse();

    $this->artisan('larsadmin:extension', ['name' => 'acme/rating', '--force' => true])->assertSuccessful();

    expect(file_get_contents($readme))->not->toBe('mine')
        ->and(is_file($this->root.'/packages/acme/rating/vite.config.js'))->toBeTrue();
});

it('creates a field inside an extension package', function () {
    $this->artisan('larsadmin:extension', ['name' => 'acme/larsadmin-rating'])->assertSuccessful();

    $this->artisan('larsadmin:field', ['name' => 'Mood', '--extension' => 'packages/acme/larsadmin-rating'])
        ->expectsOutputToContain("fields: { 'acme/mood': { form: MoodInput, display: MoodDisplay } },")
        ->assertSuccessful();

    $dir = $this->root.'/packages/acme/larsadmin-rating';
    $php = file_get_contents($dir.'/src/Fields/Mood.php');

    expect($php)->toContain('namespace Acme\\LarsadminRating\\Fields;')
        ->toContain('class Mood extends Field')
        ->toContain("public string \$component = 'acme/mood';")
        ->and(is_file($dir.'/resources/js/fields/MoodInput.vue'))->toBeTrue()
        ->and(is_file($dir.'/resources/js/fields/MoodDisplay.vue'))->toBeTrue();

    assertLints($dir.'/src/Fields/Mood.php');
});

it('creates a field in the application without an extension', function () {
    $this->artisan('larsadmin:field', ['name' => 'star-rating'])
        ->expectsOutputToContain("'app/star-rating'")
        ->assertSuccessful();

    $php = $this->root.'/app/Larsadmin/Fields/StarRating.php';

    expect(file_get_contents($php))->toContain('namespace App\\Larsadmin\\Fields;')
        ->toContain("'app/star-rating'")
        ->and(is_file($this->root.'/resources/larsadmin/js/fields/StarRatingInput.vue'))->toBeTrue()
        ->and(is_file($this->root.'/resources/larsadmin/js/fields/StarRatingDisplay.vue'))->toBeTrue();

    assertLints($php);

    $this->artisan('larsadmin:field', ['name' => 'StarRating'])->assertFailed();
    $this->artisan('larsadmin:field', ['name' => 'StarRating', '--force' => true])->assertSuccessful();
});

it('creates static and data cards', function (array $options, string $base, string $vue, string $php) {
    $this->artisan('larsadmin:card', ['name' => 'Sales', ...$options])
        ->expectsOutputToContain("cards: { 'app/sales': {$vue} },")
        ->assertSuccessful();

    $class = file_get_contents($this->root.'/app/Larsadmin/Cards/Sales.php');

    expect($class)->toContain("extends {$base}")
        ->and(file_get_contents($this->root."/resources/larsadmin/js/cards/{$vue}.vue"))->toContain($php);

    assertLints($this->root.'/app/Larsadmin/Cards/Sales.php');
})->with([
    'static' => [[], 'Card', 'SalesCard', "import { CardShell } from 'larsadmin'"],
    'data' => [['--data' => true], 'DataCard', 'SalesCard', 'useCardData(() => props.card, params)'],
]);

it('keeps a card name that already ends with Card', function () {
    $this->artisan('larsadmin:card', ['name' => 'WelcomeCard'])->assertSuccessful();

    expect(is_file($this->root.'/resources/larsadmin/js/cards/WelcomeCard.vue'))->toBeTrue();
});

it('refuses invalid names and extension directories', function (string $command, array $arguments) {
    mkdir($this->root.'/packages/not-a-package', 0777, true);
    mkdir($this->root.'/packages/no-src', 0777, true);
    file_put_contents($this->root.'/packages/no-src/composer.json', json_encode(['name' => 'acme/no-src', 'autoload' => ['psr-4' => ['Acme\\' => 'lib/']]]));

    $this->artisan($command, $arguments)->assertFailed();

    expect(is_dir($this->root.'/app/Larsadmin'))->toBeFalse();
})->with([
    'field digit name' => ['larsadmin:field', ['name' => '1Rating']],
    'field path name' => ['larsadmin:field', ['name' => '../Rating']],
    'card namespace name' => ['larsadmin:card', ['name' => 'App\\Rating']],
    'missing extension' => ['larsadmin:field', ['name' => 'Rating', '--extension' => 'packages/missing']],
    'not a package' => ['larsadmin:card', ['name' => 'Sales', '--extension' => 'packages/not-a-package']],
    'no src namespace' => ['larsadmin:card', ['name' => 'Sales', '--extension' => 'packages/no-src']],
    'extension outside' => ['larsadmin:field', ['name' => 'Rating', '--extension' => '../elsewhere']],
    'absolute extension' => ['larsadmin:field', ['name' => 'Rating', '--extension' => '/var/www']],
]);

it('derives safe key segments and kit paths', function () {
    expect(ExtensionScaffold::keySegment('Acme.Co__x'))->toBe('acme-co-x')
        ->and(ExtensionScaffold::keySegment('...'))->toBe('x')
        ->and(ExtensionScaffold::hostKitPath('packages/acme/rating'))->toBe('../../../vendor/maxcxam/larsadmin/kit')
        ->and(ExtensionScaffold::relativeDirectory('./packages//acme/', '--path'))->toBe('packages/acme');
});

it('refuses to write through a dangling link below the application root', function () {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-dangling-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($this->root.'/packages/acme', 0777, true);
    $link = $this->root.'/packages/acme/rating';

    // A link to a directory that does not exist yet (dangling).
    $linked = @symlink($outside, $link);

    if (! $linked && PHP_OS_FAMILY === 'Windows') {
        exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($outside).' 2>NUL', $output, $code);
        $linked = $code === 0;
    }

    if (! $linked) {
        $this->markTestSkipped('Links are not available.');
    }

    try {
        expect(ExtensionScaffold::insideBase($link.'/composer.json'))->toBeFalse();

        $this->artisan('larsadmin:extension', ['name' => 'acme/rating'])->assertFailed();

        expect(file_exists($outside))->toBeFalse();
    } finally {
        PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
        (new Filesystem)->deleteDirectory($outside);
    }
});
