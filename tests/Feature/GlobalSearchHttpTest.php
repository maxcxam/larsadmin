<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\DenyBeforePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Search;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Search\SearchablePost::$indexUsing = null;
    Search\SearchablePost::$globalSearchLimit = null;

    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Search\SearchablePost::class, Search\HiddenTag::class]);

    $this->searcher = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
});

function searchJson(mixed $search = null, array $query = [])
{
    $query = $search === null ? $query : ['search' => $search] + $query;

    return test()->getJson('/admin/api/search?'.http_build_query($query), ['X-Requested-With' => 'XMLHttpRequest']);
}

it('answers the search as JSON with no-store caching', function () {
    $post = Post::create(['title' => 'Searchable post', 'status' => 'live']);

    $this->actingAs($this->searcher);

    searchJson('searchable')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'search' => 'searchable',
            'groups' => [[
                'entity' => 'posts',
                'label' => 'Posts',
                'results' => [[
                    'id' => $post->id,
                    'title' => 'Searchable post',
                    'subtitle' => null,
                    'url' => '/admin/entities/posts/'.$post->id,
                ]],
                'more' => false,
                'indexUrl' => '/admin/entities/posts?search=searchable',
            ]],
            'failed' => [],
            'partial' => false,
        ]);
});

it('has the route name larsadmin.api.search', function () {
    expect(route('larsadmin.api.search', ['search' => 'x'], false))->toBe('/admin/api/search?search=x');
});

it('rejects a missing, empty, array or too long search with 422 (AC-11)', function (array $query) {
    $this->actingAs($this->searcher);

    $this->getJson('/admin/api/search?'.http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search')
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'missing' => [[]],
    'empty' => [['search' => '']],
    'array' => [['search' => ['x']]],
    'too long' => [['search' => str_repeat('a', 101)]],
]);

it('accepts 100 characters', function () {
    $this->actingAs($this->searcher);

    searchJson(str_repeat('a', 100))->assertOk()->assertJsonPath('search', str_repeat('a', 100));
});

it('answers a too short term with empty groups and no database query, but searches one digit (AC-11)', function () {
    $this->actingAs($this->searcher);
    $post = Post::create(['title' => 'Seven', 'status' => 'live']);

    DB::enableQueryLog();
    $before = count(DB::getQueryLog());

    searchJson('s')->assertOk()->assertExactJson(['search' => 's', 'groups' => [], 'failed' => [], 'partial' => false]);

    $queries = collect(DB::getQueryLog())->slice($before)->pluck('query')
        ->filter(fn (string $sql) => str_contains($sql, '"posts"'));

    expect($queries->all())->toBe([]);

    searchJson((string) $post->id)->assertOk()->assertJsonPath('groups.0.results.0.title', 'Seven');
});

it('answers 200 with no groups when no opt-in entity is reachable, not 403', function () {
    Gate::policy(Post::class, DenyBeforePolicy::class);
    Post::create(['title' => 'Searchable post', 'status' => 'live']);

    $this->actingAs($this->searcher);

    searchJson('searchable')->assertOk()->assertJsonPath('groups', []);
});

it('returns 401 to guests (AC-11)', function () {
    searchJson('searchable')->assertUnauthorized();
});

it('returns 403 without panel access (AC-11)', function () {
    Larsadmin::auth(fn () => false);
    $this->actingAs($this->searcher);

    searchJson('searchable')->assertForbidden();
});

it('throttles the search per user (AC-11)', function () {
    config(['larsadmin.search.throttle' => 3]);
    $this->actingAs($this->searcher);

    foreach (range(1, 3) as $i) {
        searchJson('ab')->assertOk();
    }

    searchJson('ab')->assertTooManyRequests()->assertHeader('Retry-After');

    // Another user has their own budget.
    $this->actingAs(User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secret']));

    searchJson('ab')->assertOk();
});

it('throttles at 60 per minute by default and not at all when null', function () {
    $this->actingAs($this->searcher);

    foreach (range(1, 60) as $i) {
        searchJson('ab')->assertOk();
    }

    searchJson('ab')->assertTooManyRequests();

    config(['larsadmin.search.throttle' => null]);

    searchJson('ab')->assertOk();
});

it('shares the search settings with the pages when an opt-in entity is reachable', function () {
    config(['larsadmin.search.min_length' => 3]);

    $this->actingAs($this->searcher)
        ->get('/admin')
        ->assertInertia(fn (Assert $page) => $page
            ->where('larsadmin.search', ['minLength' => 3, 'maxLength' => 100]));
});

it('shares a null search prop without opt-in entities or viewAny', function (Closure $setup) {
    $setup();

    $this->actingAs($this->searcher)
        ->get('/admin')
        ->assertInertia(fn (Assert $page) => $page->where('larsadmin.search', null));
})->with([
    'no opt-in entity' => [function () {
        Larsadmin::flushState();
        Larsadmin::auth(fn () => true);
        Larsadmin::entities([Search\HiddenTag::class]);
    }],
    'viewAny denied' => [fn () => Gate::policy(Post::class, DenyBeforePolicy::class)],
]);

it('shares a null search prop on the login page', function () {
    $this->get('/admin/login')
        ->assertInertia(fn (Assert $page) => $page->where('larsadmin.search', null));
});

it('evaluates the search prop lazily: a partial reload of another prop skips it', function () {
    $this->actingAs($this->searcher);

    $version = $this->get('/admin')->viewData('page')['version'];

    // A model with a policy: the prop would ask the gate for viewAny if it were evaluated.
    $checks = 0;
    Gate::before(function () use (&$checks) {
        $checks++;
    });
    Gate::policy(Post::class, DenyBeforePolicy::class);

    $this->get('/admin', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => 'toast',
    ])->assertOk()->assertJsonMissingPath('props.larsadmin');

    expect($checks)->toBe(0);
});
