<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Search\GlobalSearch;
use Maxcxam\Larsadmin\Search\ResultText;
use Maxcxam\Larsadmin\Search\ScoutCandidates;
use Maxcxam\Larsadmin\Search\SqlCandidates;
use Maxcxam\Larsadmin\Tests\Fixtures\Flag;
use Maxcxam\Larsadmin\Tests\Fixtures\Note;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Search;
use Maxcxam\Larsadmin\Tests\Fixtures\Status;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Search\SearchablePost::$globalSearchLimit = null;
    $this->request = Request::create('/admin/api/search');
});

it('clamps the per-entity limit to 1..20', function (?int $limit, ?int $config, int $expected) {
    config(['larsadmin.search.limit' => $config]);
    Search\SearchablePost::$globalSearchLimit = $limit;

    expect(GlobalSearch::limitFor(Search\SearchablePost::class))->toBe($expected);
})->with([
    'default' => [null, 5, 5],
    'config' => [null, 8, 8],
    'entity' => [3, 8, 3],
    'too low' => [-4, 5, 1],
    'too high' => [99, 5, 20],
    'config too high' => [null, 500, 20],
]);

it('decides which terms are searched', function () {
    expect(GlobalSearch::shouldSearch(''))->toBeFalse()
        ->and(GlobalSearch::shouldSearch('a'))->toBeFalse()
        ->and(GlobalSearch::shouldSearch('ї'))->toBeFalse()
        ->and(GlobalSearch::shouldSearch('7'))->toBeTrue()
        ->and(GlobalSearch::shouldSearch('ab'))->toBeTrue()
        ->and(GlobalSearch::minLength())->toBe(2);

    config(['larsadmin.search.min_length' => 0]);

    expect(GlobalSearch::minLength())->toBe(1)->and(GlobalSearch::shouldSearch('a'))->toBeTrue();

    config(['larsadmin.search' => []]);

    expect(GlobalSearch::minLength())->toBe(2);
});

it('skips the remaining entities once the time budget is spent', function () {
    config(['larsadmin.search.time_budget_ms' => 100]);
    Larsadmin::entities([Search\SearchablePost::class, Search\OpenTag::class]);
    Post::create(['title' => 'Budget', 'status' => 'live']);
    Tag::create(['name' => 'Budget']);

    // start: 0 (deadline 100); before posts: 50; before tags: 150.
    $ticks = [0, 50, 150];
    $search = new GlobalSearch(function () use (&$ticks) {
        return array_shift($ticks) ?? 1000;
    });

    $response = $search->run($this->request, 'budget');

    expect($response['partial'])->toBeTrue()
        ->and(array_column($response['groups'], 'entity'))->toBe(['posts']);
});

it('has no time budget when it is null', function () {
    config(['larsadmin.search.time_budget_ms' => null]);
    Larsadmin::entities([Search\SearchablePost::class]);
    Post::create(['title' => 'Budget', 'status' => 'live']);

    $response = (new GlobalSearch(fn () => PHP_INT_MAX))->run($this->request, 'budget');

    expect($response['partial'])->toBeFalse()->and($response['groups'])->toHaveCount(1);
});

it('isolates an entity whose candidates throw', function () {
    Exceptions::fake();
    Larsadmin::entities([Search\SearchablePost::class, Search\EngineOnlyPost::class]);
    Post::create(['title' => 'Isolated', 'status' => 'live']);

    app()->instance(ScoutCandidates::class, new class extends ScoutCandidates
    {
        public function get(string $entity, Request $request, string $term, int $take): Collection
        {
            throw new LogicException('The engine is down.');
        }
    });

    $response = app(GlobalSearch::class)->run($this->request, 'isolated');

    expect($response['failed'])->toBe([['entity' => 'engine-posts', 'label' => 'Engine Only Posts']])
        ->and(array_column($response['groups'], 'entity'))->toBe(['posts']);

    Exceptions::assertReported(LogicException::class);
});

it('routes Scout entities to the Scout candidates, without an index link when they have no $search', function () {
    Larsadmin::entities([Search\EngineOnlyPost::class]);
    $post = Post::create(['title' => 'From the engine', 'status' => 'live']);

    $fake = new class extends ScoutCandidates
    {
        public array $calls = [];

        public function get(string $entity, Request $request, string $term, int $take): Collection
        {
            $this->calls[] = [$entity, $term, $take];

            return Post::all();
        }
    };
    app()->instance(ScoutCandidates::class, $fake);
    app()->instance(SqlCandidates::class, new class extends SqlCandidates
    {
        public function get(string $entity, Request $request, string $term, int $take): Collection
        {
            throw new LogicException('The SQL path must not run.');
        }
    });

    $group = app(GlobalSearch::class)->run($this->request, 'engine')['groups'][0];

    expect($fake->calls)->toBe([[Search\EngineOnlyPost::class, 'engine', 6]])
        ->and($group['indexUrl'])->toBeNull()
        ->and($group['results'][0])->toMatchArray(['id' => $post->id, 'title' => 'From the engine', 'subtitle' => null]);
});

it('returns no candidates from the SQL path for an entity without $search', function () {
    Post::create(['title' => 'Anything', 'status' => 'live']);

    expect((new SqlCandidates)->get(Search\UnsearchablePost::class, $this->request, 'any', 6))->toHaveCount(0);
});

it('detects Scout by the Searchable trait only when Scout is installed, memoized until flushState', function () {
    $memo = fn () => (new ReflectionProperty(Larsadmin::class, 'scoutModels'))->getValue();

    expect(Search\SearchablePost::usesScout())->toBeFalse()
        ->and(Larsadmin::modelUsesScout(User::class))->toBeFalse()
        ->and($memo())->toBe([Post::class => false, User::class => false]);

    Larsadmin::flushState();

    expect($memo())->toBe([]);
});

it('lists entities in sidebar order: groups by first appearance, then registration order', function () {
    Larsadmin::entities([
        Search\SearchNote::class,      // no group
        Search\SearchableUser::class,  // Access
        Search\SearchablePost::class,  // Content
        Search\HiddenTag::class,       // Content
        Search\KeyOnlyPost::class,     // no group
    ]);

    expect(Larsadmin::searchOrder())->toBe([
        Search\SearchNote::class,
        Search\KeyOnlyPost::class,
        Search\SearchableUser::class,
        Search\SearchablePost::class,
        Search\HiddenTag::class,
    ]);

    Larsadmin::flushState();

    expect(Larsadmin::searchOrder())->toBe([]);
});

it('exposes the client settings only when an opt-in entity is reachable', function () {
    expect(GlobalSearch::clientConfig($this->request))->toBeNull();

    Larsadmin::entities([Search\HiddenTag::class, Search\UnsearchablePost::class]);
    expect(GlobalSearch::clientConfig($this->request))->toBeNull();

    config(['larsadmin.allow_without_policy' => false]);
    Larsadmin::entities([Search\SearchablePost::class]);
    expect(GlobalSearch::clientConfig($this->request))->toBeNull();

    config(['larsadmin.allow_without_policy' => true, 'larsadmin.search.min_length' => 3]);
    expect(GlobalSearch::clientConfig($this->request))->toBe(['minLength' => 3, 'maxLength' => 100]);
});

it('builds the subtitle from the attribute, null when unset or empty', function () {
    $post = new Post(['title' => 'T', 'subtitle' => '']);

    expect(Search\KeyOnlyPost::make($post)->subtitle())->toBeNull()
        ->and(Search\SearchablePost::make($post)->subtitle())->toBeNull();

    $post->setRelation('author', new User(['name' => 'Ann']));

    expect(Search\SearchablePost::make($post)->subtitle())->toBe('Ann');
});

it('passes the Scout builder through scoutQuery() by default', function () {
    $builder = new stdClass;

    expect(Search\SearchablePost::scoutQuery($this->request, $builder))->toBe($builder);
});

it('turns attribute values into display text safely (CRS-02)', function (mixed $value, ?string $expected) {
    expect(ResultText::text($value))->toBe($expected);
})->with([
    'string' => ['Text', 'Text'],
    'empty string' => ['', null],
    'null' => [null, null],
    'int' => [42, '42'],
    'backed enum' => [Status::Live, 'live'],
    'unit enum' => [Flag::Draft, 'Draft'],
    'stringable' => [str('fluent'), 'fluent'],
    'array' => [['a' => 1], null],
    'object' => [new stdClass, null],
]);

it('does not fail on an enum or array subtitle (CRS-02)', function () {
    $post = new Post(['title' => 'T']);
    $post->setRawAttributes(['title' => 'T', 'status' => 'live']);

    Search\SearchablePost::$subtitle = 'status';

    try {
        $post->mergeCasts(['status' => Status::class]);
        expect(Search\SearchablePost::make($post)->subtitle())->toBe('live');

        $post->mergeCasts(['status' => 'array']);
        $post->setRawAttributes(['status' => '["a"]']);
        expect(Search\SearchablePost::make($post)->subtitle())->toBeNull();
    } finally {
        Search\SearchablePost::$subtitle = 'author.name';
    }
});

it('eager loads the relation of a one-level relation title or subtitle (CRS-05)', function () {
    expect(ResultText::eagerLoads(Search\SearchablePost::class))->toBe(['author'])
        ->and(ResultText::eagerLoads(Search\KeyOnlyPost::class))->toBe([])
        ->and(ResultText::eagerLoads(Search\SearchableUser::class))->toBe([]);
});

it('uses overridden title() and subtitle() as they are, and skips prefix ordering on a DISTINCT scope', function () {
    Larsadmin::entities([Search\CustomTextPost::class]);
    Post::create(['title' => 'Big apple', 'status' => 'live']);
    Post::create(['title' => 'Apple', 'status' => 'draft']);
    Post::create(['title' => 'Crab apple', 'status' => 'live']);

    $results = app(GlobalSearch::class)->run($this->request, 'apple')['groups'][0]['results'];

    // Key order only (no ORDER BY CASE with DISTINCT).
    expect(array_column($results, 'title'))->toBe(['Custom Crab apple', 'Custom Apple', 'Custom Big apple'])
        ->and($results[1]['subtitle'])->toBe('Status: draft');
});

it('searches only the key for a digits-only term shorter than min_length (SECS-6)', function () {
    Larsadmin::entities([Search\SearchablePost::class, Search\SearchNote::class, Search\SecretPost::class]);
    $first = Post::create(['title' => 'Title with 1 inside', 'status' => 'live']);
    Post::create(['title' => 'Version 2', 'status' => 'live']);
    Note::create(['id' => '2', 'title' => 'Two']);

    $titles = fn (string $term) => collect(app(GlobalSearch::class)->run(Request::create('/'), $term)['groups'])
        ->mapWithKeys(fn (array $group) => [$group['entity'] => array_column($group['results'], 'title')])
        ->all();

    // "2" matches post 2 and note "2" by key, not "Version 2" by LIKE; SecretPost has no key column.
    expect($titles('2'))->toBe(['posts' => ['Version 2'], 'notes' => ['Two']])
        ->and($titles((string) $first->id))->toBe(['posts' => ['Title with 1 inside']]);

    config(['larsadmin.search.min_length' => 1]);

    expect($titles('2'))->toBe(['posts' => ['Version 2'], 'notes' => ['Two'], 'secret-posts' => ['Version 2']]);
});

it('treats an HTTP 403/404 from indexQuery as not visible and lists other statuses as failed (CRS-10)', function (int $status, bool $failed) {
    Exceptions::fake();
    Search\AbortingEntity::$status = $status;
    Larsadmin::entities([Search\AbortingEntity::class]);

    $response = app(GlobalSearch::class)->run($this->request, '12');

    // Laravel itself never reports HTTP exceptions; a 500 still lists the entity as failed.
    expect($response['failed'] !== [])->toBe($failed);
    Exceptions::assertNothingReported();
})->with([
    '403' => [403, false],
    '404' => [404, false],
    '500' => [500, true],
]);

it('does not report a throwing viewAny from the shared prop (SECS-9)', function () {
    Exceptions::fake();
    Larsadmin::entities([Search\ThrowingViewAnyEntity::class]);

    expect(GlobalSearch::clientConfig($this->request))->toBeNull();

    Exceptions::assertNothingReported();
});

it('clamps scout_candidates to 1..500 (SECS-6)', function () {
    expect(ScoutCandidates::MAX_CANDIDATES)->toBe(500);
});

it('drops a relation subtitle whose relation was not loaded instead of lazy loading it', function () {
    Larsadmin::entities([Search\SearchablePost::class, Search\ListedUser::class]);
    $author = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
    $post = Post::create(['title' => 'P', 'status' => 'live', 'author_id' => $author->id]);

    $text = new ResultText(Search\SearchablePost::class, $this->request, collect([$post]));

    expect($text->subtitle(Search\SearchablePost::make($post)))->toBeNull();

    $post->load('author');
    $text = new ResultText(Search\SearchablePost::class, $this->request, collect([$post]));

    expect($text->subtitle(Search\SearchablePost::make($post)))->toBe('Ann');
});

it('hides a relation subtitle when the related scope refuses, and fails the entity on other errors', function (int $status, bool $failed) {
    Search\AbortingUser::$status = $status;
    Larsadmin::entities([Search\SearchablePost::class, Search\AbortingUser::class]);
    $author = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'x']);
    Post::create(['title' => 'Scoped', 'status' => 'live', 'author_id' => $author->id]);

    $response = app(GlobalSearch::class)->run($this->request, 'scoped');

    expect($response['failed'] !== [])->toBe($failed)
        ->and($response['groups'][0]['results'][0]['subtitle'] ?? null)->toBeNull();
})->with([
    '403' => [403, false],
    '500' => [500, true],
]);
