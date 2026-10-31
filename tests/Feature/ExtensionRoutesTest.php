<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminServiceProvider;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\ScopedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\NoUpdatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);

    Larsadmin::routes(function () {
        Route::get('acme/ping', fn () => ['pong' => true])->name('acme.ping');
        Route::post('acme/posts/{id}/like', fn (Request $request, string $id) => [
            'liked' => Larsadmin::findRecord($request, 'posts', $id, 'update')->getKey(),
        ])->name('acme.like');
        Route::get('acme/scoped/{id}', fn (Request $request, string $id) => [
            'id' => Larsadmin::findRecord($request, 'scoped-posts', $id)->getKey(),
        ])->name('acme.scoped');
    });

    LarsadminServiceProvider::registerExtensionRoutes();
});

it('registers extension routes under the panel path with the larsadmin.ext. prefix', function () {
    expect(route('larsadmin.ext.acme.ping', [], false))->toBe('/admin/ext/acme/ping')
        ->and(route('larsadmin.ext.acme.like', 5, false))->toBe('/admin/ext/acme/posts/5/like');

    $this->actingAs($this->user)->getJson('/admin/ext/acme/ping')->assertOk()->assertExactJson(['pong' => true]);
});

it('puts extension routes behind the panel middleware and the gate', function () {
    $middleware = Route::getRoutes()->getByName('larsadmin.ext.acme.ping')->gatherMiddleware();

    expect($middleware)->toBe([...config('larsadmin.middleware'), ...config('larsadmin.auth_middleware')])
        ->and($middleware)->toContain('web'); // CSRF for POST routes comes with the web group
});

it('redirects guests and refuses users without the gate', function () {
    $this->get('/admin/ext/acme/ping')->assertRedirect('/admin/login');
    $this->getJson('/admin/ext/acme/ping')->assertUnauthorized();

    Larsadmin::auth(fn () => false);

    $this->actingAs($this->user)->getJson('/admin/ext/acme/ping')->assertForbidden();
});

it('authorizes records with findRecord', function () {
    Larsadmin::entities([PostEntity::class, ScopedPost::class]);
    $live = Post::create(['title' => 'Live', 'status' => 'live']);
    $draft = Post::create(['title' => 'Draft', 'status' => 'draft']);
    $this->actingAs($this->user);

    $this->postJson("/admin/ext/acme/posts/{$live->id}/like")->assertOk()->assertExactJson(['liked' => $live->id]);
    $this->getJson("/admin/ext/acme/scoped/{$draft->id}")->assertNotFound();
    $this->postJson('/admin/ext/acme/posts/999/like')->assertNotFound();

    Gate::policy(Post::class, NoUpdatePolicy::class);

    $this->postJson("/admin/ext/acme/posts/{$live->id}/like")->assertForbidden();
});

it('registers nothing without callbacks', function () {
    Larsadmin::flushState();
    $count = count(Route::getRoutes()->getRoutes());

    LarsadminServiceProvider::registerExtensionRoutes();

    expect(count(Route::getRoutes()->getRoutes()))->toBe($count);
});

it('registers the routes of extension providers once the application booted', function () {
    $this->app->booted(fn () => null); // the provider's callback already ran in setUp(): nothing registered then

    expect(Route::getRoutes()->getByName('larsadmin.ext.acme.ping'))->not->toBeNull();
});

it('registers the same callback once, however often routes() and the registration run', function () {
    $callback = Larsadmin::extensionRoutes()[0];
    $count = count(Route::getRoutes()->getRoutes());

    Larsadmin::routes($callback);
    LarsadminServiceProvider::registerExtensionRoutes();
    LarsadminServiceProvider::registerExtensionRoutes();

    expect(Larsadmin::extensionRoutes())->toHaveCount(1)
        ->and(count(Route::getRoutes()->getRoutes()))->toBe($count);
});

it('registers only the callbacks added since the last registration', function () {
    $count = count(Route::getRoutes()->getRoutes());

    Larsadmin::routes(fn () => Route::get('acme/later', fn () => ['later' => true])->name('acme.later'));
    LarsadminServiceProvider::registerExtensionRoutes();

    expect(count(Route::getRoutes()->getRoutes()))->toBe($count + 1)
        ->and(route('larsadmin.ext.acme.later', [], false))->toBe('/admin/ext/acme/later');

    $this->actingAs($this->user)->getJson('/admin/ext/acme/later')->assertOk()->assertExactJson(['later' => true]);
});
