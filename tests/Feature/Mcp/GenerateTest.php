<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\McpServiceProvider;
use Maxcxam\Larsadmin\Mcp\LarsadminServer;
use Maxcxam\Larsadmin\Mcp\Tools\Generate;
use Maxcxam\Larsadmin\Scaffolding\Scaffolder;
use Maxcxam\Larsadmin\Tests\Fixtures\Mcp\McpResult;

beforeEach(function () {
    $this->app->register(McpServiceProvider::class);

    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-generate-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($this->root.'/app', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    $this->originalBase = $this->app->basePath();
    $this->app->setBasePath($this->root);

    $this->app['env'] = 'local';
    config(['larsadmin.mcp.enabled' => null]);
});

afterEach(function () {
    // Nothing to undo when the test was skipped before beforeEach ran (no laravel/mcp, tests/Pest.php).
    if (isset($this->originalBase)) {
        $this->app->setBasePath($this->originalBase);
    }

    if (isset($this->root)) {
        (new Filesystem)->deleteDirectory($this->root);
    }
});

/**
 * Call the tool; the structured result, or the error text as ['error' => ...].
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function mcpGenerate(array $arguments): array
{
    $response = (new Generate)->handle(new Request($arguments));

    if ($response instanceof Response) {
        expect($response->isError())->toBeTrue();

        return ['error' => (string) $response->content()];
    }

    expect($response)->toBeInstanceOf(ResponseFactory::class);

    return McpResult::fromHandle($response);
}

/**
 * Every file below a directory, relative path => contents.
 *
 * @return array<string, string>
 */
function generatedTree(string $root): array
{
    $files = [];

    foreach ((new Filesystem)->allFiles($root, true) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        if ($path !== 'composer.json') {
            $files[$path] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

it('is registered only while the MCP gate is open', function (string $environment, mixed $flag, bool $registered) {
    $this->app['env'] = $environment;
    config(['larsadmin.mcp.enabled' => $flag]);

    expect((new Generate)->shouldRegister())->toBe($registered);
})->with([
    'local' => ['local', null, true],
    'staging on' => ['staging', true, true],
    'testing auto' => ['testing', null, false],
    'production on' => ['production', true, false],
]);

it('refuses to run, even called directly, while the gate is closed', function (string $environment) {
    $this->app['env'] = $environment;
    config(['larsadmin.mcp.enabled' => $environment === 'production' ? true : false]);

    expect(mcpGenerate(['kind' => 'entity', 'name' => 'Post', 'write' => true])['error'])->toContain('MCP server')
        ->and(is_dir($this->root.'/app/Larsadmin'))->toBeFalse();
})->with(['production', 'local']);

it('describes its input and is marked destructive, because force overwrites (SEC6-02)', function () {
    $tool = (new Generate)->toArray();

    expect($tool['name'])->toBe('generate')
        ->and(array_keys($tool['inputSchema']['properties']))->toBe(['kind', 'name', 'options', 'write', 'force'])
        ->and($tool['inputSchema']['properties']['kind']['enum'])->toBe(array_keys(Scaffolder::KINDS))
        ->and($tool['inputSchema']['required'])->toBe(['kind', 'name'])
        ->and($tool['annotations']['destructiveHint'])->toBeTrue()
        ->and($tool['annotations'])->not->toHaveKey('readOnlyHint')
        ->and($tool['description'])->toContain('OVERWRITES existing files');
});

it('refuses names reserved by PHP, as the artisan generators do (SEC6-01)', function (array $arguments) {
    expect(mcpGenerate([...$arguments, 'write' => true])['error'] ?? null)->toContain('is reserved by PHP')
        ->and(generatedTree($this->root))->toBe([]);
})->with([
    'PoC entity List' => [['kind' => 'entity', 'name' => 'List']],
    'nested segment' => [['kind' => 'entity', 'name' => 'Blog/Class']],
    'filter Match' => [['kind' => 'filter', 'name' => 'Match']],
    'field' => [['kind' => 'field', 'name' => 'Enum']],
    'tool' => [['kind' => 'tool', 'name' => 'Readonly']],
    'example field' => [['kind' => 'extension', 'name' => 'acme/rating', 'options' => ['field' => 'Function']]],
]);

it('refuses the same reserved names in the artisan field, card and tool generators', function () {
    $this->artisan('larsadmin:field', ['name' => 'List'])->expectsOutputToContain('is reserved by PHP')->assertFailed();
    $this->artisan('larsadmin:entity', ['name' => 'List'])->expectsOutputToContain('is reserved by PHP');

    expect(generatedTree($this->root))->toBe([]);
});

it('keeps extensions out of dependency, build, storage and dot directories (SEC6-03)', function (array $arguments, string $error) {
    // A package-like directory, as in the PoC: any composer.json with a PSR-4 src/ would otherwise be accepted.
    foreach (['vendor/spatie/laravel-medialibrary', '.git/hooks-pkg', 'app/Pkg'] as $directory) {
        mkdir($this->root.'/'.$directory, 0777, true);
        file_put_contents($this->root.'/'.$directory.'/composer.json', json_encode(['name' => 'spatie/laravel-medialibrary', 'autoload' => ['psr-4' => ['Spatie\\MediaLibrary\\' => 'src/']]]));
    }

    $before = generatedTree($this->root);

    expect(mcpGenerate([...$arguments, 'write' => true, 'force' => true])['error'] ?? null)->toContain($error)
        ->and(generatedTree($this->root))->toBe($before);
})->with([
    'PoC field in vendor' => [['kind' => 'field', 'name' => 'Rating', 'options' => ['extension' => 'vendor/spatie/laravel-medialibrary']], 'must not be in [vendor]'],
    'vendor upper case' => [['kind' => 'card', 'name' => 'Sales', 'options' => ['extension' => 'Vendor/spatie/laravel-medialibrary']], 'must not be in [vendor]'],
    'dot folder' => [['kind' => 'tool', 'name' => 'Backups', 'options' => ['extension' => '.git/hooks-pkg']], 'must not be in [.git]'],
    'outside the extensions roots' => [['kind' => 'field', 'name' => 'Rating', 'options' => ['extension' => 'app/Pkg']], 'must be below packages/ or extensions/'],
    'PoC package over vendor' => [['kind' => 'extension', 'name' => 'laravel/framework', 'options' => ['path' => 'vendor']], 'must not be in [vendor]'],
    'node_modules' => [['kind' => 'extension', 'name' => 'acme/x', 'options' => ['path' => 'packages/node_modules']], 'must not be in [node_modules]'],
    'storage' => [['kind' => 'extension', 'name' => 'acme/x', 'options' => ['path' => 'storage']], 'must not be in [storage]'],
    'bootstrap cache' => [['kind' => 'extension', 'name' => 'acme/x', 'options' => ['path' => 'bootstrap/cache']], 'must not be in [bootstrap]'],
    'public' => [['kind' => 'extension', 'name' => 'acme/x', 'options' => ['path' => 'public']], 'must not be in [public]'],
    'idea' => [['kind' => 'extension', 'name' => 'acme/x', 'options' => ['path' => 'packages/.idea']], 'must not be in [.idea]'],
    'sandbox root' => [['kind' => 'extension', 'name' => 'acme/x', 'options' => ['path' => 'src']], 'must be below packages/ or extensions/'],
]);

it('keeps the documented artisan --path values and refuses dependency directories there too', function () {
    $this->artisan('larsadmin:extension', ['name' => 'acme/x', '--path' => 'vendor'])->expectsOutputToContain('must not be in [vendor]')->assertFailed();
    $this->artisan('larsadmin:extension', ['name' => 'acme/x', '--path' => 'sandbox/extensions'])->assertSuccessful();

    expect(is_file($this->root.'/sandbox/extensions/acme/x/composer.json'))->toBeTrue()
        ->and(is_dir($this->root.'/vendor'))->toBeFalse();
});

it('refuses path segments of dots only or with a trailing dot, leaving nothing behind (SEC6-04)', function (string $path) {
    expect(mcpGenerate(['kind' => 'extension', 'name' => 'acme/x', 'options' => ['path' => $path], 'write' => true])['error'] ?? null)
        ->toContain('must stay inside the application root');

    $this->artisan('larsadmin:extension', ['name' => 'acme/x', '--path' => $path])->assertFailed();

    expect(array_values(array_diff(scandir($this->root), ['.', '..'])))->toBe(['app', 'composer.json'])
        ->and(generatedTree($this->root))->toBe([]);
})->with(['packages/...', 'packages/x/.../y', 'packages/acme.', 'extensions/..../x']);

it('returns a plan with the contents and writes nothing by default', function () {
    $result = mcpGenerate(['kind' => 'entity', 'name' => 'Post', 'options' => ['model' => 'Post']]);

    expect($result['kind'])->toBe('entity')
        ->and($result['written'])->toBeFalse()
        ->and($result['files'])->toHaveCount(1)
        ->and($result['files'][0]['path'])->toBe('app/Larsadmin/Post.php')
        ->and($result['files'][0]['status'])->toBe('create')
        ->and($result['files'][0]['contents'])->toContain('class Post extends Entity')->toContain('\\App\\Post::class')
        ->and($result['nextSteps'])->not->toBeEmpty()
        ->and(is_dir($this->root.'/app/Larsadmin'))->toBeFalse();
});

it('plans exactly what the artisan command writes, for every kind', function (string $kind, string $command, array $arguments, string $name, array $options) {
    // Artisan first, into the application; then the plan of the same input in an empty copy of it.
    expect(Artisan::call($command, $arguments))->toBe(0);
    $expected = generatedTree($this->root);

    (new Filesystem)->deleteDirectory($this->root);
    mkdir($this->root.'/app', 0777, true);
    file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    $result = mcpGenerate(['kind' => $kind, 'name' => $name, 'options' => $options]);
    $planned = [];

    foreach ($result['files'] as $file) {
        expect($file['status'])->toBe('create');
        $planned[$file['path']] = $file['contents'];
    }

    ksort($planned);

    expect($planned)->toBe($expected)
        ->and(generatedTree($this->root))->toBe([]);
})->with([
    'entity' => ['entity', 'larsadmin:entity', ['name' => 'Blog/Post', '--model' => 'Article'], 'Blog/Post', ['model' => 'Article']],
    'action' => ['action', 'larsadmin:action', ['name' => 'Publish', '--destructive' => true], 'Publish', ['destructive' => true]],
    'filter' => ['filter', 'larsadmin:filter', ['name' => 'Status'], 'Status', []],
    'dashboard' => ['dashboard', 'larsadmin:dashboard', ['name' => 'Sales'], 'Sales', []],
    'value' => ['value', 'larsadmin:value', ['name' => 'NewUsers'], 'NewUsers', []],
    'trend' => ['trend', 'larsadmin:trend', ['name' => 'UsersPerDay'], 'UsersPerDay', []],
    'partition' => ['partition', 'larsadmin:partition', ['name' => 'UsersByRole'], 'UsersByRole', []],
    'progress' => ['progress', 'larsadmin:progress', ['name' => 'Goal'], 'Goal', []],
    'field' => ['field', 'larsadmin:field', ['name' => 'StarRating'], 'StarRating', []],
    'card' => ['card', 'larsadmin:card', ['name' => 'Sales'], 'Sales', []],
    'data-card' => ['data-card', 'larsadmin:card', ['name' => 'Stats', '--data' => true], 'Stats', []],
    'tool' => ['tool', 'larsadmin:tool', ['name' => 'Backups'], 'Backups', []],
    'extension' => ['extension', 'larsadmin:extension', ['name' => 'acme/larsadmin-rating', '--path' => 'extensions', '--field' => 'Stars', '--card' => 'TopRated'], 'acme/larsadmin-rating', ['path' => 'extensions', 'field' => 'Stars', 'card' => 'TopRated']],
]);

it('plans fields, cards and tools inside an extension package', function () {
    expect(Artisan::call('larsadmin:extension', ['name' => 'acme/larsadmin-rating']))->toBe(0);

    $result = mcpGenerate(['kind' => 'tool', 'name' => 'Reports', 'options' => ['extension' => 'packages/acme/larsadmin-rating']]);

    expect(array_column($result['files'], 'path'))->toBe([
        'packages/acme/larsadmin-rating/src/Tools/Reports.php',
        'packages/acme/larsadmin-rating/resources/js/tools/ReportsPage.vue',
    ])
        ->and($result['files'][0]['contents'])->toContain('namespace Acme\\LarsadminRating\\Tools;')
        ->and(implode("\n", $result['nextSteps']))->toContain("tools: { 'acme/reports': ReportsPage },")
        ->toContain('Larsadmin::tools([\\Acme\\LarsadminRating\\Tools\\Reports::class]);');
});

it('writes with write: true and logs the paths', function () {
    Log::spy();

    $result = mcpGenerate(['kind' => 'field', 'name' => 'Rating', 'write' => true]);

    expect($result['written'])->toBeTrue()
        ->and(array_column($result['files'], 'status'))->toBe(['create', 'create', 'create'])
        ->and($result['files'][0])->not->toHaveKey('contents')
        ->and(file_get_contents($this->root.'/app/Larsadmin/Fields/Rating.php'))->toContain("'app/rating'")
        ->and(is_file($this->root.'/resources/larsadmin/js/fields/RatingInput.vue'))->toBeTrue();

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $message === 'larsadmin.mcp.generate'
        && $context['paths'] === ['app/Larsadmin/Fields/Rating.php', 'resources/larsadmin/js/fields/RatingInput.vue', 'resources/larsadmin/js/fields/RatingDisplay.vue']);
});

it('never changes an existing file without force', function () {
    mkdir($this->root.'/app/Larsadmin/Actions', 0777, true);
    file_put_contents($this->root.'/app/Larsadmin/Actions/Publish.php', 'mine');

    $plan = mcpGenerate(['kind' => 'action', 'name' => 'Publish']);
    $refused = mcpGenerate(['kind' => 'action', 'name' => 'Publish', 'write' => true]);

    expect($plan['files'][0]['status'])->toBe('exists')
        ->and($refused['written'])->toBeFalse()
        ->and($refused['files'][0]['status'])->toBe('exists')
        ->and($refused['nextSteps'][0])->toContain('Nothing was written')
        ->and(file_get_contents($this->root.'/app/Larsadmin/Actions/Publish.php'))->toBe('mine');

    // All or nothing: one existing file keeps the others from being written too.
    mkdir($this->root.'/app/Larsadmin/Tools', 0777, true);
    file_put_contents($this->root.'/app/Larsadmin/Tools/Backups.php', 'mine');

    expect(mcpGenerate(['kind' => 'tool', 'name' => 'Backups', 'write' => true])['written'])->toBeFalse()
        ->and(is_dir($this->root.'/resources'))->toBeFalse();

    $forced = mcpGenerate(['kind' => 'action', 'name' => 'Publish', 'write' => true, 'force' => true]);

    expect($forced['written'])->toBeTrue()
        ->and($forced['files'][0]['status'])->toBe('overwrite')
        ->and(file_get_contents($this->root.'/app/Larsadmin/Actions/Publish.php'))->toContain('class Publish extends Action');
});

it('refuses invalid input', function (array $arguments, string $error) {
    expect(mcpGenerate($arguments)['error'] ?? null)->toContain($error)
        ->and(generatedTree($this->root))->toBe([]);
})->with([
    'unknown kind' => [['kind' => 'lens', 'name' => 'Post'], 'The kind must be one of'],
    'missing name' => [['kind' => 'entity'], 'The name must be'],
    'options list' => [['kind' => 'entity', 'name' => 'Post', 'options' => ['Post']], 'The options must be an object'],
    'write string' => [['kind' => 'entity', 'name' => 'Post', 'write' => 'yes'], 'must be booleans'],
    'force without write' => [['kind' => 'entity', 'name' => 'Post', 'force' => true], 'only together with write'],
    'unknown option' => [['kind' => 'entity', 'name' => 'Post', 'options' => ['force' => true]], 'Unknown option [force] for [entity]'],
    'option of another kind' => [['kind' => 'filter', 'name' => 'Status', 'options' => ['model' => 'Post']], 'it takes no options'],
    'mistyped option' => [['kind' => 'action', 'name' => 'Go', 'options' => ['destructive' => 'yes']], 'must be a bool'],
    'lower case name' => [['kind' => 'entity', 'name' => 'post'], 'StudlyCase'],
    'traversal name' => [['kind' => 'entity', 'name' => '../Post'], 'StudlyCase'],
    'backslash name' => [['kind' => 'entity', 'name' => 'App\\Models\\Post'], 'StudlyCase'],
    'trailing newline' => [['kind' => 'filter', 'name' => "Status\n"], 'StudlyCase'],
    'outside app/Larsadmin' => [['kind' => 'entity', 'name' => 'App/Models/Post'], 'outside app/Larsadmin'],
    'model injection' => [['kind' => 'entity', 'name' => 'Post', 'options' => ['model' => 'Post::class; exec("x"); //']], 'must be a class name'],
    'nested field name' => [['kind' => 'field', 'name' => 'Admin/Rating'], 'must start with a letter'],
    'package name' => [['kind' => 'extension', 'name' => 'Acme/Rating'], 'not a valid Composer package name'],
    'package path outside' => [['kind' => 'extension', 'name' => 'acme/rating', 'options' => ['path' => '../outside']], 'must stay inside the application root'],
    'absolute path' => [['kind' => 'extension', 'name' => 'acme/rating', 'options' => ['path' => 'C:/outside']], 'relative to the application root'],
    'example quote' => [['kind' => 'extension', 'name' => 'acme/rating', 'options' => ['field' => "Bad'Name"]], 'must start with a letter'],
    'missing extension' => [['kind' => 'card', 'name' => 'Sales', 'options' => ['extension' => 'packages/missing']], 'No extension package'],
    'extension outside' => [['kind' => 'tool', 'name' => 'Backups', 'options' => ['extension' => '../elsewhere']], 'must stay inside the application root'],
]);

it('refuses a namespace from composer.json that is not a PHP namespace', function () {
    mkdir($this->root.'/packages/evil', 0777, true);
    file_put_contents($this->root.'/packages/evil/composer.json', json_encode(['name' => 'acme/evil', 'autoload' => ['psr-4' => ["Acme\\Evil;exec('x');//\\" => 'src/']]]));

    expect(mcpGenerate(['kind' => 'field', 'name' => 'Rating', 'options' => ['extension' => 'packages/evil'], 'write' => true])['error'])
        ->toContain('is not a valid PHP namespace')
        ->and(is_dir($this->root.'/packages/evil/src'))->toBeFalse();
});

it('refuses to write through a link below the application root', function () {
    $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-generate-out-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($outside);
    mkdir($this->root.'/app/Larsadmin', 0777, true);
    $link = $this->root.'/app/Larsadmin/Actions';

    $linked = @symlink($outside, $link);

    if (! $linked && PHP_OS_FAMILY === 'Windows') {
        exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($outside).' 2>NUL', $output, $code);
        $linked = $code === 0;
    }

    if (! $linked) {
        $this->markTestSkipped('Links are not available.');
    }

    try {
        expect(Scaffolder::isLink($link))->toBeTrue()
            ->and(mcpGenerate(['kind' => 'action', 'name' => 'Publish', 'write' => true])['error'] ?? null)->toContain('outside the application root')
            ->and(glob($outside.'/*'))->toBe([]);

        // The same rule for the artisan command since 1.6.
        $this->artisan('larsadmin:action', ['name' => 'Publish'])->assertFailed();

        expect(glob($outside.'/*'))->toBe([]);
    } finally {
        PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
        (new Filesystem)->deleteDirectory($outside);
    }
});

it('runs through the larsadmin server', function () {
    LarsadminServer::tool(Generate::class, ['kind' => 'dashboard', 'name' => 'Sales'])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('kind', 'dashboard')
                ->where('written', false)
                ->where('files.0.path', 'app/Larsadmin/Dashboards/Sales.php')
                ->where('files.0.status', 'create')
                ->etc();
        });

    expect(is_dir($this->root.'/app/Larsadmin'))->toBeFalse();
});
