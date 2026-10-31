<?php

use Maxcxam\Larsadmin\Extensions\Extension;
use Maxcxam\Larsadmin\Extensions\ExtensionAssets;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\FixtureExtensions;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));

    $this->extension = Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');
    $this->base = '/admin/extensions/acme/demo/'.$this->extension->version().'/';
});

/**
 * Link $link to the directory $target: a symlink, or a junction on Windows without symlink rights.
 */
function linkDirectory(string $target, string $link): bool
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

function unlinkDirectory(string $link): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        @rmdir($link);
    } else {
        @unlink($link);
    }
}

it('serves the entry module, the stylesheet and chunks with their types', function (string $file, string $type) {
    $response = $this->get($this->base.$file)->assertOk();

    expect($response->headers->get('Content-Type'))->toBe($type)
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull()
        ->and($response->getFile()->getRealPath())->toBe(realpath(FixtureExtensions::DEMO.'/dist/'.$file));
})->with([
    'entry' => ['extension.js', 'text/javascript; charset=utf-8'],
    'style' => ['extension.css', 'text/css; charset=utf-8'],
    'chunk' => ['chunks/lazy.js', 'text/javascript; charset=utf-8'],
    'nested module' => ['sub/nested.mjs', 'text/javascript; charset=utf-8'],
    'json' => ['data.json', 'application/json'],
]);

it('caches the current version as immutable', function () {
    $response = $this->get($this->base.'extension.js')->assertOk();

    expect($response->headers->get('Cache-Control'))->toBe('immutable, max-age=31536000, private');

    config(['larsadmin.extensions.max_age' => 60]);

    expect($this->get($this->base.'extension.js')->headers->get('Cache-Control'))->toBe('immutable, max-age=60, private');
});

it('serves an old version with no-cache', function () {
    $response = $this->get('/admin/extensions/acme/demo/0123456789ab/extension.js')->assertOk();

    expect($response->headers->get('Cache-Control'))->toBe('no-cache, private');
});

it('answers 304 for a matching ETag and sends Last-Modified', function () {
    $response = $this->get($this->base.'extension.js')->assertOk();
    $etag = $response->headers->get('ETag');

    expect($etag)->not->toBeNull()
        ->and($response->headers->get('Last-Modified'))->not->toBeNull();

    $this->get($this->base.'extension.js', ['If-None-Match' => $etag])->assertStatus(304);
});

it('serves SVG images sandboxed', function () {
    $response = $this->get($this->base.'logo.svg')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($response->headers->get('Content-Security-Policy'))->toBe('sandbox');
});

it('serves source maps only when enabled', function () {
    $this->get($this->base.'extension.js.map')->assertNotFound();

    config(['larsadmin.extensions.source_maps' => true]);

    $response = $this->get($this->base.'extension.js.map')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/json');
});

it('refuses unregistered extensions', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    'unknown package' => ['/admin/extensions/acme/other/0123456789ab/extension.js'],
    'unknown vendor' => ['/admin/extensions/evil/demo/0123456789ab/extension.js'],
    'invalid version' => ['/admin/extensions/acme/demo/zzz/extension.js'],
]);

it('refuses traversal, dotfiles and types outside the allow-list', function (string $file) {
    $this->get($this->base.$file)->assertNotFound();
})->with([
    'parent' => ['../secret.php'],
    'parent script' => ['../outside.js'],
    'nested parent script' => ['chunks/../../outside.js'],
    'encoded parent script' => ['%2e%2e/outside.js'],
    'nested parent' => ['chunks/../../secret.php'],
    'encoded dots' => ['%2e%2e/secret.php'],
    'encoded slash' => ['..%2Fsecret.php'],
    'double encoded' => ['%252e%252e/secret.php'],
    'encoded backslash' => ['..%5Csecret.php'],
    'absolute' => ['/etc/passwd'],
    'encoded absolute' => ['%2Fetc%2Fpasswd'],
    'windows drive' => ['C:/Windows/win.ini'],
    'nul byte' => ['extension.js%00.css'],
    'dot segment' => ['./extension.js'],
    'dotfile' => ['.hidden.js'],
    'vite manifest' => ['.vite/manifest.json'],
    'php' => ['shell.php'],
    'missing file' => ['missing.js'],
    'directory' => ['chunks'],
    'empty segment' => ['chunks//lazy.js'],
]);

it('refuses a symlink that escapes the extension directory', function () {
    $link = FixtureExtensions::DEMO.'/dist/escape-'.getmypid();

    if (! linkDirectory(realpath(FixtureExtensions::DEMO), $link)) {
        $this->markTestSkipped('Directory links are not available.');
    }

    try {
        expect(is_file($link.'/secret.php'))->toBeTrue();

        $this->get($this->base.'escape-'.getmypid().'/secret.php')->assertNotFound();
        $this->get($this->base.'escape-'.getmypid().'/outside.js')->assertNotFound();
    } finally {
        unlinkDirectory($link);
    }
});

it('serves a symlink that stays inside the extension directory', function () {
    $link = FixtureExtensions::DEMO.'/dist/inside-'.getmypid();

    if (! linkDirectory(realpath(FixtureExtensions::DEMO.'/dist/chunks'), $link)) {
        $this->markTestSkipped('Directory links are not available.');
    }

    try {
        $this->get($this->base.'inside-'.getmypid().'/lazy.js')->assertOk();
    } finally {
        unlinkDirectory($link);
    }
});

it('resolves only safe paths inside the directory', function (string $file) {
    expect(ExtensionAssets::resolve($this->extension, $file))->toBeNull();
})->with([
    'backslash' => ['..\\outside.js'],
    'backslash inside' => ['chunks\\lazy.js'],
    'parent' => ['../outside.js'],
    'absolute' => ['/etc/passwd'],
    'windows absolute' => ['C:\\Windows\\win.ini'],
    'nul byte' => ["extension.js\0"],
    'control character' => ["extension.js\n"],
    'too long' => [str_repeat('a/', 600).'x.js'],
]);

it('resolves a safe file to its real path', function () {
    expect(ExtensionAssets::resolve($this->extension, 'chunks/lazy.js'))->toBe(realpath(FixtureExtensions::DEMO.'/dist/chunks/lazy.js'));
});

it('requires a signed-in panel user', function () {
    auth()->logout();

    $this->get($this->base.'extension.js')->assertRedirect('/admin/login');
    $this->getJson($this->base.'extension.js')->assertUnauthorized();
});

it('requires the panel gate', function () {
    Larsadmin::auth(fn () => false);

    $this->get($this->base.'extension.js')->assertForbidden();
});

it('builds URLs the asset route serves', function () {
    expect($this->extension)->toBeInstanceOf(Extension::class);

    $this->get($this->extension->url('chunks/lazy.js'))->assertOk();
    $this->get($this->extension->toClient()['style'])->assertOk();
});

it('re-checks the resolved path: a link inside the directory to .vite/ is refused', function () {
    $link = FixtureExtensions::DEMO.'/dist/alias-'.getmypid();

    if (! linkDirectory(realpath(FixtureExtensions::DEMO.'/dist/.vite'), $link)) {
        $this->markTestSkipped('Directory links are not available.');
    }

    try {
        expect(ExtensionAssets::resolve($this->extension, 'alias-'.getmypid().'/manifest.json'))->toBeNull();

        $this->get($this->base.'alias-'.getmypid().'/manifest.json')->assertNotFound();
    } finally {
        unlinkDirectory($link);
    }
});

it('refuses Windows 8.3 short names of dot directories', function () {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('8.3 short names exist on Windows only.');
    }

    $vite = realpath(FixtureExtensions::DEMO.'/dist/.vite');
    exec('cmd /c for %I in ("'.$vite.'") do @echo %~sI', $output, $code);
    $short = basename(trim((string) ($output[0] ?? '')));

    if ($code !== 0 || $short === '' || $short === '.vite' || str_starts_with($short, '.')) {
        $this->markTestSkipped('This volume has no 8.3 short names.');
    }

    // The requested name passes the lexical rules; the resolved ".vite" does not.
    expect(ExtensionAssets::normalizeFile($short.'/manifest.json'))->not->toBeNull()
        ->and(ExtensionAssets::resolve($this->extension, $short.'/manifest.json'))->toBeNull();
});
