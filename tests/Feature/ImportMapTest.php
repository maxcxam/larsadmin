<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Vite;
use Maxcxam\Larsadmin\Extensions\ExtensionAssets;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\FixtureExtensions;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * The HTML of a guest's login page; with $legacy rendered by the 1.4 root view (a verbatim copy).
 * Line endings are normalized: they depend on the checkout, not on the view.
 */
function loginPageHtml(bool $legacy = false): string
{
    if ($legacy) {
        app('view')->getFinder()->replaceNamespace('larsadmin', [FixtureExtensions::VIEWS_1_4]);
        app('view')->flushFinderCache();
    }

    try {
        return str_replace("\r\n", "\n", test()->get('/admin/login')->assertOk()->getContent());
    } finally {
        app('view')->getFinder()->replaceNamespace('larsadmin', [realpath(__DIR__.'/../../resources/views')]);
        app('view')->flushFinderCache();
    }
}

it('renders the root view exactly as 1.4 without extensions', function () {
    $html = loginPageHtml();

    expect($html)->toBe(loginPageHtml(legacy: true))
        ->and($html)->not->toContain('importmap')
        ->and($html)->not->toContain('nonce');
});

it('adds the nonce to the panel scripts only when the application sets one', function () {
    $legacy = loginPageHtml(legacy: true);

    Vite::useCspNonce('n0nce');
    $html = loginPageHtml();

    expect(substr_count($html, ' nonce="n0nce"'))->toBe(2)
        ->and(str_replace(' nonce="n0nce"', '', $html))->toBe($legacy)
        ->and($html)->toContain("<script nonce=\"n0nce\">\n        try {")
        ->and($html)->toMatch('#<script type="module" src="[^"]+app\.js[^"]*" nonce="n0nce"></script>#');
});

it('emits the import map before app.js when an extension is registered', function () {
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');

    $html = loginPageHtml();

    preg_match('#<script type="importmap">(.*?)</script>#s', $html, $match);

    expect($match)->toHaveCount(2)
        ->and(json_decode($match[1], true))->toBe(['imports' => [
            'vue' => Larsadmin::asset('runtime/vue.js'),
            '@inertiajs/vue3' => Larsadmin::asset('runtime/inertia.js'),
            'larsadmin' => Larsadmin::asset('runtime/larsadmin.js'),
        ]])
        ->and(strpos($html, 'type="importmap"'))->toBeLessThan(strpos($html, 'type="module"'))
        ->and($html)->toContain('app.css') // stylesheet line kept
        ->and($html)->not->toContain('/extensions/acme/demo'); // the login page loads no extension files
});

it('puts the nonce on the import map and both scripts', function () {
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');
    Vite::useCspNonce('n0nce');

    $html = loginPageHtml();

    expect(substr_count($html, ' nonce="n0nce"'))->toBe(3)
        ->and($html)->toContain('<script type="importmap" nonce="n0nce">{"imports":{');
});

it('escapes the import map so it cannot close its tag', function () {
    config(['app.url' => 'http://localhost/</script><script>alert(1)</script>']);
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');

    $html = loginPageHtml();

    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->toContain('</script>');
});

it('renders the import map for signed-in users too', function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']))
        ->get('/admin')
        ->assertOk()
        ->assertSee('<script type="importmap">', false);
});

it('preloads the static import closure of app.js from the published manifest', function () {
    // A public directory of this test only: parallel tests share Testbench's public/.
    $public = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-preload-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    $original = $this->app->publicPath();
    $this->app->usePublicPath($public);
    mkdir($public.'/vendor/larsadmin/.vite', 0777, true);
    file_put_contents($public.'/vendor/larsadmin/.vite/manifest.json', json_encode([
        '_app.css' => ['file' => 'app.css'],
        '_runtime-1.js' => ['file' => 'chunks/runtime-1.js'],
        '_vue-1.js' => ['file' => 'chunks/vue-1.js'],
        '_inertia-1.js' => ['file' => 'chunks/inertia-1.js', 'imports' => ['_vue-1.js']],
        '_shared-1.js' => ['file' => 'chunks/shared-1.js', 'imports' => ['_runtime-1.js', '_vue-1.js', '_inertia-1.js', '_shared-1.js'], 'dynamicImports' => ['lazy.js'], 'css' => ['app.css']],
        '_evil.js' => ['file' => '../../evil.js'],
        'lazy.js' => ['file' => 'chunks/lazy-1.js', 'isDynamicEntry' => true],
        'resources/js/app.js' => ['file' => 'app.js', 'isEntry' => true, 'imports' => ['_vue-1.js', '_inertia-1.js', '_shared-1.js', '_evil.js', '_missing.js']],
    ]));

    try {
        Vite::useCspNonce('n0nce');
        $html = loginPageHtml();

        preg_match_all('#<link rel="modulepreload" href="([^"]+)" nonce="n0nce">#', $html, $links);

        expect(array_map(fn (string $url) => parse_url($url, PHP_URL_PATH), $links[1]))->toBe([
            '/vendor/larsadmin/chunks/vue-1.js',
            '/vendor/larsadmin/chunks/inertia-1.js',
            '/vendor/larsadmin/chunks/runtime-1.js',
            '/vendor/larsadmin/chunks/shared-1.js',
        ])
            ->and($html)->not->toContain('lazy-1.js')
            ->and($html)->not->toContain('evil.js')
            ->and(array_filter($links[1], fn (string $url) => str_contains($url, '?')))->toBe([])
            ->and(strpos($html, 'modulepreload'))->toBeGreaterThan(strpos($html, 'type="module"'));
    } finally {
        $this->app->usePublicPath($original);
        (new Filesystem)->deleteDirectory($public);
    }
});

it('preloads nothing without a published manifest', function () {
    expect(ExtensionAssets::modulePreloadTags())->toBe('')
        ->and(loginPageHtml())->not->toContain('modulepreload');
});
