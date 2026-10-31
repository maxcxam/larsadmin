<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Extensions\ExtensionAssets;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminServiceProvider;
use Maxcxam\Larsadmin\Metrics\Metric;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\ScopedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\FixtureExtensions;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\Security\LeakyCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\Security\RatedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\Security\SecurityFixtures;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\Security\SpyTool;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\CardsDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\MetricPost;
use Maxcxam\Larsadmin\Tests\Fixtures\NoUpdatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\UpdateOnlyPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Maxcxam\Larsadmin\Tools\Tool;

/*
 * T1 (1.5): the public extension API seen from an attacker, end to end through HTTP and the console
 * (design 1.5 §4, §5.6, §6.2, §7, §8, §11; ADR-030..ADR-034; NFR-025..NFR-028). Every point of §8 is a
 * named test. Bob is the signed-in panel user, Eve a user the gate refuses. Fail closed everywhere.
 * The functional behaviour lives in ExtensionAssetsTest, ImportMapTest, ExtensionSharedPropsTest,
 * ExtensionRoutesTest, FindRecordTest, DataCardTest, ToolsTest and ScaffoldCommandsTest.
 */

const EXTSEC_CARD_URL = '/admin/api/dashboards/cards-dashboard/metrics/leaky-card';

beforeEach(function () {
    config([
        'app.debug' => false,
        'larsadmin.locale' => 'en',
        'larsadmin.timezone' => 'UTC',
        'cache.default' => 'array',
    ]);
    app()->setLocale('en');
    LeakyCard::reset();
    SpyTool::reset();
    PostPolicy::$allowDelete = false;
    MetricPost::$cardList = [];

    Larsadmin::auth(fn ($request) => $request->user()?->email !== 'eve@example.com');

    $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);
    $this->eve = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secret']);
    $this->actingAs($this->bob);

    $this->extension = Larsadmin::extension('acme/security', SecurityFixtures::DIST);
    $this->base = '/admin/extensions/acme/security/'.$this->extension->version().'/';
});

afterEach(function () {
    LeakyCard::reset();
    SpyTool::reset();
    PostPolicy::$allowDelete = false;
    MetricPost::$cardList = [];
    URL::useAssetOrigin(null);
});

/**
 * Link $link to the directory $target: a symlink, or a junction on Windows without symlink rights.
 */
function extsecLink(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        return true;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($target).' 2>NUL', $output, $code);

        return $code === 0 && is_dir($link);
    }

    return false;
}

function extsecUnlink(string $link): void
{
    PHP_OS_FAMILY === 'Windows' ? @rmdir($link) : @unlink($link);
}

/**
 * Assert that a response body reveals no file system path and no content of a file it must not serve.
 */
function extsecAssertNoLeak(string $content): void
{
    $root = realpath(SecurityFixtures::ROOT);

    expect($content)->not->toContain($root)
        ->and($content)->not->toContain(str_replace('\\', '/', $root))
        ->and($content)->not->toContain('Fixtures')
        ->and($content)->not->toContain('outside the dist directory')
        ->and($content)->not->toContain('hunter2')
        ->and($content)->not->toContain('<?php');
}

/**
 * The HTML of $url; with $legacy rendered by the verbatim 1.4 root view. Line endings are normalized.
 */
function extsecHtml(string $url, bool $legacy = false): string
{
    if ($legacy) {
        app('view')->getFinder()->replaceNamespace('larsadmin', [FixtureExtensions::VIEWS_1_4]);
        app('view')->flushFinderCache();
    }

    try {
        return str_replace("\r\n", "\n", test()->get($url)->assertOk()->getContent());
    } finally {
        app('view')->getFinder()->replaceNamespace('larsadmin', [realpath(__DIR__.'/../../resources/views')]);
        app('view')->flushFinderCache();
    }
}

/**
 * The decoded import map of a page and its raw JSON.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function extsecImportMap(string $html): array
{
    expect(preg_match_all('#<script type="importmap"[^>]*>(.*?)</script>#s', $html, $matches))->toBe(1);

    return [json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR), $matches[1][0]];
}

function extsecDashboard(array $cards): CardsDashboard
{
    $dashboard = CardsDashboard::make();
    $dashboard->cardList = $cards;
    Larsadmin::dashboards([$dashboard]);

    return $dashboard;
}

/*
 * Asset serving: path traversal (§6.2 step 2, NFR-025).
 */

it('never serves a file outside the registered directory', function (string $file, int $status = 404) {
    expect(is_file(SecurityFixtures::ROOT.'/outside/secret.js'))->toBeTrue();

    extsecAssertNoLeak((string) $this->get($this->base.$file)->assertStatus($status)->getContent());
    extsecAssertNoLeak((string) $this->getJson($this->base.$file)->assertStatus($status)->getContent());
})->with([
    'parent' => ['../outside/secret.js'],
    'nested parent' => ['chunks/../../outside/secret.js'],
    'dot then parent' => ['sub/./../../outside/secret.js'],
    'encoded dots' => ['%2e%2e/outside/secret.js'],
    'upper case encoded dots' => ['%2E%2E/outside/secret.js'],
    'half encoded dots' => ['.%2e/outside/secret.js'],
    'other half encoded dots' => ['%2e./outside/secret.js'],
    'encoded slash' => ['..%2foutside%2fsecret.js'],
    'double encoded dots' => ['%252e%252e/outside/secret.js'],
    'double encoded path' => ['%252e%252e%252foutside%252fsecret.js'],
    'overlong utf-8 dots (invalid UTF-8 is a 400)' => ['%c0%ae%c0%ae/outside/secret.js', 400],
    'unicode full stops' => ['%e2%80%a4%e2%80%a4/outside/secret.js'],
    'backslash' => ['..%5coutside%5csecret.js'],
    'double encoded backslash' => ['..%255coutside%255csecret.js'],
    'mixed separators' => ['chunks%5c..%5c..%2foutside/secret.js'],
    'encoded parents inside a chunk path' => ['chunks/%2e%2e/%2e%2e/outside/secret.js'],
    'absolute unix' => ['%2fetc%2fpasswd'],
    'absolute fixture path' => [rawurlencode(str_replace('\\', '/', SecurityFixtures::ROOT).'/outside/secret.js')],
    'unc path' => ['%5c%5clocalhost%5cc$%5cwindows%5cwin.ini'],
    'drive letter' => ['C:%5cWindows%5cwin.ini'],
    'file scheme' => ['file:%2f%2f%2fetc%2fpasswd'],
    'nul byte' => ['extension.js%00'],
    'nul byte before a type' => ['server.php%00.js'],
    'nul byte in a parent' => ['..%00/outside/secret.js'],
    'tab' => ['extension.js%09'],
    'trailing dot php' => ['server.php.'],
    'trailing space php' => ['server.php%20'],
    'ntfs stream' => ['server.php::$DATA'],
    'dos short name' => ['SERVER~1.PHP'],
]);

it('answers 404 without an error for overlong paths', function (string $file) {
    $this->get($this->base.$file)->assertNotFound();
})->with([
    'many segments' => [str_repeat('chunks/', 150).'part.js'],
    'long segment' => [str_repeat('a', 2000).'.js'],
    'many parents' => [str_repeat('../', 400).'outside/secret.js'],
]);

it('refuses a symlinked file or directory that points outside the registered directory', function () {
    $outside = realpath(SecurityFixtures::ROOT.'/outside');
    $dir = SecurityFixtures::DIST.'/escape-dir-'.getmypid();
    $file = SecurityFixtures::DIST.'/escape-file-'.getmypid().'.js';

    if (! extsecLink($outside, $dir)) {
        $this->markTestSkipped('Directory links are not available.');
    }

    $fileLinked = @symlink($outside.DIRECTORY_SEPARATOR.'secret.js', $file);

    try {
        expect(is_file($dir.'/secret.js'))->toBeTrue();

        extsecAssertNoLeak((string) $this->get($this->base.basename($dir).'/secret.js')->assertNotFound()->getContent());

        if ($fileLinked) {
            extsecAssertNoLeak((string) $this->get($this->base.basename($file))->assertNotFound()->getContent());
        }
    } finally {
        extsecUnlink($dir);
        @unlink($file);
    }
});

/*
 * Asset serving: what may be served (§6.2 step 3–4).
 */

it('serves only allow-listed types and never HTML, PHP, env files, dotfiles or source maps', function (string $file, ?string $type) {
    expect(is_file(SecurityFixtures::DIST.'/'.$file))->toBeTrue("fixture {$file} is missing");

    $response = $this->get($this->base.$file);

    if ($type === null) {
        $response->assertNotFound();
        extsecAssertNoLeak((string) $response->getContent());

        return;
    }

    $response->assertOk();

    expect($response->headers->get('Content-Type'))->toBe($type)
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBeNull();
})->with([
    'entry module' => ['extension.js', 'text/javascript; charset=utf-8'],
    'stylesheet' => ['extension.css', 'text/css; charset=utf-8'],
    'chunk' => ['chunks/part.js', 'text/javascript; charset=utf-8'],
    'upper case type' => ['UPPER.JS', 'text/javascript; charset=utf-8'],
    'html' => ['index.html', null],
    'htm' => ['page.htm', null],
    'xml' => ['feed.xml', null],
    'text' => ['notes.txt', null],
    'php' => ['server.php', null],
    'phtml' => ['server.phtml', null],
    'phar' => ['archive.phar', null],
    'env type' => ['app.env', null],
    'dotenv' => ['.env', null],
    'htaccess' => ['.htaccess', null],
    'hidden directory' => ['sub/.secret/hidden.js', null],
    'source map' => ['bundle.js.map', null],
    'no extension' => ['noext', null],
]);

it('serves source maps only when the flag is truly on', function (mixed $flag, int $status) {
    config(['larsadmin.extensions.source_maps' => $flag]);

    $this->get($this->base.'bundle.js.map')->assertStatus($status);

    // The flag opens source maps only: nothing else becomes servable.
    $this->get($this->base.'.env')->assertNotFound();
    $this->get($this->base.'server.php')->assertNotFound();
    $this->get($this->base.'index.html')->assertNotFound();
})->with([
    'off' => [false, 404],
    'null' => [null, 404],
    'string false' => ['false', 404],
    'string zero' => ['0', 404],
    'empty string' => ['', 404],
    'garbage' => ['yes please', 404],
    'on' => [true, 200],
    'string true' => ['true', 200],
    'string one' => ['1', 200],
]);

it('caches only the current version as immutable', function () {
    $current = $this->get($this->base.'extension.js')->assertOk();
    $stale = $this->get('/admin/extensions/acme/security/000000000000/extension.js')->assertOk();

    // A stale hash (a tab open during a deploy) is served but revalidated, never cached for a year (§6.2 step 4).
    expect($current->headers->get('Cache-Control'))->toBe('immutable, max-age=31536000, private')
        ->and($stale->headers->get('Cache-Control'))->toBe('no-cache, private')
        ->and($stale->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('answers 404 for unknown extensions and malformed versions without leaking paths', function (string $url) {
    extsecAssertNoLeak((string) $this->get($url)->assertNotFound()->getContent());
    extsecAssertNoLeak((string) $this->getJson($url)->assertNotFound()->getContent());
})->with([
    'unknown vendor' => ['/admin/extensions/evil/security/000000000000/extension.js'],
    'unknown package' => ['/admin/extensions/acme/other/000000000000/extension.js'],
    'upper case vendor' => ['/admin/extensions/ACME/security/000000000000/extension.js'],
    'vendor traversal' => ['/admin/extensions/%2e%2e/security/000000000000/extension.js'],
    'package with a slash' => ['/admin/extensions/acme/security%2f..%2f..%2fdemo/000000000000/extension.js'],
    'non-hex version' => ['/admin/extensions/acme/security/zzzzzzzzzzzz/extension.js'],
    'version traversal' => ['/admin/extensions/acme/security/%2e%2e/extension.js'],
    'too long version' => ['/admin/extensions/acme/security/'.str_repeat('a', 65).'/extension.js'],
]);

it('never serves the files of one extension through another', function () {
    $demo = Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');
    $demoBase = '/admin/extensions/acme/demo/'.$demo->version().'/';

    $this->get($demoBase.'chunks/lazy.js')->assertOk();
    $this->get($this->base.'chunks/lazy.js')->assertNotFound();
    $this->get($demoBase.'chunks/part.js')->assertNotFound();
    $this->get($this->base.'../../acme-demo/dist/extension.js')->assertNotFound();
    $this->get($this->base.'%2e%2e/%2e%2e/acme-demo/dist/extension.js')->assertNotFound();
});

/*
 * Asset serving: access (§8 "who gets the code").
 */

it('serves extension files to panel users only', function () {
    $this->get($this->base.'extension.js')->assertOk();

    $this->actingAs($this->eve);
    $forbidden = $this->get($this->base.'extension.js')->assertForbidden();

    expect((string) $forbidden->getContent())->not->toContain("acme/security'");

    auth()->logout();

    $this->get($this->base.'extension.js')->assertRedirect('/admin/login');
    $this->getJson($this->base.'extension.js')->assertUnauthorized();
});

it('gives the login page no extension script, style, name or translations', function () {
    $this->extension->translations('acme-security');
    app('translator')->addNamespace('acme-security', SecurityFixtures::LANG);
    auth()->logout();

    $html = extsecHtml('/admin/login');

    expect($html)->not->toContain('/extensions/')
        ->and($html)->not->toContain('extension.js')
        ->and($html)->not->toContain('extension.css')
        ->and($html)->not->toContain('acme/security')
        ->and($html)->not->toContain('acme-security')
        ->and($html)->not->toContain('alert(');

    // The import map is part of the root view on every page (ImportMapTest), but it only maps the panel's own
    // public runtime modules: no extension ever appears in it.
    [$map] = extsecImportMap($html);

    expect(array_keys($map))->toBe(['imports'])
        ->and(array_keys($map['imports']))->toBe(array_keys(ExtensionAssets::RUNTIME));
});

it('gives users refused by the gate no extension code on the 403 page', function () {
    $this->actingAs($this->eve);

    $html = (string) $this->get('/admin')->assertForbidden()->getContent();

    expect($html)->not->toContain('/extensions/acme/security')
        ->and($html)->not->toContain('acme/security');
});

/*
 * Import map and CSP (§5.1, §8 "CSP").
 */

it('lists only registered extensions and never lets them add import map specifiers', function () {
    $this->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.extensions', [[
            'name' => 'acme/security',
            'script' => $this->base.'extension.js',
            'style' => $this->base.'extension.css',
        ]]));

    $html = extsecHtml('/admin');
    [$map] = extsecImportMap($html);

    expect($map)->toBe(ExtensionAssets::importMap())
        ->and(array_keys($map['imports']))->toBe(['vue', '@inertiajs/vue3', 'larsadmin'])
        ->and($html)->not->toContain('acme/demo');
});

it('encodes the import map so a hostile asset origin cannot break out of its tag', function (string $origin) {
    URL::useAssetOrigin($origin);
    auth()->logout();

    [$map, $raw] = extsecImportMap(extsecHtml('/admin/login'));

    // The hostile value reaches the map (the test is not vacuous) but only escaped.
    expect($map['imports']['vue'])->toStartWith($origin)
        ->and($raw)->not->toContain('<')
        ->and($raw)->not->toContain('>')
        ->and($raw)->not->toContain("\u{2028}")
        ->and($raw)->not->toContain("'");
})->with([
    'closing script' => ['https://cdn.test/</script><script>alert(1)</script>'],
    'upper case closing script' => ['https://cdn.test/</SCRIPT ><script>alert(1)</script>'],
    'html comment' => ['https://cdn.test/<!--<script>'],
    'quotes and ampersand' => ["https://cdn.test/\"'&amp;"],
    'line separator' => ["https://cdn.test/\u{2028}x"],
]);

it('escapes hostile extension translations in the page', function () {
    $this->extension->translations('acme-security');
    app('translator')->addNamespace('acme-security', SecurityFixtures::LANG);

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.extensionTranslations.acme-security.xss', '</script><script>alert(1)</script><!--'));

    $html = extsecHtml('/admin');

    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('<!--');
});

it('adds the nonce only when the application sets one, escaped', function () {
    auth()->logout();

    expect(extsecHtml('/admin/login'))->not->toContain('nonce');

    Vite::useCspNonce('n0nce');

    $html = extsecHtml('/admin/login');

    expect(substr_count($html, ' nonce="n0nce"'))->toBe(3)
        ->and($html)->toContain('<script type="importmap" nonce="n0nce">');

    Vite::useCspNonce('"><script>alert(1)</script>');

    $html = extsecHtml('/admin/login');

    expect($html)->not->toContain('"><script>alert(1)')
        ->and(substr_count($html, ' nonce="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"'))->toBe(3);
});

it('renders the page and the Inertia version exactly as 1.4 without extensions', function () {
    Larsadmin::flushState();
    Larsadmin::auth(fn () => true);

    // Extension routes alone add nothing to the page.
    Larsadmin::routes(fn () => Route::get('acme/ping', fn () => 'pong'));
    LarsadminServiceProvider::registerExtensionRoutes();

    $manifest = public_path('vendor/larsadmin/.vite/manifest.json');
    $legacyVersion = is_file($manifest) ? md5_file($manifest) : null;

    foreach (['/admin', '/admin/login'] as $url) {
        if ($url === '/admin/login') {
            auth()->logout();
        }

        $html = extsecHtml($url);

        expect($html)->toBe(extsecHtml($url, legacy: true))
            ->and($html)->not->toContain('importmap')
            ->and($html)->not->toContain('nonce')
            ->and($html)->not->toContain('/extensions/');
    }

    expect(Larsadmin::assetsVersion())->toBe($legacyVersion)
        ->and($this->get('/admin/login')->viewData('page')['version'])->toBe((string) $legacyVersion);
});

/*
 * Larsadmin::extension() validation (§4.1).
 */

it('refuses invalid extension names', function (string $name) {
    expect(fn () => Larsadmin::extension($name, SecurityFixtures::DIST))->toThrow(InvalidArgumentException::class);

    expect(array_keys(Larsadmin::registeredExtensions()))->toBe(['acme/security']);
})->with([
    'no vendor' => ['security'],
    'empty' => [''],
    'upper case' => ['Acme/Security'],
    'three segments' => ['acme/security/x'],
    'traversal' => ['../security'],
    'traversal package' => ['acme/..'],
    'dot package' => ['acme/.hidden'],
    'leading dash' => ['-acme/security'],
    'space' => ['acme/sec urity'],
    'closing script' => ['acme/</script>'],
    'html comment' => ['acme/<!--'],
    'quote' => ["acme/x'y"],
    'double quote' => ['acme/x"y'],
    'backslash' => ['acme\\security'],
    'nul byte' => ["acme/security\0"],
    'unicode' => ['acme/sécurité'],
]);

it('refuses missing directories, files and NUL bytes as extension directories', function (string $path) {
    expect(fn () => Larsadmin::extension('acme/other', $path))->toThrow(InvalidArgumentException::class);

    expect(Larsadmin::extensionFor('acme/other'))->toBeNull();
})->with([
    'missing' => [SecurityFixtures::ROOT.'/missing'],
    'a file' => [SecurityFixtures::DIST.'/extension.js'],
    'nul byte' => [SecurityFixtures::DIST."\0/../outside"],
]);

it('refuses an extension name with a trailing new line', function () {
    expect(fn () => Larsadmin::extension("acme/security\n", SecurityFixtures::DIST))->toThrow(InvalidArgumentException::class)
        ->and(preg_match(Tool::COMPONENT_PATTERN, "acme/tool\n"))->toBe(0)
        ->and(fn () => Larsadmin::extension('acme/security', SecurityFixtures::DIST)->translations("acme\n"))->toThrow(InvalidArgumentException::class);

    $this->artisan('larsadmin:extension', ['name' => "acme/security\n"])->assertFailed();
});

it('refuses relative extension directories', function (string $path) {
    expect(fn () => Larsadmin::extension('acme/other', $path))->toThrow(InvalidArgumentException::class, 'absolute path')
        ->and(Larsadmin::extensionFor('acme/other'))->toBeNull();
})->with([
    'dot' => ['.'],
    'relative' => ['tests/Fixtures'],
    'parent' => ['../larsadmin'],
    'stream wrapper' => ['file://'.SecurityFixtures::DIST],
]);

it('refuses an empty extension directory instead of serving the working directory', function () {
    expect(fn () => Larsadmin::extension('acme/other', ''))->toThrow(InvalidArgumentException::class);
});

it('refuses a symlinked extension directory', function () {
    $link = SecurityFixtures::ROOT.'/linked-dist-'.getmypid();

    if (! extsecLink(realpath(SecurityFixtures::DIST), $link)) {
        $this->markTestSkipped('Directory links are not available.');
    }

    // A Windows junction (is_link() is false for it) is refused as well.
    try {
        expect(fn () => Larsadmin::extension('acme/linked', $link))->toThrow(InvalidArgumentException::class, 'symbolic link')
            ->and(fn () => Larsadmin::extension('acme/linked', $link.'/'))->toThrow(InvalidArgumentException::class, 'symbolic link')
            ->and(Larsadmin::extensionFor('acme/linked'))->toBeNull();
    } finally {
        extsecUnlink($link);
    }
});

it('stores the real path of the extension directory', function () {
    $extension = Larsadmin::extension('acme/dotted', SecurityFixtures::DIST.'/chunks/..');

    expect($extension->path)->toBe(realpath(SecurityFixtures::DIST))
        ->and($extension->path)->not->toContain('..');
});

it('refuses to register a name again with another directory', function () {
    expect(Larsadmin::extension('acme/security', SecurityFixtures::DIST.'/../dist'))->toBe($this->extension);

    expect(fn () => Larsadmin::extension('acme/security', FixtureExtensions::DEMO.'/dist'))
        ->toThrow(InvalidArgumentException::class, 'already registered');

    expect(Larsadmin::extensionFor('acme/security')->path)->toBe(realpath(SecurityFixtures::DIST));
    $this->get($this->base.'extension.js')->assertOk();
});

it('refuses unsafe script and style files', function (string $file) {
    expect(fn () => $this->extension->script($file))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->extension->style($file))->toThrow(InvalidArgumentException::class)
        ->and($this->extension->scriptFile())->toBe('extension.js');
})->with([
    'parent' => ['../outside/secret.js'],
    'absolute' => ['/etc/passwd'],
    'backslash' => ['..\\outside\\secret.js'],
    'drive' => ['C:/x.js'],
    'dotfile' => ['.env'],
    'encoded' => ['%2e%2e/outside/secret.js'],
    'nul byte' => ["extension.js\0"],
]);

/*
 * Larsadmin::routes() and findRecord() (§4.1, §8 "extension routes").
 */

function extsecRoutes(): void
{
    Larsadmin::routes(function () {
        Route::get('sec/ping', fn () => ['pong' => true]);
        Route::post('sec/ping', fn () => ['posted' => true]);
        Route::get('entities/posts', fn () => ['shadow' => true]);
        Route::match(['get', 'post'], 'sec/{entity}/{id}/{ability}', fn (Request $request, string $entity, string $id, string $ability) => [
            'id' => Larsadmin::findRecord($request, $entity, $id, $ability)->getKey(),
        ]);
    });

    LarsadminServiceProvider::registerExtensionRoutes();
}

it('puts every extension route behind the panel gate', function (string $method) {
    extsecRoutes();

    $this->actingAs($this->eve)->json($method, '/admin/ext/sec/ping')->assertForbidden();

    auth()->logout();

    $this->json($method, '/admin/ext/sec/ping')->assertUnauthorized();
    $this->call($method, '/admin/ext/sec/ping')->assertRedirect('/admin/login');
})->with([
    'get' => ['GET'],
    'post' => ['POST'],
]);

it('keeps extension routes inside their own prefix', function () {
    extsecRoutes();

    Larsadmin::entities([PostEntity::class]);

    $this->getJson('/admin/ext/entities/posts')->assertOk()->assertExactJson(['shadow' => true]);
    $this->get('/admin/entities/posts')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Entities/Index', false));
});

it('finds records only inside the detail scope, whatever the ability (IDOR)', function (string $ability) {
    extsecRoutes();

    Larsadmin::entities([ScopedPost::class]);
    Gate::policy(Post::class, PostPolicy::class);
    PostPolicy::$allowDelete = true;
    $live = Post::create(['title' => 'Live', 'status' => 'live']);
    $draft = Post::create(['title' => 'Draft', 'status' => 'draft']);

    $this->getJson("/admin/ext/sec/scoped-posts/{$live->id}/{$ability}")->assertOk()->assertExactJson(['id' => $live->id]);

    $response = $this->getJson("/admin/ext/sec/scoped-posts/{$draft->id}/{$ability}")->assertNotFound();

    expect((string) $response->getContent())->not->toContain('Draft')
        ->and((string) $response->getContent())->not->toContain('Post');
})->with([
    'view' => ['view'],
    'update' => ['update'],
    'delete' => ['delete'],
]);

it('enforces the policy ability on the record', function (string $ability, int $status) {
    extsecRoutes();

    Larsadmin::entities([PostEntity::class]);
    Gate::policy(Post::class, NoUpdatePolicy::class);
    $live = Post::create(['title' => 'Live', 'status' => 'live']);

    $this->postJson("/admin/ext/sec/posts/{$live->id}/{$ability}")->assertStatus($status);
})->with([
    'view allowed' => ['view', 200],
    'update denied' => ['update', 403],
    'delete denied' => ['delete', 403],
]);

it('answers 403 without viewAny, also for missing records', function () {
    extsecRoutes();

    Larsadmin::entities([PostEntity::class]);
    Gate::policy(Post::class, UpdateOnlyPolicy::class);
    $live = Post::create(['title' => 'Live', 'status' => 'live']);

    $this->getJson("/admin/ext/sec/posts/{$live->id}/update")->assertForbidden();
    $this->getJson('/admin/ext/sec/posts/999/update')->assertForbidden();
});

it('answers 404 without naming the model for unknown entities and malformed keys', function (string $entity, string $id) {
    extsecRoutes();

    Larsadmin::entities([PostEntity::class]);
    Post::create(['title' => 'Live', 'status' => 'live']);

    $content = (string) $this->getJson('/admin/ext/sec/'.$entity.'/'.$id.'/view')->assertNotFound()->getContent();

    expect($content)->not->toContain('Maxcxam')
        ->and($content)->not->toContain('Fixtures')
        ->and($content)->not->toContain('No query results')
        ->and($content)->not->toContain('posts"');
})->with([
    'unknown entity' => ['nope', '1'],
    'missing record' => ['posts', '999'],
    'sql injection' => ['posts', '1%20OR%201=1'],
    'negative' => ['posts', '-1'],
    'exponent' => ['posts', '1e0'],
    'hex' => ['posts', '0x1'],
    'overflow' => ['posts', '99999999999999999999'],
    'encoded nul' => ['posts', '1%00'],
]);

it('refuses abilities other than view, update and delete', function (string $ability) {
    extsecRoutes();

    Larsadmin::entities([PostEntity::class]);
    $live = Post::create(['title' => 'Live', 'status' => 'live']);

    expect(fn () => Larsadmin::findRecord(request(), 'posts', $live->id, $ability))
        ->toThrow(InvalidArgumentException::class, 'Unsupported ability');

    // Through HTTP the programming error is a 500 without details.
    $content = (string) $this->getJson("/admin/ext/sec/posts/{$live->id}/".rawurlencode($ability))->assertStatus(500)->getContent();

    expect($content)->not->toContain('Unsupported ability');
})->with([
    'view any' => ['viewAny'],
    'create' => ['create'],
    'force delete' => ['forceDelete'],
    'restore' => ['restore'],
    'upper case' => ['View'],
    'padded' => ['view '],
]);

/*
 * DataCard (§4.4, §8 "card data", ADR-033).
 */

it('authorizes data cards before running data()', function (Closure $arrange, string $url, int $status) {
    $arrange->call($this);

    $this->getJson($url)->assertStatus($status);

    expect(LeakyCard::$calls)->toBe(0);
})->with([
    'invisible card' => [function () {
        extsecDashboard([LeakyCard::make()->canSee(fn () => false)]);
    }, EXTSEC_CARD_URL, 404],
    'invisible dashboard' => [function () {
        extsecDashboard([LeakyCard::make()])->canSee(fn () => false);
    }, EXTSEC_CARD_URL, 404],
    'refused by the gate' => [function () {
        extsecDashboard([LeakyCard::make()]);
        $this->actingAs($this->eve);
    }, EXTSEC_CARD_URL, 403],
    'guest' => [function () {
        extsecDashboard([LeakyCard::make()]);
        auth()->logout();
    }, EXTSEC_CARD_URL, 401],
    'entity without viewAny' => [function () {
        Larsadmin::entities([MetricPost::class]);
        MetricPost::$cardList = [LeakyCard::make()];
        Gate::policy(Post::class, UpdateOnlyPolicy::class);
    }, '/admin/api/entities/metric-posts/metrics/leaky-card', 403],
    'invalid parameter' => [function () {
        extsecDashboard([LeakyCard::make()]);
    }, EXTSEC_CARD_URL.'?q=toolong', 422],
    'nested parameter' => [function () {
        extsecDashboard([LeakyCard::make()]);
    }, EXTSEC_CARD_URL.'?q[a][b]=1', 422],
]);

it('re-checks the owner on every cached request', function () {
    LeakyCard::$ttl = 60;
    $dashboard = extsecDashboard([LeakyCard::make()]);

    $this->getJson(EXTSEC_CARD_URL)->assertOk();

    $this->actingAs($this->eve)->getJson(EXTSEC_CARD_URL)->assertForbidden();

    $this->actingAs($this->bob);
    $dashboard->canSee(fn () => false);

    $this->getJson(EXTSEC_CARD_URL)->assertNotFound();

    expect(LeakyCard::$calls)->toBe(1);
});

it('never serves one user the cached data of another', function () {
    LeakyCard::$ttl = 60;
    extsecDashboard([LeakyCard::make()]);

    $this->getJson(EXTSEC_CARD_URL)->assertOk()->assertJsonPath('data.secret', 'secret-of-'.$this->bob->id);

    $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);

    $this->actingAs($alice)->getJson(EXTSEC_CARD_URL)->assertOk()->assertJsonPath('data.secret', 'secret-of-'.$alice->id);
    $this->actingAs($this->bob)->getJson(EXTSEC_CARD_URL)->assertOk()->assertJsonPath('data.secret', 'secret-of-'.$this->bob->id);

    expect(LeakyCard::$calls)->toBe(2);
});

it('never shares cached card data between owners, tenants or ignored parameters', function () {
    LeakyCard::$ttl = 60;
    extsecDashboard([LeakyCard::make()]);
    Larsadmin::entities([MetricPost::class]);
    MetricPost::$cardList = [LeakyCard::make()];

    $this->getJson(EXTSEC_CARD_URL)->assertOk()->assertJsonPath('data.owner', 'dashboard:cards-dashboard');
    $this->getJson('/admin/api/entities/metric-posts/metrics/leaky-card')->assertOk()->assertJsonPath('data.owner', 'entity:metric-posts');

    expect(LeakyCard::$calls)->toBe(2);

    // A tenant switch through the global scope gets its own entry.
    $tenant = 'one';
    Larsadmin::metricCacheScopeUsing(function (Request $request, $card) use (&$tenant) {
        return $tenant;
    });
    $this->getJson(EXTSEC_CARD_URL)->assertOk();
    $tenant = 'two';
    $this->getJson(EXTSEC_CARD_URL)->assertOk();

    expect(LeakyCard::$calls)->toBe(4);

    // Parameters outside rules() are dropped: they can neither poison nor bust the cache.
    $this->getJson(EXTSEC_CARD_URL.'?evil=1')->assertOk();
    $this->getJson(EXTSEC_CARD_URL.'?evil=2&q=ab')->assertOk();
    $this->getJson(EXTSEC_CARD_URL.'?q=ab')->assertOk();

    expect(LeakyCard::$calls)->toBe(5);
});

it('never hands a data card to a cache scope callback typed for metrics', function () {
    LeakyCard::$ttl = 60;
    extsecDashboard([LeakyCard::make()]);

    // Calling this callback with a DataCard would be a TypeError (a 500 for every user).
    Larsadmin::metricCacheScopeUsing(fn (Request $request, Metric $metric) => 'tenant');

    $this->getJson(EXTSEC_CARD_URL)->assertOk()->assertJsonPath('data.secret', 'secret-of-'.$this->bob->id);
    $this->getJson(EXTSEC_CARD_URL)->assertOk();

    expect(LeakyCard::$calls)->toBe(2);
});

it('answers data card errors without exception messages', function (string $fail, int $status) {
    extsecDashboard([LeakyCard::make()]);
    LeakyCard::$fail = $fail;

    $response = $this->getJson(EXTSEC_CARD_URL)->assertStatus($status);

    extsecAssertNoLeak((string) $response->getContent());

    expect((string) $response->getContent())->not->toContain('SQLSTATE')
        ->and((string) $response->getContent())->not->toContain('LeakyCard');
})->with([
    'unexpected exception' => ['runtime', 500],
    'authorization with a reason' => ['authorization', 403],
]);

it('does not echo invalid parameters back', function () {
    extsecDashboard([LeakyCard::make()]);

    $content = (string) $this->getJson(EXTSEC_CARD_URL.'?q='.rawurlencode('<script>alert(1)</script>'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('q')
        ->getContent();

    expect($content)->not->toContain('<script>')
        ->and($content)->not->toContain('\u003Cscript')
        ->and($content)->not->toContain('alert(1)');
});

/*
 * Tools (§4.5, Q-65).
 */

it('builds no tool props for users who may not see the tool', function (Closure $arrange, int $status) {
    Larsadmin::tools([SpyTool::make()->canSee(fn (Request $request) => $request->user()?->email === 'bob@example.com')]);
    $arrange->call($this);

    $this->get('/admin/tools/spy-tool')->assertStatus($status);
    $this->get('/admin/tools/spy-tool', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Larsadmin::assetsVersion()])->assertStatus($status);

    expect(SpyTool::$propsCalls)->toBe(0);
})->with([
    'canSee refuses' => [function () {
        $this->actingAs(User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']));
    }, 404],
    'gate refuses' => [function () {
        $this->actingAs($this->eve);
    }, 403],
    'guest' => [function () {
        auth()->logout();
    }, 302],
]);

it('answers 404 for unknown tool keys', function (string $key) {
    Larsadmin::tools([SpyTool::class]);

    $this->get('/admin/tools/spy-tool')->assertOk();
    $this->get('/admin/tools/'.$key)->assertNotFound();

    expect(SpyTool::$propsCalls)->toBe(1);
})->with([
    'unknown' => ['backups'],
    'upper case' => ['SPY-TOOL'],
    'class name' => ['SpyTool'],
    'trailing dot' => ['spy-tool.'],
    'encoded slash' => ['spy-tool%2F..'],
    'traversal' => ['..%2Fspy-tool'],
]);

it('refuses tools whose component is not a plain extension key', function (string $component) {
    $tool = new class extends SpyTool
    {
        public function uriKey(): string
        {
            return 'hostile';
        }
    };
    $tool->component = $component;

    expect(fn () => Larsadmin::tools([$tool]))->toThrow(InvalidArgumentException::class, 'must be an extension key')
        ->and(Larsadmin::toolForKey('hostile'))->toBeNull();
})->with([
    'built-in key' => ['text-field'],
    'script tag' => ['acme/<script>'],
    'quote' => ['acme/x"onload'],
    'traversal' => ['../acme'],
    'three segments' => ['acme/x/y'],
    'upper case' => ['Acme/Spy'],
    'empty package' => ['acme/'],
]);

it('refuses a second tool class claiming a registered uriKey', function () {
    Larsadmin::tools([SpyTool::class]);

    $impostor = new class extends SpyTool
    {
        public string $component = 'evil/spy';

        public function uriKey(): string
        {
            return 'spy-tool';
        }
    };

    expect(fn () => Larsadmin::tools([$impostor]))->toThrow(InvalidArgumentException::class, 'Two tools use the uriKey [spy-tool]')
        ->and(Larsadmin::toolForKey('spy-tool'))->toBeInstanceOf(SpyTool::class)
        ->and(Larsadmin::toolForKey('spy-tool')->component)->toBe('acme/spy');
});

it('sends only allow-listed icons to the client', function (string $icon, ?string $sent) {
    SpyTool::$icon = $icon;
    Larsadmin::tools([SpyTool::class]);

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('tools.0.icon', $sent));
})->with([
    'allowed' => ['database', 'database'],
    'markup' => ['<svg onload=alert(1)>', null],
    'quote' => ['database" onmouseover="alert(1)', null],
    'upper case' => ['DATABASE', null],
    'padded' => [' database', null],
    'path' => ['../icons/database', null],
    'url' => ['https://evil.test/x.svg', null],
]);

/*
 * Overrides of built-in components (§5.6, Q-70): UI only, the server decides.
 */

it('validates overridable built-in and extension fields on the server whatever the client renders', function (array $input, string $error) {
    Larsadmin::entities([RatedPost::class]);
    $post = Post::create(['title' => 'Kept', 'status' => 'live', 'views' => 3]);

    $this->putJson("/admin/entities/rated-posts/{$post->id}", $input)->assertStatus(422)->assertJsonValidationErrors($error);

    expect($post->fresh()->only('title', 'status', 'views'))->toBe(['title' => 'Kept', 'status' => 'live', 'views' => 3]);
})->with([
    'text too long' => [['title' => str_repeat('x', 21), 'status' => 'live'], 'title'],
    'text missing' => [['status' => 'live'], 'title'],
    'text as array' => [['title' => ['x'], 'status' => 'live'], 'title'],
    'select outside its options' => [['title' => 'T', 'status' => 'deleted'], 'status'],
    'extension field above its max' => [['title' => 'T', 'status' => 'live', 'views' => 9], 'views'],
    'extension field not a number' => [['title' => 'T', 'status' => 'live', 'views' => 'many'], 'views'],
]);

it('fills only declared, writable fields', function () {
    Larsadmin::entities([RatedPost::class]);
    $post = Post::create(['title' => 'Kept', 'status' => 'live', 'views' => 3, 'subtitle' => 'Original']);

    $this->putJson("/admin/entities/rated-posts/{$post->id}", [
        'title' => 'New',
        'status' => 'draft',
        'views' => '4',
        'subtitle' => 'Hacked',
        'author_id' => $this->bob->id,
        'id' => 999,
        'body' => 'Injected',
        'published' => true,
    ])->assertSessionHasNoErrors();

    $fresh = Post::query()->findOrFail($post->id);

    expect($fresh->only('title', 'status', 'views', 'subtitle', 'author_id', 'body', 'published'))->toBe([
        'title' => 'New',
        'status' => 'draft',
        'views' => 4,
        'subtitle' => 'Original',
        'author_id' => null,
        'body' => null,
        'published' => false,
    ])->and(Post::query()->find(999))->toBeNull();
});

it('keeps the stored value of a field the client did not send (fail closed)', function () {
    Larsadmin::entities([RatedPost::class]);
    $post = Post::create(['title' => 'Kept', 'status' => 'live', 'views' => 3]);

    // An unavailable extension component is left out of the submission (ADR-032): the server keeps the value.
    $this->putJson("/admin/entities/rated-posts/{$post->id}", ['title' => 'New', 'status' => 'live'])->assertSessionHasNoErrors();

    expect($post->fresh()->views)->toBe(3);
});

it('serializes fields the same whether or not extensions are registered', function () {
    Larsadmin::entities([RatedPost::class]);
    $post = Post::create(['title' => 'Kept', 'status' => 'live', 'views' => 3]);

    $with = $this->get("/admin/entities/rated-posts/{$post->id}/edit")->assertOk()->viewData('page')['props'];

    Larsadmin::flushState();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([RatedPost::class]);

    $without = $this->get("/admin/entities/rated-posts/{$post->id}/edit")->assertOk()->viewData('page')['props'];

    expect($with['record'])->toBe($without['record'])
        ->and($with['entity'])->toBe($without['entity'])
        ->and(json_encode($with['record']))->not->toContain('overrid');

    $components = collect($with['record']['fields'] ?? [])->pluck('component')->all();

    expect($components)->toContain('text-field', 'select-field', 'acme/rating');
});

it('ignores the values of fields marked unavailable on the server')
    ->skip('Not a server feature: the client leaves unavailable fields out of the submission (ADR-032); the server keeps omitted values, covered above.');

/*
 * Scaffold commands (§7).
 */

describe('scaffold commands', function () {
    beforeEach(function () {
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-extsec-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
        $this->outside = $this->root.'-outside';
        mkdir($this->root.'/app', 0777, true);
        mkdir($this->outside, 0777, true);
        file_put_contents($this->root.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

        $this->originalBase = $this->app->basePath();
        $this->app->setBasePath($this->root);
    });

    afterEach(function () {
        $this->app->setBasePath($this->originalBase);

        // Remove the link first: deleting the root must never recurse into the link's target.
        extsecUnlink($this->root.'/escape');

        (new Filesystem)->deleteDirectory($this->root);
        (new Filesystem)->deleteDirectory($this->outside);
    });

    it('refuses hostile package names and writes nothing', function (string $name) {
        $this->artisan('larsadmin:extension', ['name' => $name])->assertFailed();

        expect(is_dir($this->root.'/packages'))->toBeFalse();
    })->with([
        'quote' => ["acme/x'y"],
        'double quote' => ['acme/x"y'],
        'php close tag' => ['acme/x?>'],
        'dollar' => ['acme/x$y'],
        'backslash' => ['acme/x\\y'],
        'traversal package' => ['acme/../../x'],
        'absolute' => ['/acme/x'],
        'new line' => ["acme/x\nsystem"],
        'nul byte' => ["acme/x\0"],
        'space' => ['acme/x y'],
        'markup' => ['acme/<x>'],
    ]);

    it('refuses hostile field and card names and writes nothing', function (string $command, string $name) {
        $this->artisan($command, ['name' => $name])->assertFailed();

        expect(is_dir($this->root.'/app/Larsadmin'))->toBeFalse()
            ->and(is_dir($this->root.'/resources'))->toBeFalse();
    })->with([
        'field quote' => ['larsadmin:field', "Rat'ing"],
        'field double quote' => ['larsadmin:field', 'Rat"ing'],
        'field semicolon' => ['larsadmin:field', 'Rating;system'],
        'field dollar' => ['larsadmin:field', 'Rat$ing'],
        'field brace' => ['larsadmin:field', 'Rating{}'],
        'field space' => ['larsadmin:field', 'Rat ing'],
        'field slash' => ['larsadmin:field', 'Rat/ing'],
        'field backslash' => ['larsadmin:field', 'Rat\\ing'],
        'field file name' => ['larsadmin:field', 'Rating.php'],
        'field absolute' => ['larsadmin:field', '/tmp/Rating'],
        'field nul byte' => ['larsadmin:field', "Rating\0"],
        'field unicode' => ['larsadmin:field', 'Ratingé'],
        'card quote' => ['larsadmin:card', "Sa'les"],
        'card traversal' => ['larsadmin:card', '../../Sales'],
        'card close tag' => ['larsadmin:card', 'Sales?>'],
    ]);

    it('refuses target directories outside the application', function (string $command, array $arguments) {
        $this->artisan($command, $arguments)->assertFailed();

        expect(glob($this->outside.'/*'))->toBe([])
            ->and(is_dir($this->root.'/packages'))->toBeFalse();
    })->with([
        'path parent' => ['larsadmin:extension', ['name' => 'acme/x', '--path' => '..']],
        'path sibling' => ['larsadmin:extension', ['name' => 'acme/x', '--path' => '../'.basename(sys_get_temp_dir()).'/x']],
        'path encoded parent' => ['larsadmin:extension', ['name' => 'acme/x', '--path' => '%2e%2e']],
        'path home' => ['larsadmin:extension', ['name' => 'acme/x', '--path' => '~/x']],
        'path unc' => ['larsadmin:extension', ['name' => 'acme/x', '--path' => '\\\\server\\share']],
        'path nul byte' => ['larsadmin:extension', ['name' => 'acme/x', '--path' => "packages\0/../.."]],
        'path stream wrapper' => ['larsadmin:extension', ['name' => 'acme/x', '--path' => 'phar://x']],
        'extension parent' => ['larsadmin:field', ['name' => 'Rating', '--extension' => 'packages/../../x']],
        'extension absolute' => ['larsadmin:card', ['name' => 'Sales', '--extension' => '/etc']],
        'extension drive' => ['larsadmin:card', ['name' => 'Sales', '--extension' => 'C:\\Windows']],
    ]);

    it('does not follow a link out of the application', function () {
        if (! extsecLink($this->outside, $this->root.'/escape')) {
            $this->markTestSkipped('Directory links are not available.');
        }

        $this->artisan('larsadmin:extension', ['name' => 'acme/x', '--path' => 'escape'])->assertFailed();

        expect(glob($this->outside.'/*'))->toBe([]);
    });

    it('never puts a composer.json namespace into generated PHP unescaped', function () {
        mkdir($this->root.'/packages/evil/src', 0777, true);
        file_put_contents($this->root.'/packages/evil/composer.json', json_encode([
            'name' => 'acme/evil',
            'autoload' => ['psr-4' => ["Acme\\Evil;echo 'pwned';//\\" => 'src/']],
        ]));

        $this->artisan('larsadmin:field', ['name' => 'Rating', '--extension' => 'packages/evil'])->assertFailed();

        expect(is_file($this->root.'/packages/evil/src/Fields/Rating.php'))->toBeFalse();
    });

    it('writes nothing when one target already exists (all or nothing)', function (string $command, array $arguments, string $existing, array $absent) {
        @mkdir(dirname($this->root.'/'.$existing), 0777, true);
        file_put_contents($this->root.'/'.$existing, 'mine');

        $this->artisan($command, $arguments)->expectsOutputToContain('Use --force to overwrite')->assertFailed();

        expect(file_get_contents($this->root.'/'.$existing))->toBe('mine');

        foreach ($absent as $file) {
            expect(is_file($this->root.'/'.$file))->toBeFalse("{$file} was written");
        }
    })->with([
        'field display exists' => [
            'larsadmin:field', ['name' => 'Rating'],
            'resources/larsadmin/js/fields/RatingDisplay.vue',
            ['app/Larsadmin/Fields/Rating.php', 'resources/larsadmin/js/fields/RatingInput.vue'],
        ],
        'card class exists' => [
            'larsadmin:card', ['name' => 'Sales', '--data' => true],
            'app/Larsadmin/Cards/Sales.php',
            ['resources/larsadmin/js/cards/SalesCard.vue'],
        ],
        'package readme exists' => [
            'larsadmin:extension', ['name' => 'acme/rating'],
            'packages/acme/rating/README.md',
            ['packages/acme/rating/composer.json', 'packages/acme/rating/src/RatingServiceProvider.php'],
        ],
    ]);

    it('generates PHP that lints and carries no user input unescaped', function () {
        $this->artisan('larsadmin:extension', ['name' => 'acme.co/larsadmin-x_y'])->assertSuccessful();
        $this->artisan('larsadmin:field', ['name' => 'star-rating_x'])->assertSuccessful();
        $this->artisan('larsadmin:card', ['name' => 'Top_Sales', '--data' => true])->assertSuccessful();

        $php = [
            ...glob($this->root.'/packages/acme.co/larsadmin-x_y/src/*.php'),
            ...glob($this->root.'/packages/acme.co/larsadmin-x_y/src/*/*.php'),
            ...glob($this->root.'/app/Larsadmin/*/*.php'),
        ];

        expect($php)->toHaveCount(5);

        foreach ($php as $file) {
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $code);

            expect($code)->toBe(0, implode("\n", $output))
                ->and(file_get_contents($file))->toMatch('/^namespace [A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*;$/m')
                ->and(file_get_contents($file))->toMatch('/^(final )?(abstract )?class [A-Za-z_][A-Za-z0-9_]* /m');
        }
    });
});
