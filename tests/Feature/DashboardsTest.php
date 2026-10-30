<?php

use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Dashboards\Dashboard;
use Maxcxam\Larsadmin\Dashboards\Main;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\CardsDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsValue;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\SalesDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    app()->setLocale('en');
    config(['larsadmin.locale' => 'en']);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

it('registers the empty Main dashboard when nothing was registered', function () {
    expect(Larsadmin::registeredDashboards())->toHaveCount(1)
        ->and(Larsadmin::registeredDashboards()[0])->toBeInstanceOf(Main::class)
        ->and(Larsadmin::dashboardForKey('main'))->toBeInstanceOf(Main::class)
        ->and(Larsadmin::dashboardForKey('nope'))->toBeNull()
        ->and((new Main)->uriKey())->toBe('main')
        ->and((new Main)->name())->toBe('Dashboard')
        ->and((new Main)->cards(Request::create('/')))->toBe([]);
});

it('renders the home dashboard at the panel root, keeping a page without cards', function () {
    $this->get('/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('dashboard', ['uriKey' => 'main', 'name' => 'Dashboard'])
            ->where('cards', [])
            ->where('user.email', 'admin@example.com'));
});

it('derives dashboard names and keys from the class', function () {
    expect(SalesDashboard::make()->uriKey())->toBe('sales-dashboard')
        ->and(SalesDashboard::make()->name())->toBe('Sales Dashboard')
        ->and(SalesDashboard::make()->displayInNavigation)->toBeTrue();
});

it('serves several dashboards and the first visible one is the home', function () {
    $sales = SalesDashboard::make();
    $sales->cardList = [PostsValue::make()->width('full')];

    Larsadmin::dashboards([CardsDashboard::make()->canSee(fn () => false), $sales]);

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.uriKey', 'sales-dashboard')
        ->has('cards', 1)
        ->where('cards.0', fn ($card) => $card['component'] === 'value-metric'
            && $card['uriKey'] === 'posts-value'
            && $card['width'] === 'full'
            && $card['endpoint'] === '/admin/api/dashboards/sales-dashboard/metrics/posts-value'
            && $card['defaultRange'] === 30
            && $card['ranges'][0] === ['value' => 30, 'label' => '30 days']
            && $card['helpText'] === null));

    $this->get('/admin/dashboards/sales-dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->where('dashboard.name', 'Sales Dashboard')->has('cards', 1));
});

it('hides invisible dashboards and cards from the page with 404', function () {
    Larsadmin::dashboards([SalesDashboard::make()->canSee(fn () => false), CardsDashboard::make()]);

    $this->get('/admin/dashboards/sales-dashboard')->assertNotFound();
    $this->get('/admin/dashboards/unknown')->assertNotFound();
    $this->get('/admin/dashboards/cards-dashboard')->assertOk();
});

it('omits invisible cards from the dashboard props', function () {
    $dashboard = CardsDashboard::make();
    $dashboard->cardList = [PostsValue::make()->canSee(fn () => false)];
    Larsadmin::dashboards([$dashboard]);

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page->where('cards', []));
});

it('renders a page without a dashboard when none is visible', function () {
    Larsadmin::dashboards([Main::make()->canSee(fn () => false)]);

    $this->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('dashboard', null)
        ->where('cards', [])
        ->where('dashboards', []));
});

it('shares the visible dashboards for the sidebar', function () {
    Larsadmin::dashboards([
        Main::make(),
        SalesDashboard::make(),
        CardsDashboard::make()->canSee(fn () => false),
        new class extends Dashboard
        {
            public bool $displayInNavigation = false;

            public function cards(Request $request): array
            {
                return [];
            }
        },
    ]);

    $this->get('/admin/dashboards/sales-dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('dashboards', [
            ['uriKey' => 'main', 'name' => 'Dashboard', 'url' => '/admin'],
            ['uriKey' => 'sales-dashboard', 'name' => 'Sales Dashboard', 'url' => '/admin/dashboards/sales-dashboard'],
        ]));
});

it('points the sidebar entry of the home dashboard at the panel root', function () {
    Larsadmin::dashboards([CardsDashboard::make()->canSee(fn () => false), SalesDashboard::make()]);

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('dashboards', [['uriKey' => 'sales-dashboard', 'name' => 'Sales Dashboard', 'url' => '/admin']]));
});

it('does not share dashboards with guests on the login page', function () {
    auth()->forgetGuards();

    $this->get('/admin/login')->assertInertia(fn (Assert $page) => $page->where('dashboards', []));
});

it('forbids the dashboard pages to users failing the panel authorization', function () {
    Larsadmin::auth(fn () => false);

    $this->get('/admin')->assertForbidden();
    $this->get('/admin/dashboards/main')->assertForbidden();
});

it('redirects guests to the login page', function () {
    auth()->forgetGuards();

    $this->get('/admin/dashboards/main')->assertRedirect(route('larsadmin.login'));
});

it('registers the dashboards of the application service provider', function () {
    $provider = new class(app()) extends LarsadminApplicationServiceProvider
    {
        protected function dashboards(): array
        {
            return [SalesDashboard::make(), Main::make()];
        }
    };

    $provider->boot();

    expect(collect(Larsadmin::registeredDashboards())->map->uriKey()->all())->toBe(['sales-dashboard', 'main']);

    $default = new class(app()) extends LarsadminApplicationServiceProvider {};
    $default->boot();

    expect(collect(Larsadmin::registeredDashboards())->map->uriKey()->all())->toBe(['main']);
});

it('resets dashboards and the timezone callback with flushState', function () {
    Larsadmin::dashboards([SalesDashboard::make()]);
    Larsadmin::timezoneUsing(fn () => 'Asia/Tokyo');

    Larsadmin::flushState();

    expect(collect(Larsadmin::registeredDashboards())->map->uriKey()->all())->toBe(['main'])
        ->and(Larsadmin::timezone(Request::create('/')))->toBe(config('app.timezone'));
});

it('instantiates dashboards registered as class strings', function () {
    Larsadmin::dashboards([SalesDashboard::class, Main::make()]);

    expect(Larsadmin::registeredDashboards()[0])->toBeInstanceOf(SalesDashboard::class)
        ->and(collect(Larsadmin::registeredDashboards())->map->uriKey()->all())->toBe(['sales-dashboard', 'main']);

    $this->get('/admin/dashboards/sales-dashboard')->assertOk();
});

it('rejects non dashboards passed to the registry', function (mixed $dashboard) {
    Larsadmin::dashboards([SalesDashboard::make(), $dashboard]);
})->with([
    'unknown class string' => ['nope'],
    'other class string' => [stdClass::class],
    'object' => [new stdClass],
])->throws(InvalidArgumentException::class);

it('rejects two dashboards with the same uriKey', function () {
    Larsadmin::dashboards([SalesDashboard::make(), SalesDashboard::class]);
})->throws(InvalidArgumentException::class, 'sales-dashboard');

it('ships the metrics config defaults', function () {
    expect(config('larsadmin.metrics.cache_store'))->toBeNull()
        ->and(config('larsadmin.metrics.throttle'))->toBe(120)
        ->and(config('larsadmin.timezone'))->toBeNull();
});
