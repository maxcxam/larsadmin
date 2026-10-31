<?php

use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Tools\Backups;
use Maxcxam\Larsadmin\Tests\Fixtures\Tools\BuiltinKeyTool;
use Maxcxam\Larsadmin\Tests\Fixtures\Tools\OtherBackups;
use Maxcxam\Larsadmin\Tests\Fixtures\Tools\SystemInfo;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    app()->setLocale('en');
    config(['larsadmin.locale' => 'en']);

    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);
});

it('derives the name, key and defaults from the class', function () {
    $tool = SystemInfo::make();

    expect($tool->name())->toBe('System Info')
        ->and($tool->uriKey())->toBe('system-info')
        ->and($tool->icon())->toBeNull()
        ->and($tool->navigationIcon())->toBeNull()
        ->and($tool->authorizedToSee(Request::create('/')))->toBeTrue()
        ->and($tool->props(Request::create('/')))->toBe([]);
});

it('registers tools from classes and instances in order', function () {
    $info = SystemInfo::make();

    Larsadmin::tools([Backups::class]);
    Larsadmin::tools([$info]);

    expect(Larsadmin::registeredTools())->toHaveCount(2)
        ->and(Larsadmin::registeredTools()[0])->toBeInstanceOf(Backups::class)
        ->and(Larsadmin::registeredTools()[1])->toBe($info)
        ->and(Larsadmin::toolForKey('system-info'))->toBe($info)
        ->and(Larsadmin::toolForKey('nope'))->toBeNull();
});

it('replaces a tool registered again by the same class', function () {
    Larsadmin::tools([Backups::class]);
    $again = Backups::make()->canSee(fn () => false);
    Larsadmin::tools([$again]);

    expect(Larsadmin::registeredTools())->toBe([$again]);
});

it('refuses invalid tools', function (mixed $tool, string $message) {
    $tool = $tool instanceof Closure ? $tool() : $tool;

    expect(fn () => Larsadmin::tools([$tool]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not a tool class' => [User::class, 'must be Tool instances'],
    'an object' => [fn () => new stdClass, 'must be Tool instances or classes; got stdClass'],
    'an integer' => [42, 'got int'],
    'a built-in key' => [BuiltinKeyTool::class, 'must be an extension key'],
    'an empty key' => [fn () => new class extends Backups
    {
        public string $component = '';
    }, 'must be an extension key'],
]);

it('refuses two tool classes with the same key', function () {
    Larsadmin::tools([Backups::class]);

    Larsadmin::tools([OtherBackups::class]);
})->throws(InvalidArgumentException::class, 'Two tools use the uriKey [backups]');

it('lists only visible tools with their icon and URL', function () {
    Larsadmin::tools([Backups::class, SystemInfo::make()->canSee(fn (Request $request) => $request->user()->email === 'root@example.com')]);

    $this->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page->where('tools', [
        ['name' => 'Backups', 'uriKey' => 'backups', 'icon' => 'database', 'url' => '/admin/tools/backups'],
    ]));
});

it('sends no unknown icon to the client', function () {
    Larsadmin::tools([OtherBackups::class]);

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('tools.0.icon', null));
});

it('shares an empty menu with guests and no tools prop without tools', function () {
    $this->get('/admin')->assertInertia(fn (Assert $page) => $page->missing('tools'));

    Larsadmin::tools([Backups::class]);
    auth()->logout();

    $this->get('/admin/login')->assertInertia(fn (Assert $page) => $page->where('tools', []));
});

it('renders the tool page with its props', function () {
    Larsadmin::tools([Backups::class]);

    $this->get('/admin/tools/backups')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Tool', false)
        ->where('tool', ['name' => 'Backups', 'uriKey' => 'backups', 'component' => 'acme/backups'])
        ->where('props', ['disk' => 'local', 'user' => 'admin@example.com']));
});

it('sends empty props as a JSON object', function () {
    Larsadmin::tools([SystemInfo::class]);

    $this->get('/admin/tools/system-info', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Tool')
        ->assertSee('"props":{}', false);
});

it('answers 404 for an unknown or invisible tool', function () {
    Larsadmin::tools([SystemInfo::make()->canSee(fn () => false)]);

    $this->get('/admin/tools/system-info')->assertNotFound();
    $this->get('/admin/tools/missing')->assertNotFound();
});

it('requires the panel gate and a signed-in user', function () {
    Larsadmin::tools([Backups::class]);
    Larsadmin::auth(fn () => false);

    $this->get('/admin/tools/backups')->assertForbidden();

    auth()->logout();

    $this->get('/admin/tools/backups')->assertRedirect('/admin/login');
});
