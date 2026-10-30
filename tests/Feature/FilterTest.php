<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Maxcxam\Larsadmin\Tests\Fixtures\UserPolicy;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Entities\FilterPost::class, Entities\FilterUser::class]);
    Entities\FilterPost::$seeHidden = false;
    UserPolicy::$viewAny = true;
    UserPolicy::$view = true;

    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
    $this->actingAs($this->owner);

    foreach ([
        ['Alpha', 'draft', false, 10, null],
        ['Bravo', 'live', true, 20, '2026-01-10 15:30:00'],
        ['Charlie', 'live', true, 10, '2026-02-15 00:00:00'],
        ['Delta', 'draft', true, 20, '2026-03-20 23:59:59'],
    ] as [$title, $status, $published, $views, $at]) {
        Post::create([
            'title' => $title, 'status' => $status, 'published' => $published,
            'views' => $views, 'published_at' => $at, 'author_id' => $this->owner->id,
        ]);
    }
});

function fIndex(string $query = ''): TestResponse
{
    return test()->get('/admin/entities/posts'.($query ? "?{$query}" : ''));
}

function fTitles(TestResponse $response): array
{
    $titles = [];
    $response->assertInertia(function (Assert $page) use (&$titles) {
        $titles = collect($page->toArray()['props']['records']['data'])->pluck('title')->sort()->values()->all();
    });

    return $titles;
}

it('exposes filter definitions and an empty query.filters object', function () {
    $response = fIndex();

    $response->assertInertia(fn (Assert $page) => $page
        ->has('filters', 5)
        ->where('filters.0', [
            'key' => 'status', 'label' => 'Status', 'component' => 'select-filter',
            'options' => [['value' => 'draft', 'label' => 'Draft'], ['value' => 'live', 'label' => 'Live']],
            'value' => null, 'default' => null,
        ])
        ->where('filters.1.component', 'boolean-filter')
        ->where('filters.1.options', [['value' => '1', 'label' => 'Так'], ['value' => '0', 'label' => 'Ні']])
        ->where('filters.2.key', 'published-range')
        ->where('filters.2.component', 'date-range-filter')
        ->missing('filters.2.options')
        ->where('filters.3.options', [['value' => '10', 'label' => 'Ten'], ['value' => '20', 'label' => 'Twenty']]));

    expect($response->viewData('page')['props']['query']['filters'])->toEqual((object) []);
    expect(json_encode($response->viewData('page')['props']['query']))->toContain('"filters":{}');
});

it('applies a select filter and echoes it', function () {
    $response = fIndex('filters[status]=live');

    expect(fTitles($response))->toBe(['Bravo', 'Charlie']);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('query.filters', ['status' => 'live'])
        ->where('filters.0.value', 'live'));
});

it('applies closure options and keeps numeric keys as strings', function () {
    $response = fIndex('filters[v]=20');

    expect(fTitles($response))->toBe(['Bravo', 'Delta']);
    $response->assertInertia(fn (Assert $page) => $page->where('query.filters', ['v' => '20']));
});

it('applies a boolean filter for 1 and 0', function () {
    expect(fTitles(fIndex('filters[published]=1')))->toBe(['Bravo', 'Charlie', 'Delta'])
        ->and(fTitles(fIndex('filters[published]=0')))->toBe(['Alpha']);
});

it('applies a date range inclusively with either bound', function () {
    expect(fTitles(fIndex('filters[published-range][from]=2026-01-10&filters[published-range][to]=2026-02-15')))
        ->toBe(['Bravo', 'Charlie'])
        ->and(fTitles(fIndex('filters[published-range][from]=2026-03-20')))->toBe(['Delta'])
        ->and(fTitles(fIndex('filters[published-range][to]=2026-01-10')))->toBe(['Bravo']);

    fIndex('filters[published-range][from]=2026-03-20')->assertInertia(fn (Assert $page) => $page
        ->where('query.filters', ['published-range' => ['from' => '2026-03-20', 'to' => null]]));
});

it('combines filters with search, sort and pagination', function () {
    fIndex('filters[status]=live&filters[published]=1&search=a&sort=title&direction=asc&perPage=2&page=1')
        ->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 2)
            ->where('records.data.0.title', 'Bravo')
            ->where('records.data.1.title', 'Charlie')
            ->where('query.sort', 'title'));

    fIndex('filters[published]=1&sort=title&direction=asc&perPage=2&page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('records.total', 3)
            ->has('records.data', 1)
            ->where('records.data.0.title', 'Delta')
            ->where('records.next_page_url', null)
            ->where('records.prev_page_url', fn ($url) => str_contains(urldecode($url), 'filters[published]=1')));
});

it('survives a page beyond the last one', function () {
    fIndex('filters[status]=live&page=99')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('records.total', 2)->has('records.data', 0));
});

it('round-trips the filters through the pagination links', function () {
    fIndex('filters[status]=draft&filters[published-range][to]=2026-12-31&perPage=2')
        ->assertInertia(fn (Assert $page) => $page
            ->where('records.first_page_url', fn ($url) => str_contains(urldecode($url), 'filters[status]=draft')
                && str_contains(urldecode($url), 'filters[published-range][to]=2026-12-31')));
});

it('ignores invalid, unknown, array and malformed filter params without erroring', function (string $query) {
    $response = fIndex($query);

    $response->assertOk();
    expect(fTitles($response))->toBe(['Alpha', 'Bravo', 'Charlie', 'Delta']);
    $response->assertInertia(fn (Assert $page) => $page->where('query.filters', []));
})->with([
    'select not in options' => 'filters[status]=archived',
    'select array' => 'filters[status][]=live',
    'select nested' => 'filters[status][a][b]=live',
    'boolean junk' => 'filters[published]=yes',
    'boolean array' => 'filters[published][]=1',
    'date junk' => 'filters[published-range][from]=tomorrow',
    'date impossible' => 'filters[published-range][from]=2026-02-30&filters[published-range][to]=2026-13-01',
    'date wrong format' => 'filters[published-range][from]=10.01.2026',
    'date scalar' => 'filters[published-range]=2026-01-10',
    'date nested arrays' => 'filters[published-range][from][]=2026-01-10',
    'unknown key' => 'filters[nope]=1',
    'filters scalar' => 'filters=live',
    'empty value' => 'filters[status]=',
    'oversized value' => 'filters[status]=x',
]);

it('ignores an oversized value', function () {
    expect(fTitles(fIndex('filters[status]='.str_repeat('a', 500))))->toHaveCount(4);
});

it('keeps valid filters when others are invalid', function () {
    $response = fIndex('filters[status]=live&filters[published]=maybe&filters[nope]=1');

    expect(fTitles($response))->toBe(['Bravo', 'Charlie']);
    $response->assertInertia(fn (Assert $page) => $page->where('query.filters', ['status' => 'live']));
});

it('hides filters the user cannot see and ignores their params', function () {
    fIndex()->assertInertia(fn (Assert $page) => $page->has('filters', 5)
        ->where('filters.0.key', 'status')->where('filters.3.key', 'v')->where('filters.4.key', 'escape'));

    expect(fTitles(fIndex('filters[hidden]=live')))->toHaveCount(4);

    Entities\FilterPost::$seeHidden = true;
    fIndex('filters[hidden]=live')->assertInertia(fn (Assert $page) => $page
        ->has('filters', 6)->where('query.filters', ['hidden' => 'live']));
});

it('cannot escape the row scope with an orWhere filter', function () {
    expect(fTitles(fIndex('filters[escape]=1&filters[published]=0')))->toBe([]);
});

it('applies filters to related panels and echoes them', function () {
    $get = fn (string $q) => test()->getJson("/admin/api/entities/users/{$this->owner->id}/related/posts?{$q}", ['X-Requested-With' => 'XMLHttpRequest']);

    $other = User::create(['name' => 'Other', 'email' => 'o@example.com', 'password' => 'secret']);
    Post::create(['title' => 'Foreign', 'status' => 'draft', 'author_id' => $other->id]);

    $get('filters[status]=draft')->assertOk()
        ->assertJsonPath('records.total', 2)
        ->assertJsonPath('query.filters', ['status' => 'draft'])
        ->assertJsonPath('filters.0.key', 'status');
    $get('filters[status]=bogus&filters[published]=x')->assertOk()->assertJsonPath('records.total', 4);
    $get('')->assertJsonPath('query.filters', []);
});

it('applies a default only when the filter is absent from the query string', function () {
    Larsadmin::entities([Entities\DefaultedPost::class]);

    $url = '/admin/entities/defaulted-posts';

    test()->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('filters.0.default', 'live')->where('filters.0.value', 'live')
        ->where('query.filters', ['status' => 'live'])
        ->where('records.total', 2));

    test()->get($url.'?filters[status]=draft')->assertInertia(fn (Assert $page) => $page
        ->where('records.total', 2)->where('filters.0.value', 'draft'));
    test()->get($url.'?filters[status]=')->assertInertia(fn (Assert $page) => $page
        ->where('records.total', 4)->where('filters.0.value', null));
});

it('generates a filter class', function () {
    $path = app_path('Larsadmin/Filters/RecentFilter.php');
    File::delete($path);

    Artisan::call('larsadmin:filter', ['name' => 'RecentFilter']);

    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))
        ->toContain('namespace App\Larsadmin\Filters;')
        ->toContain('class RecentFilter extends Filter')
        ->toContain('public function apply(Request $request, Builder $query, mixed $value): Builder');

    File::delete($path);
});

it('only overwrites an existing filter class with --force', function () {
    $path = app_path('Larsadmin/Filters/RecentFilter.php');
    File::delete($path);

    Artisan::call('larsadmin:filter', ['name' => 'RecentFilter']);
    File::put($path, 'custom');

    Artisan::call('larsadmin:filter', ['name' => 'RecentFilter']);
    expect(File::get($path))->toBe('custom');

    Artisan::call('larsadmin:filter', ['name' => 'RecentFilter', '--force' => true]);
    expect(File::get($path))->toContain('class RecentFilter extends Filter');

    File::delete($path);
});
