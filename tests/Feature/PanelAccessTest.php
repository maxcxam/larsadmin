<?php

use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;
use Maxcxam\Larsadmin\LarsadminServiceProvider;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

function admin(): User
{
    return User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'secret',
    ]);
}

it('redirects guests to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('renders the login page', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('larsadmin.path', '/admin')
            ->where('user', null));
});

it('logs in an authorized user with valid credentials', function () {
    Larsadmin::auth(fn ($request) => $request->user()?->email === 'admin@example.com');
    admin();

    $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'secret'])
        ->assertRedirect('/admin');

    $this->assertAuthenticated();
});

it('does not authenticate a user the panel rejects, with the same error as bad credentials', function () {
    Larsadmin::auth(fn () => false);
    admin();

    $unauthorized = $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'secret']);
    $unauthorized->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();

    $wrong = $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
    $wrong->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();
});

it('rate limits login attempts per email and ip', function () {
    admin();

    foreach (range(1, 5) as $i) {
        $this->post('/admin/login', ['email' => 'Admin@Example.com', 'password' => 'wrong'])->assertStatus(302);
    }

    // Case-insensitive email key.
    $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong'])->assertStatus(429);

    // Another email is not affected by the first one's counter.
    $this->post('/admin/login', ['email' => 'other@example.com', 'password' => 'wrong'])->assertStatus(302);
});

it('rate limits login attempts per ip across emails', function () {
    foreach (range(1, 30) as $i) {
        $this->post('/admin/login', ['email' => "user{$i}@example.com", 'password' => 'wrong'])->assertStatus(302);
    }

    $this->post('/admin/login', ['email' => 'another@example.com', 'password' => 'wrong'])->assertStatus(429);
});

it('only trusts the local environment when allow_local is enabled', function () {
    app()->detectEnvironment(fn () => 'local');
    $request = Request::create('/admin');

    config(['larsadmin.allow_local' => true]);
    expect(Larsadmin::check($request))->toBeTrue();

    config(['larsadmin.allow_local' => false]);
    expect(Larsadmin::check($request))->toBeFalse();
});

it('enforces the viewLarsadmin gate in the local environment when allow_local is disabled', function () {
    app()->detectEnvironment(fn () => 'local');
    (new LarsadminApplicationServiceProvider(app()))->boot();
    $request = Request::create('/admin');
    $request->setUserResolver(fn () => admin());

    config(['larsadmin.allow_local' => true]);
    expect(Larsadmin::check($request))->toBeTrue();

    config(['larsadmin.allow_local' => false]);
    expect(Larsadmin::check($request))->toBeFalse();
});

it('rejects invalid credentials', function () {
    admin();

    $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('shows the dashboard to authorized users', function () {
    Larsadmin::auth(fn () => true);

    $this->actingAs(admin())
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('user.email', 'admin@example.com'));
});

it('forbids users that fail the authorization callback', function () {
    Larsadmin::auth(fn () => false);

    $this->actingAs(admin())->get('/admin')->assertForbidden();
});

it('denies access outside the local environment by default', function () {
    $this->actingAs(admin())->get('/admin')->assertForbidden();
});

it('logs the user out', function () {
    Larsadmin::auth(fn () => true);

    $this->actingAs(admin())
        ->post('/admin/logout')
        ->assertRedirect('/admin/login');

    $this->assertGuest();
});

it('serves the panel from a configurable path', function () {
    config(['larsadmin.path' => 'backoffice']);

    app('router')->setRoutes(new RouteCollection);
    $this->app->register(LarsadminServiceProvider::class, force: true);

    $this->get('/backoffice')->assertRedirect('/backoffice/login');
});
