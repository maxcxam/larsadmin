<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Search\GlobalSearch;
use Maxcxam\Larsadmin\Tests\Fixtures\DenyBeforePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Note;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Search;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\UpdateOnlyPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Search\SearchablePost::$indexUsing = null;
    Search\SearchablePost::$globalSearchLimit = null;
    Search\SecretPost::$seeBody = false;
    Search\SecretPost::$seeTitle = true;

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->admin);
});

/**
 * One search per request, as over HTTP (field prototypes and canSee are cached per request).
 */
function globalSearch(string $term): array
{
    return app(GlobalSearch::class)->run(Request::create('/admin/api/search'), $term);
}

/**
 * @return array<string, list<string>> titles per entity uriKey
 */
function searchTitles(string $term): array
{
    return collect(globalSearch($term)['groups'])
        ->mapWithKeys(fn (array $group) => [$group['entity'] => array_column($group['results'], 'title')])
        ->all();
}

it('returns groups of matching records in the documented shape', function () {
    // The author's entity is registered: a relation subtitle needs it (SECS-1).
    Larsadmin::entities([Search\SearchablePost::class, Search\SearchableUser::class]);
    $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'x']);
    $post = Post::create(['title' => 'Осінній Київ', 'status' => 'live', 'author_id' => $alice->id]);

    expect(globalSearch('  Київ '))->toBe([
        'search' => 'Київ',
        'groups' => [[
            'entity' => 'posts',
            'label' => 'Posts',
            'results' => [[
                'id' => $post->id,
                'title' => 'Осінній Київ',
                'subtitle' => 'Alice',
                'url' => '/admin/entities/posts/'.$post->id,
            ]],
            'more' => false,
            'indexUrl' => '/admin/entities/posts?search='.rawurlencode('Київ'),
        ]],
        'failed' => [],
        'partial' => false,
    ]);
});

it('searches only entities that opted in (AC-1)', function () {
    Larsadmin::entities([Search\HiddenTag::class, Search\OpenTag::class]);
    Tag::create(['name' => 'Laravel']);

    expect(searchTitles('lara'))->toBe(['open-tags' => ['Laravel']]);

    Larsadmin::flushState();
    Larsadmin::entities([Search\HiddenTag::class]);

    expect(globalSearch('lara')['groups'])->toBe([]);
});

it('orders the groups like the sidebar and omits entities without results', function () {
    Larsadmin::entities([
        Search\SearchableUser::class,  // Access
        Search\SearchablePost::class,  // Content
        Search\SearchNote::class,      // no group
        Search\OpenTag::class,         // Content
    ]);

    User::create(['name' => 'Mark Zed', 'email' => 'zed@example.com', 'password' => 'x']);
    Post::create(['title' => 'Zed post', 'status' => 'live']);
    Note::create(['title' => 'Zed note']);
    Tag::create(['name' => 'Zed tag']);
    Tag::create(['name' => 'Other']);

    expect(array_keys(searchTitles('zed')))->toBe(['users', 'posts', 'open-tags', 'notes'])
        ->and(array_keys(searchTitles('post')))->toBe(['posts']);
});

it('finds the same records as the index search: key, LIKE, relation column and escaped wildcards (AC-2)', function () {
    Larsadmin::entities([Search\SearchablePost::class, Search\SearchableUser::class]);
    $alice = User::create(['name' => 'Alice Wonder', 'email' => 'alice@example.com', 'password' => 'x']);
    $first = Post::create(['title' => '50% off', 'status' => 'live']);
    Post::create(['title' => '50 percent off', 'status' => 'live']);
    Post::create(['title' => 'By her', 'status' => 'live', 'author_id' => $alice->id]);

    expect(searchTitles('50%'))->toBe(['posts' => ['50% off']])
        ->and(searchTitles('_'))->toBe([])
        ->and(searchTitles('wonder'))->toBe(['posts' => ['By her'], 'users' => ['Alice Wonder']])
        ->and(searchTitles((string) $first->id)['posts'])->toBe(['50% off']);
});

it('searches a digits-only term from one character and skips shorter terms without queries', function () {
    Larsadmin::entities([Search\KeyOnlyPost::class]);
    $post = Post::create(['title' => 'Seven', 'status' => 'live']);

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(globalSearch('s'))->toBe(['search' => 's', 'groups' => [], 'failed' => [], 'partial' => false])
        ->and(globalSearch('   '))->toMatchArray(['search' => '', 'groups' => []])
        ->and(DB::getQueryLog())->toBe([])
        ->and(searchTitles((string) $post->id))->toBe(['key-posts' => ['Seven']]);
});

it('honours the configured minimum length', function () {
    config(['larsadmin.search.min_length' => 4]);
    Larsadmin::entities([Search\SearchablePost::class]);
    Post::create(['title' => 'Abcd', 'status' => 'live']);

    expect(searchTitles('abc'))->toBe([])
        ->and(searchTitles('abcd'))->toBe(['posts' => ['Abcd']]);
});

it('caps the term at 100 characters', function () {
    Larsadmin::entities([Search\SearchablePost::class]);

    expect(globalSearch(str_repeat('a', 150))['search'])->toBe(str_repeat('a', 100));
});

it('matches nothing when no search column applies instead of every row', function () {
    Larsadmin::entities([Search\KeyOnlyPost::class, Search\SecretPost::class]);
    Post::create(['title' => 'Alpha', 'body' => 'Alpha body', 'status' => 'live']);

    Search\SecretPost::$seeTitle = false;

    // "al" cannot match an integer key; SecretPost's title and body are both hidden.
    expect(globalSearch('al')['groups'])->toBe([]);
});

it('limits the results per entity and flags more (AC-4)', function () {
    Larsadmin::entities([Search\SearchablePost::class]);
    foreach (range(1, 22) as $i) {
        Post::create(['title' => "Match {$i}", 'status' => 'live']);
    }

    $group = fn () => globalSearch('match')['groups'][0];

    expect($group()['results'])->toHaveCount(5)
        ->and($group()['more'])->toBeTrue()
        ->and($group()['results'][0]['title'])->toBe('Match 22');

    config(['larsadmin.search.limit' => 3]);
    expect($group()['results'])->toHaveCount(3);

    Search\SearchablePost::$globalSearchLimit = 2;
    expect($group()['results'])->toHaveCount(2);

    Search\SearchablePost::$globalSearchLimit = 50;
    expect($group()['results'])->toHaveCount(20)->and($group()['more'])->toBeTrue();

    Search\SearchablePost::$globalSearchLimit = 22;
    expect($group()['results'])->toHaveCount(20);

    Search\SearchablePost::$globalSearchLimit = 0;
    expect($group()['results'])->toHaveCount(1);

    Post::where('id', '>', 5)->delete();
    Search\SearchablePost::$globalSearchLimit = 5;
    expect($group()['results'])->toHaveCount(5)->and($group()['more'])->toBeFalse();
});

it('shows the title and subtitle of each record, eager loading $with without N+1 (AC-5)', function () {
    Larsadmin::entities([Search\SearchablePost::class, Search\ListedUser::class]);

    foreach (range(1, 5) as $i) {
        $author = User::create(['name' => "Author {$i}", 'email' => "a{$i}@example.com", 'password' => 'x']);
        Post::create(['title' => "Story {$i}", 'status' => 'live', 'author_id' => $author->id]);
    }

    Post::create(['title' => 'Story anonymous', 'status' => 'live']);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $results = globalSearch('story')['groups'][0]['results'];

    expect(array_column($results, 'subtitle'))->toBe([null, 'Author 5', 'Author 4', 'Author 3', 'Author 2'])
        // Posts, their authors, and one visibility check of all authors in the user entity's scope.
        ->and(count(DB::getQueryLog()))->toBe(3);
});

it('lists titles starting with the term first, then the newest (D5)', function () {
    Larsadmin::entities([Search\SearchablePost::class]);
    Post::create(['title' => 'Big apple', 'status' => 'live']);
    Post::create(['title' => 'Apple pie', 'status' => 'live']);
    Post::create(['title' => 'Crab apple', 'status' => 'live']);
    Post::create(['title' => 'APPLES', 'status' => 'live']);

    expect(searchTitles('apple')['posts'])->toBe(['APPLES', 'Apple pie', 'Crab apple', 'Big apple']);
});

it('neither searches nor orders by a column hidden by canSee (AC-10)', function () {
    Larsadmin::entities([Search\SecretPost::class]);
    Post::create(['title' => 'Plain', 'body' => 'needle inside', 'status' => 'live']);

    expect(searchTitles('needle'))->toBe([]);

    Search\SecretPost::$seeBody = true;
    expect(searchTitles('needle'))->toBe(['secret-posts' => ['Plain']]);

    // With the title hidden, rows are ordered by key only: no prefix ordering on the hidden column.
    Search\SecretPost::$seeTitle = false;
    Post::create(['title' => 'Needle first', 'body' => 'a needle', 'status' => 'live']);
    Post::create(['title' => 'Last', 'body' => 'another needle', 'status' => 'live']);

    expect(searchTitles('needle')['secret-posts'])->toBe(['Last', 'Needle first', 'Plain']);

    Search\SecretPost::$seeTitle = true;
    expect(searchTitles('needle')['secret-posts'])->toBe(['Needle first', 'Last', 'Plain']);
});

it('returns only rows of the index scope (AC-8, SQL path)', function () {
    Larsadmin::entities([Search\SearchablePost::class]);
    Post::create(['title' => 'Draft match', 'status' => 'draft']);
    Post::create(['title' => 'Live match', 'status' => 'live']);

    Search\SearchablePost::$indexUsing = fn ($query) => $query->where('status', 'live');

    expect(searchTitles('match'))->toBe(['posts' => ['Live match']]);
});

it('drops records the user may not view (AC-9)', function () {
    Gate::policy(Post::class, Search\SearchPolicy::class);
    Larsadmin::entities([Search\SearchablePost::class]);
    Search\SearchablePost::$globalSearchLimit = 2;

    Post::create(['title' => 'Open match', 'status' => 'live']);
    Post::create(['title' => 'Throwing match', 'status' => 'live']);
    Post::create(['title' => 'Private match', 'status' => 'live']);

    // The two newest are not viewable: no group at all.
    expect(globalSearch('match')['groups'])->toBe([]);

    Search\SearchablePost::$globalSearchLimit = 3;
    $group = globalSearch('match')['groups'][0];

    expect(array_column($group['results'], 'title'))->toBe(['Open match'])
        ->and($group['more'])->toBeFalse();

    Search\SearchablePost::$globalSearchLimit = 1;
    Post::create(['title' => 'Newest match', 'status' => 'live']);
    $group = globalSearch('match')['groups'][0];

    expect(array_column($group['results'], 'title'))->toBe(['Newest match'])->and($group['more'])->toBeTrue();
});

it('skips entities the user may not list (AC-7)', function (string $policy) {
    Gate::policy(Post::class, $policy);
    Larsadmin::entities([Search\SearchablePost::class, Search\SearchableUser::class]);
    Post::create(['title' => 'Shared name', 'status' => 'live']);
    User::create(['name' => 'Shared name', 'email' => 's@example.com', 'password' => 'x']);

    expect(globalSearch('shared')['failed'])->toBe([])
        ->and(array_keys(searchTitles('shared')))->toBe(['users']);
})->with([
    'before() denies' => [DenyBeforePolicy::class],
    'policy without viewAny' => [UpdateOnlyPolicy::class],
]);

it('skips entities without a policy when allow_without_policy is false', function () {
    config(['larsadmin.allow_without_policy' => false]);
    Larsadmin::entities([Search\SearchablePost::class]);
    Post::create(['title' => 'Hidden', 'status' => 'live']);

    expect(globalSearch('hidden')['groups'])->toBe([]);
});

it('skips an opted-in entity that has neither $search nor Scout', function () {
    Larsadmin::entities([Search\UnsearchablePost::class]);
    Post::create(['title' => 'Anything', 'status' => 'live']);

    expect(globalSearch('anything')['groups'])->toBe([]);
});

it('drops records whose key is unsafe in a URL path', function () {
    Larsadmin::entities([Search\SearchNote::class]);
    Note::create(['id' => '../users/1', 'title' => 'Unsafe note']);
    Note::create(['title' => 'Safe note']);

    expect(searchTitles('note'))->toBe(['notes' => ['Safe note']]);
});

it('reports a failing entity, lists it in failed and still answers for the others (AC-12)', function () {
    Exceptions::fake();
    Larsadmin::entities([Search\BrokenEntity::class, Search\SearchablePost::class]);
    Post::create(['title' => '42 answers', 'status' => 'live']);

    $response = globalSearch('42');

    expect($response['failed'])->toBe([['entity' => 'broken', 'label' => 'Broken']])
        ->and(array_column($response['groups'], 'entity'))->toBe(['posts'])
        ->and($response['partial'])->toBeFalse();

    Exceptions::assertReported(RuntimeException::class);
});

it('treats an authorization error of indexQuery as not visible, not as a failure', function () {
    Exceptions::fake();
    Larsadmin::entities([Search\RefusingEntity::class]);

    expect(globalSearch('42'))->toMatchArray(['groups' => [], 'failed' => []]);

    Exceptions::assertNothingReported();
});

it('fails closed when the viewAny check throws, without naming the entity', function (bool $authorizationError) {
    Exceptions::fake();
    Search\ThrowingViewAnyEntity::$authorizationError = $authorizationError;
    Larsadmin::entities([Search\ThrowingViewAnyEntity::class]);
    Post::create(['title' => 'x', 'status' => 'live']);

    expect(globalSearch('1'))->toMatchArray(['groups' => [], 'failed' => []]);

    $authorizationError ? Exceptions::assertNothingReported() : Exceptions::assertReported(RuntimeException::class);
})->with([
    'generic error' => [false],
    'authorization error' => [true],
]);
