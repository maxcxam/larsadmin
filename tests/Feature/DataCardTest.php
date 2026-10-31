<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Metrics\Card;
use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;
use Maxcxam\Larsadmin\Metrics\Metric;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Cards\ScopedTitles;
use Maxcxam\Larsadmin\Tests\Fixtures\Cards\StatusCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Cards\TopPosts;
use Maxcxam\Larsadmin\Tests\Fixtures\Cards\Welcome;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\ScopedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\CardsDashboard;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\MetricPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsValue;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\TestMetrics;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\UpdateOnlyPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

uses(InteractsWithLarsadmin::class);

const DATA_CARD_URL = '/admin/api/dashboards/cards-dashboard/metrics/';

beforeEach(function () {
    TopPosts::reset();
    StatusCard::$deny = false;
    TestMetrics::reset();
    TestMetrics::$source = Post::class;
    MetricPost::$cardList = [];
    config(['app.timezone' => 'UTC', 'larsadmin.timezone' => 'UTC', 'larsadmin.locale' => 'en', 'cache.default' => 'array']);

    Larsadmin::auth(fn () => true);
    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);

    foreach (['One' => 'live', 'Two' => 'draft', 'Three' => 'live'] as $title => $status) {
        Post::create(['title' => $title, 'status' => $status]);
    }
});

afterEach(function () {
    ScopedTitles::$entity = ScopedPost::class;
    TopPosts::reset();
    StatusCard::$deny = false;
    TestMetrics::reset();
    MetricPost::$cardList = [];
});

function dataCardDashboard(array $cards): CardsDashboard
{
    $dashboard = CardsDashboard::make();
    $dashboard->cardList = $cards;
    Larsadmin::dashboards([$dashboard]);

    return $dashboard;
}

it('serializes data cards with their endpoint and meta', function () {
    dataCardDashboard([TopPosts::make()->withMeta(['columns' => ['title']])->width('1/2')]);

    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('cards.0.component', 'acme/top-posts')
        ->where('cards.0.endpoint', DATA_CARD_URL.'top-posts')
        ->where('cards.0.width', '1/2')
        ->where('cards.0.columns', ['title'])
        ->missing('cards.0.ranges'));
});

it('answers with the data of the card', function () {
    dataCardDashboard([TopPosts::make()]);

    $this->getJson(DATA_CARD_URL.'top-posts?limit=2')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => 1, 'title' => 'One', 'user' => $this->user->id],
            ['id' => 2, 'title' => 'Two', 'user' => $this->user->id],
        ]]);
});

it('passes only the validated parameters and the request context to data()', function () {
    CarbonImmutable::setTestNow('2026-10-31 10:00:00');
    config(['larsadmin.timezone' => 'Europe/Kyiv']);
    dataCardDashboard([TopPosts::make()]);

    try {
        $this->getJson(DATA_CARD_URL.'top-posts?status=live&evil=1&sort=id')->assertOk()->assertJsonCount(2, 'data');
    } finally {
        CarbonImmutable::setTestNow();
    }

    $request = TopPosts::$lastRequest;

    expect($request)->toBeInstanceOf(CardRequest::class)
        ->and($request->parameters)->toBe(['status' => 'live'])
        ->and($request->get('evil'))->toBeNull()
        ->and($request->get('limit', 5))->toBe(5)
        ->and($request->owner)->toBe('dashboard:cards-dashboard')
        ->and($request->timezone)->toBe('Europe/Kyiv')
        ->and($request->now->getTimezone()->getName())->toBe('Europe/Kyiv')
        ->and($request->now->toIso8601String())->toBe('2026-10-31T12:00:00+02:00')
        ->and($request->user()?->is($this->user))->toBeTrue()
        ->and($request->request)->toBeInstanceOf(Request::class);
});

it('drops every parameter of a card without rules', function () {
    dataCardDashboard([StatusCard::make()]);

    $this->getJson(DATA_CARD_URL.'status-card?anything=1')
        ->assertOk()
        ->assertExactJson(['data' => ['ok' => true, 'ratio' => 1.0, 'empty' => [], 'owner' => 'dashboard:cards-dashboard', 'timezone' => 'UTC']]);
});

it('answers 422 for invalid parameters', function (string $query) {
    dataCardDashboard([TopPosts::make()]);

    $this->getJson(DATA_CARD_URL.'top-posts?'.$query)
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);

    expect(TopPosts::$calls)->toBe(0);
})->with([
    'not an integer' => ['limit=abc'],
    'too large' => ['limit=50'],
    'array' => ['limit[]=1'],
    'unknown status' => ['status=deleted'],
]);

it('answers 422 as JSON even without an Accept header', function () {
    dataCardDashboard([TopPosts::make()]);

    $this->get(DATA_CARD_URL.'top-posts?limit=abc')->assertStatus(422)->assertJsonValidationErrors('limit');
});

it('answers 404 for a static card, an invisible card or an invisible dashboard', function () {
    $dashboard = dataCardDashboard([Welcome::make(), TopPosts::make()->canSee(fn () => false)]);

    $this->getJson(DATA_CARD_URL.'welcome')->assertNotFound();
    $this->getJson(DATA_CARD_URL.'top-posts')->assertNotFound();
    $this->getJson(DATA_CARD_URL.'missing')->assertNotFound();

    $dashboard->canSee(fn () => false);
    $dashboard->cardList = [StatusCard::make()];

    $this->getJson(DATA_CARD_URL.'status-card')->assertNotFound();
});

it('answers 403 when data() denies access', function () {
    dataCardDashboard([StatusCard::make()]);
    StatusCard::$deny = true;

    $this->getJson(DATA_CARD_URL.'status-card')->assertForbidden();
});

it('serves data cards on entity indexes behind viewAny', function () {
    Larsadmin::entities([MetricPost::class]);
    MetricPost::$cardList = [StatusCard::make()];

    $this->getJson('/admin/api/entities/metric-posts/metrics/status-card')
        ->assertOk()
        ->assertJsonPath('data.owner', 'entity:metric-posts');

    Gate::policy(Post::class, UpdateOnlyPolicy::class);

    $this->getJson('/admin/api/entities/metric-posts/metrics/status-card')->assertForbidden();
});

it('requires the panel gate', function () {
    dataCardDashboard([StatusCard::make()]);
    Larsadmin::auth(fn () => false);

    $this->getJson(DATA_CARD_URL.'status-card')->assertForbidden();
});

it('keeps serving metrics from the same endpoint', function () {
    dataCardDashboard([PostsValue::make(), StatusCard::make()]);

    $this->getJson(DATA_CARD_URL.'posts-value?range=30')->assertOk()->assertJsonPath('result.type', 'value');
    $this->getJson(DATA_CARD_URL.'status-card')->assertOk()->assertJsonPath('data.ok', true);
});

it('does not cache by default', function () {
    dataCardDashboard([TopPosts::make()]);

    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();
    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    expect(TopPosts::$calls)->toBe(2);
});

it('caches the data per user and parameters', function () {
    TopPosts::$ttl = 60;
    dataCardDashboard([TopPosts::make()]);

    $first = $this->getJson(DATA_CARD_URL.'top-posts?limit=1')->assertOk()->json();
    $again = $this->getJson(DATA_CARD_URL.'top-posts?limit=1')->assertOk()->json();

    expect($again)->toBe($first)->and(TopPosts::$calls)->toBe(1);

    $this->getJson(DATA_CARD_URL.'top-posts?limit=2')->assertOk();

    expect(TopPosts::$calls)->toBe(2);

    $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secret']);
    $this->actingAs($other)->getJson(DATA_CARD_URL.'top-posts?limit=1')->assertOk()->assertJsonPath('data.0.user', $other->id);

    expect(TopPosts::$calls)->toBe(3);
});

it('returns the same JSON from the cache as without it', function () {
    dataCardDashboard([StatusCard::make()]);

    $plain = $this->getJson(DATA_CARD_URL.'status-card')->getContent();

    $cached = new class extends StatusCard
    {
        public function uriKey(): string
        {
            return 'status-card';
        }

        public function cacheFor(): int
        {
            return 60;
        }
    };
    dataCardDashboard([$cached]);

    $miss = $this->getJson(DATA_CARD_URL.'status-card')->getContent();
    $hit = $this->getJson(DATA_CARD_URL.'status-card')->getContent();

    expect($miss)->toBe($plain)->and($hit)->toBe($plain)->and($plain)->toContain('"empty":{}');
});

it('adds cacheKeyParts and the global scope to the cache key', function () {
    TopPosts::$ttl = 60;
    dataCardDashboard([TopPosts::make()]);

    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();
    TopPosts::$keyParts = ['tenant' => 2];
    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    expect(TopPosts::$calls)->toBe(2);

    $seen = null;
    Larsadmin::metricCacheScopeUsing(function (Request $request, $card) use (&$seen) {
        $seen = $card;

        return 'team-7';
    });
    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    expect(TopPosts::$calls)->toBe(3)->and($seen)->toBeInstanceOf(TopPosts::class);
});

it('never caches a data card when the global scope callback only accepts metrics', function () {
    TopPosts::$ttl = 60;
    dataCardDashboard([TopPosts::make()]);
    $calls = 0;
    Larsadmin::metricCacheScopeUsing(function (Request $request, Metric $metric) use (&$calls) {
        $calls++;

        return 'tenant';
    });

    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();
    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    expect(TopPosts::$calls)->toBe(2)
        ->and($calls)->toBe(0)
        ->and(Larsadmin::cacheScopeAccepts(TopPosts::make()))->toBeFalse()
        ->and(Larsadmin::metricCacheScope(Request::create('/'), PostsValue::make()))->toBe('tenant');
});

it('caches a data card when the global scope callback accepts it', function (Closure $callback) {
    TopPosts::$ttl = 60;
    dataCardDashboard([TopPosts::make()]);
    Larsadmin::metricCacheScopeUsing($callback()); // the dataset holds factories of the callbacks

    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();
    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    expect(TopPosts::$calls)->toBe(1)->and(Larsadmin::cacheScopeAccepts(TopPosts::make()))->toBeTrue();
})->with([
    'untyped' => [fn () => fn ($request, $card) => 'tenant'],
    'one parameter' => [fn () => fn (Request $request) => 'tenant'],
    'mixed' => [fn () => fn (Request $request, mixed $card) => 'tenant'],
    'object' => [fn () => fn (Request $request, object $card) => 'tenant'],
    'card' => [fn () => fn (Request $request, Card $card) => 'tenant'],
    'union' => [fn () => fn (Request $request, Metric|DataCard $card) => 'tenant'],
    'nullable data card' => [fn () => fn (Request $request, ?DataCard $card) => 'tenant'],
    'intersection' => [fn () => fn (Request $request, Card&JsonSerializable $card) => 'tenant'],
]);

it('does not cache for scope callbacks with other types', function (Closure $callback) {
    Larsadmin::metricCacheScopeUsing($callback());

    expect(Larsadmin::cacheScopeAccepts(TopPosts::make()))->toBeFalse();
})->with([
    'string' => [fn () => fn (Request $request, string $card) => 'tenant'],
    'other class' => [fn () => fn (Request $request, User $card) => 'tenant'],
    'union of others' => [fn () => fn (Request $request, Metric|string $card) => 'tenant'],
    'intersection' => [fn () => fn (Request $request, Metric&JsonSerializable $card) => 'tenant'],
]);

it('stores data cards in the metrics cache store with their own prefix', function () {
    TopPosts::$ttl = 60;
    config(['larsadmin.metrics.cache_store' => 'array']);
    dataCardDashboard([TopPosts::make()]);

    Cache::store('array')->flush();
    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    $store = Cache::store('array')->getStore();
    $keys = array_keys((fn () => $this->storage)->call($store));

    // The rate limiter shares the store; the card wrote exactly one entry.
    expect(array_filter($keys, fn (string $key) => str_starts_with($key, 'larsadmin:card:')))->toHaveCount(1)
        ->and(array_filter($keys, fn (string $key) => str_starts_with($key, 'larsadmin:metric:')))->toBe([]);
});

it('ignores a zero or negative TTL', function () {
    TopPosts::$ttl = 0;
    dataCardDashboard([TopPosts::make()]);

    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();
    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    expect(TopPosts::$calls)->toBe(2);
});

it('re-checks canSee on a cache hit', function () {
    TopPosts::$ttl = 60;
    $visible = true;
    dataCardDashboard([TopPosts::make()->canSee(function () use (&$visible) {
        return $visible;
    })]);

    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();
    $visible = false;

    $this->getJson(DATA_CARD_URL.'top-posts')->assertNotFound();
});

it('runs one query per data request', function () {
    dataCardDashboard([TopPosts::make()]);

    DB::enableQueryLog();
    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    // The session user and the card's own query.
    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], '"posts"')))->toHaveCount(1);
});

it('scopes data to the entity index query with entityQuery()', function (string $entity) {
    Larsadmin::entities([ScopedPost::class]);
    ScopedTitles::$entity = $entity;
    Post::create(['title' => 'Alive', 'status' => 'live']);
    Post::create(['title' => 'Draft B', 'status' => 'draft']);
    Post::create(['title' => 'Another draft', 'status' => 'draft']);
    dataCardDashboard([ScopedTitles::make()]);

    // The constraint's "or" stays inside its group: drafts never leak.
    $this->getJson(DATA_CARD_URL.'scoped-titles')->assertOk()->assertExactJson(['data' => ['Alive']]);
})->with([
    'entity class' => [ScopedPost::class],
    'uri key' => ['scoped-posts'],
]);

it('answers 403 when entityQuery() finds no viewAny', function () {
    Larsadmin::entities([ScopedPost::class]);
    ScopedTitles::$entity = ScopedPost::class;
    Gate::policy(Post::class, UpdateOnlyPolicy::class);
    dataCardDashboard([ScopedTitles::make()]);

    $this->getJson(DATA_CARD_URL.'scoped-titles')->assertForbidden();
});

it('accepts only registered entities in entityQuery()', function (string $entity) {
    Larsadmin::entities([MetricPost::class]);
    ScopedTitles::$entity = $entity;

    $this->cardData(ScopedTitles::make());
})->with([
    'a model' => [Post::class],
    'an unregistered entity class' => [ScopedPost::class],
    'an unregistered key' => ['scoped-posts'],
    'an unknown class' => ['App\Nope\Missing'],
])->throws(InvalidArgumentException::class, 'not a registered entity');

it('checks viewAny of the queried entities again on a cache hit', function () {
    Larsadmin::entities([ScopedPost::class]);
    ScopedTitles::$entity = ScopedPost::class;
    Post::create(['title' => 'Alive', 'status' => 'live']);

    $card = new class extends ScopedTitles
    {
        public function uriKey(): string
        {
            return 'scoped-titles';
        }

        public function cacheFor(): int
        {
            return 60;
        }
    };
    dataCardDashboard([$card]);

    $this->getJson(DATA_CARD_URL.'scoped-titles')->assertOk()->assertExactJson(['data' => ['Alive']]);
    $this->getJson(DATA_CARD_URL.'scoped-titles')->assertOk();

    Gate::policy(Post::class, UpdateOnlyPolicy::class);

    // The cached entry is not served once viewAny is revoked.
    $this->getJson(DATA_CARD_URL.'scoped-titles')->assertForbidden();
});

it('recomputes cache entries stored without their authorized entities', function () {
    TopPosts::$ttl = 60;
    config(['larsadmin.metrics.cache_store' => 'array']);
    dataCardDashboard([TopPosts::make()]);

    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk();

    $store = Cache::store('array');
    $keys = array_filter(array_keys((fn () => $this->storage)->call($store->getStore())), fn (string $key) => str_starts_with($key, 'larsadmin:card:'));
    $key = reset($keys);
    $store->put($key, ['json' => '"stale"'], 60);

    $this->getJson(DATA_CARD_URL.'top-posts')->assertOk()->assertJsonCount(3, 'data');

    expect(TopPosts::$calls)->toBe(2);
});
