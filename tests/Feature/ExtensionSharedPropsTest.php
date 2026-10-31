<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Extensions\ExtensionAssets;
use Maxcxam\Larsadmin\Http\Middleware\HandleInertiaRequests;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\FixtureExtensions;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    app()->setLocale('en');
    config(['larsadmin.locale' => 'en']);

    app('translator')->addNamespace('acme-demo', FixtureExtensions::DEMO.'/lang');

    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
});

function registerDemoExtension(): void
{
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist')->translations('acme-demo');
}

it('shares nothing new without extensions, as in 1.4', function () {
    $this->actingAs($this->user)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->missing('larsadmin.extensions')
        ->missing('larsadmin.extensionTranslations')
        ->missing('larsadmin.debug')
        ->missing('tools'));

    auth()->logout();

    $this->get('/admin/login')->assertInertia(fn (Assert $page) => $page->missing('larsadmin.extensions'));
});

it('keeps the 1.4 asset version without extensions', function () {
    // A public directory of this test only: parallel tests share Testbench's public/.
    $public = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-public-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    $original = $this->app->publicPath();
    $this->app->usePublicPath($public);

    $manifest = public_path('vendor/larsadmin/.vite/manifest.json');
    mkdir(dirname($manifest), 0777, true);
    file_put_contents($manifest, '{"app.js":{}}');

    try {
        Larsadmin::flushState();

        expect(Larsadmin::assetsVersion())->toBe(md5_file($manifest))
            ->and((new HandleInertiaRequests)->version(request()))->toBe(md5_file($manifest));

        registerDemoExtension();
        $extension = Larsadmin::extensionFor('acme/demo');

        expect(Larsadmin::assetsVersion())->toBe(md5(md5_file($manifest).'|acme/demo@'.$extension->version()));
    } finally {
        $this->app->usePublicPath($original);
        (new Filesystem)->deleteDirectory($public);
    }
});

it('changes the asset version with the extension version, even without a published manifest', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'larsadmin-ext-'.getmypid().'-'.random_int(1, PHP_INT_MAX);
    mkdir($dir.'/.vite', 0777, true);
    file_put_contents($dir.'/.vite/manifest.json', '{"a":1}');

    try {
        $first = Larsadmin::extension('acme/temp', $dir);
        $before = Larsadmin::assetsVersion();

        file_put_contents($dir.'/.vite/manifest.json', '{"a":2}');
        Larsadmin::flushState();
        Larsadmin::extension('acme/temp', $dir);

        expect($before)->toMatch('/^[a-f0-9]{32}$/')
            ->and(Larsadmin::assetsVersion())->not->toBe($before)
            ->and($first->version())->not->toBe(Larsadmin::extensionFor('acme/temp')->version());
    } finally {
        @unlink($dir.'/.vite/manifest.json');
        @rmdir($dir.'/.vite');
        @rmdir($dir);
    }
});

it('shares the versioned extension files and translations with panel users', function () {
    registerDemoExtension();
    $version = Larsadmin::extensionFor('acme/demo')->version();

    $this->actingAs($this->user)->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.extensions', [[
            'name' => 'acme/demo',
            'script' => "/admin/extensions/acme/demo/{$version}/extension.js",
            'style' => "/admin/extensions/acme/demo/{$version}/extension.css",
        ]])
        ->where('larsadmin.extensionTranslations', ['acme-demo' => ['stars' => ':count stars', 'group' => ['key' => 'Nested']]])
        ->where('larsadmin.debug', false)
        ->missing('tools'));
});

it('remembers the translations per locale and fingerprint on the client', function () {
    registerDemoExtension();

    $page = $this->actingAs($this->user)->get('/admin')->viewData('page');

    expect($page['onceProps'] ?? [])->toHaveKey('larsadmin.ext-translations.en.'.ExtensionAssets::fingerprint());
});

it('translates extension groups in the panel locale with the fallback', function () {
    registerDemoExtension();
    config(['larsadmin.locale' => 'uk']);

    $this->actingAs($this->user)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.extensionTranslations.acme-demo.stars', ':count зірок'));
});

it('shares an empty group for a namespace without translations', function () {
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist')->translations('acme-missing');

    $this->actingAs($this->user)->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.extensionTranslations', ['acme-missing' => []]));
});

it('shares the debug flag with panel users', function () {
    registerDemoExtension();
    config(['app.debug' => true]);

    $this->actingAs($this->user)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('larsadmin.debug', true));
});

it('shares no extension code with guests on the login page', function () {
    registerDemoExtension();
    config(['app.debug' => true]);

    $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.extensions', [])
        ->where('larsadmin.extensionTranslations', null)
        ->where('larsadmin.debug', false));
});

it('shares no extension code with users without the panel gate', function () {
    registerDemoExtension();
    Larsadmin::auth(fn () => false);

    config(['app.debug' => true]);
    $this->actingAs($this->user);

    // The gate turns every panel page into a 403, so the props are resolved directly.
    $request = Request::create('/admin');
    $request->setUserResolver(fn () => $this->user);
    $request->setLaravelSession(app('session.store'));

    $shared = (new HandleInertiaRequests)->share($request);

    expect(($shared['larsadmin']['extensions'])())->toBe([])
        ->and($shared['larsadmin']['extensionTranslations'])->toBeNull()
        ->and(($shared['larsadmin']['debug'])())->toBeFalse();

    $this->get('/admin')->assertForbidden();
});

it('does not query the database for extension props', function () {
    registerDemoExtension();
    $this->actingAs($this->user);

    DB::enableQueryLog();
    $this->get('/admin')->assertOk();
    $withExtension = count(DB::getQueryLog());

    Larsadmin::flushState();
    Larsadmin::auth(fn () => true);
    DB::flushQueryLog();
    $this->get('/admin')->assertOk();

    expect($withExtension)->toBe(count(DB::getQueryLog()));
});
