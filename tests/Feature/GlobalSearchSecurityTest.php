<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Maxcxam\Larsadmin\Http\Middleware\HandleInertiaRequests;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\DenyBeforePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Note;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\BrokenEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout\RankedEngine;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout\ScoutPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\SearchNote;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\DeniedProbe;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\HiddenScoutPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\NonOptInProbe;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\OpenPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\Probe;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\RecordPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\SubtitlePost;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\TeamUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\TenantPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Search\Security\TenantScoutPost;
use Maxcxam\Larsadmin\Tests\Fixtures\UpdateOnlyPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/*
 * T1: the global search seen from an attacker, end-to-end through HTTP
 * (design 1.2 §5, §6, §7, §10 AC-7..AC-11, §15 security checklist; ADR-020, ADR-021).
 * Two tenants: Alice (id 1) and Bob (id 2); TenantPost lists only the posts of the signed-in author.
 */

beforeEach(function () {
    Probe::reset();
    secEntities([TenantPost::class, TeamUser::class]);

    $this->alice = User::create(['name' => 'Alice Team', 'email' => 'alice@team.test', 'password' => 'secret']);
    $this->bob = User::create(['name' => 'Bob Team', 'email' => 'bob@team.test', 'password' => 'secret']);

    $this->actingAs($this->bob);
});

afterEach(fn () => Probe::reset());

/**
 * Register exactly these entities (Larsadmin::entities() appends).
 */
function secEntities(array $entities): void
{
    Larsadmin::flushState();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities($entities);
}

function secSearch(mixed $search): TestResponse
{
    return test()->getJson('/admin/api/search?'.http_build_query(['search' => $search]), ['X-Requested-With' => 'XMLHttpRequest']);
}

/**
 * @return array<string, list<string>> result titles per entity uriKey
 */
function secTitles(mixed $search): array
{
    return collect(secSearch($search)->assertOk()->json('groups'))
        ->mapWithKeys(fn (array $group) => [$group['entity'] => array_column($group['results'], 'title')])
        ->all();
}

/**
 * @return array<string, ?string> subtitle per result title of a "quarterly" search
 */
function subtitles(): array
{
    return collect(secSearch('quarterly')->assertOk()->json('groups.0.results'))
        ->mapWithKeys(fn (array $result) => [$result['title'] => $result['subtitle']])
        ->all();
}

function secPost(User $author, string $title, ?string $body = null): Post
{
    return Post::create(['title' => $title, 'body' => $body, 'status' => 'live', 'author_id' => $author->id]);
}

/**
 * The search prop as the Inertia middleware would share it for the request.
 */
function secSharedSearch(?User $user): mixed
{
    $request = Request::create('/admin');
    // Bound first: rebinding the request resets its user resolver to the auth manager's.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    $prop = app(HandleInertiaRequests::class)->share($request)['larsadmin']['search'];

    return $prop instanceof Closure ? $prop() : $prop;
}

// --- Entities: opt-in and viewAny (AC-1, AC-7, NFR-025) ----------------------------------------------

it('AC-1: never queries nor names an entity that did not opt in', function () {
    Exceptions::fake();
    secEntities([NonOptInProbe::class, TenantPost::class]);
    secPost($this->bob, 'Ledger entry');

    $response = secSearch('ledger')->assertOk();

    expect(Probe::called('internal-ledger'))->toBe(0)
        ->and(array_column($response->json('groups'), 'entity'))->toBe(['tenant-posts'])
        ->and($response->json('failed'))->toBe([])
        ->and($response->getContent())->not->toContain('internal-ledger')->not->toContain('Internal ledger');

    Exceptions::assertNothingReported();
});

it('AC-7: never queries nor names an entity whose viewAny is denied, even when it would fail', function () {
    Exceptions::fake();
    secEntities([DeniedProbe::class, TenantPost::class]);
    secPost($this->bob, 'Quarterly report');

    $response = secSearch('report')->assertOk();

    expect(Probe::called('classified'))->toBe(0)
        ->and($response->json('failed'))->toBe([])
        ->and(array_column($response->json('groups'), 'entity'))->toBe(['tenant-posts'])
        ->and(strtolower($response->getContent()))->not->toContain('classified');

    Exceptions::assertNothingReported();
});

it('AC-7: hides an entity across the policy matrix', function (?string $policy, bool $allowWithoutPolicy, bool $viewAny, bool $visible) {
    config(['larsadmin.allow_without_policy' => $allowWithoutPolicy]);
    Probe::$viewAny = $viewAny;

    if ($policy !== null) {
        Gate::policy(Post::class, $policy);
    }

    secPost($this->bob, 'Matrix post');

    $response = secSearch('matrix')->assertOk();

    expect($response->json('failed'))->toBe([]);

    if ($visible) {
        expect(array_column($response->json('groups'), 'entity'))->toBe(['tenant-posts']);
    } else {
        expect($response->json('groups'))->toBe([])
            ->and($response->getContent())->not->toContain('tenant-posts')->not->toContain('Tenant posts')
            ->and(Probe::called('tenant-posts'))->toBe(0);
    }
})->with([
    'no policy, allowed without policy' => [null, true, true, true],
    'no policy, denied without policy' => [null, false, true, false],
    'policy allows viewAny' => [RecordPolicy::class, false, true, true],
    'policy denies viewAny' => [RecordPolicy::class, true, false, false],
    'policy without viewAny' => [UpdateOnlyPolicy::class, true, true, false],
    'before() denies' => [DenyBeforePolicy::class, true, true, false],
]);

// --- Rows: indexQuery scoping (AC-8, NFR-027) --------------------------------------------------------

it('AC-8: returns only the rows of the tenant scope on the SQL path, by text and by key', function () {
    $foreign = secPost($this->alice, 'Invoice of Alice', 'invoice body');
    foreach (range(1, 8) as $i) {
        secPost($this->alice, "Invoice {$i}");
    }
    $own = secPost($this->bob, 'Invoice of Bob');

    $group = secSearch('invoice')->assertOk()->json('groups.0');

    expect(array_column($group['results'], 'title'))->toBe(['Invoice of Bob'])
        // "more" is computed within the scope: Alice's nine invoices do not leak through it.
        ->and($group['more'])->toBeFalse()
        ->and(secTitles((string) $foreign->id))->toBe([])
        ->and(secTitles((string) $own->id))->toBe(['tenant-posts' => ['Invoice of Bob']])
        ->and(secTitles('alice'))->toBe([]);
});

it('AC-8: searches a relation column only within the related entity\'s index scope', function () {
    secEntities([OpenPost::class, TeamUser::class]);
    $mallory = User::create(['name' => 'Mallory Outsider', 'email' => 'mallory@evil.test', 'password' => 'x']);
    Post::create(['title' => 'Written by an outsider', 'status' => 'live', 'author_id' => $mallory->id]);
    Post::create(['title' => 'Written by the team', 'status' => 'live', 'author_id' => $this->alice->id]);

    // Mallory is outside TeamUser's index: her name must not be an oracle through the post search.
    expect(secTitles('mallory'))->toBe([])
        ->and(secTitles('alice team'))->toBe(['open-posts' => ['Written by the team']]);
});

// --- Records: view (AC-9, NFR-025) -------------------------------------------------------------------

it('AC-9: drops each record the user may not view and never returns its title', function () {
    Gate::policy(Post::class, RecordPolicy::class);
    secPost($this->bob, 'Private salary review');
    secPost($this->bob, 'Public salary scale');

    $response = secSearch('salary')->assertOk();

    expect(array_column($response->json('groups.0.results'), 'title'))->toBe(['Public salary scale'])
        ->and($response->getContent())->not->toContain('Private salary review');
});

it('AC-9: fails closed when the view check crashes: the whole entity is dropped, reported and the message not leaked', function () {
    Exceptions::fake();
    config(['app.debug' => true]);
    Gate::policy(Post::class, RecordPolicy::class);
    secEntities([TenantPost::class, TeamUser::class, SearchNote::class]);
    secPost($this->bob, 'Boom budget');
    secPost($this->bob, 'Fine budget');
    Note::create(['title' => 'Budget note']);

    $response = secSearch('budget')->assertOk();

    expect(array_column($response->json('groups'), 'entity'))->toBe(['notes'])
        ->and($response->json('failed'))->toBe([['entity' => 'tenant-posts', 'label' => 'Tenant posts']])
        ->and($response->getContent())->not->toContain('Fine budget')->not->toContain('salaries')->not->toContain('policy secret');

    Exceptions::assertReported(RuntimeException::class);
});

// --- Columns: canSee (AC-10, NFR-027) ----------------------------------------------------------------

it('AC-10: does not search a column hidden by canSee: no oracle on its value', function (string $switch, Closure $term) {
    $post = secPost($this->bob, 'Quarterly', 'needle in the body');
    $term = $term($post);

    expect(secTitles($term))->toBe(['tenant-posts' => ['Quarterly']]);

    Probe::${$switch} = false;

    expect(secTitles($term))->toBe([]);
})->with([
    'body' => ['seeBody', fn () => 'needle'],
    'key' => ['seeId', fn (Post $post) => (string) $post->id],
    'relation column' => ['seeAuthor', fn () => 'bob team'],
]);

it('AC-10: does not reveal a field hidden by canSee through the subtitle', function () {
    secPost($this->bob, 'Quarterly', 'hidden body text');
    Probe::$seeBody = false;

    $response = secSearch('quarterly')->assertOk();

    expect($response->json('groups.0.results.0.title'))->toBe('Quarterly')
        ->and($response->json('groups.0.results.0.subtitle'))->toBeNull()
        ->and($response->getContent())->not->toContain('hidden body text');

    Probe::$seeBody = true;

    expect(secSearch('quarterly')->json('groups.0.results.0.subtitle'))->toBe('hidden body text');
});

// --- Relation subtitle / title: the related entity's scope, viewAny and canSee (SECS-1, SECS-2) ------

describe('relation subtitle', function () {
    beforeEach(function () {
        secEntities([SubtitlePost::class, TeamUser::class]);

        $this->mallory = User::create(['name' => 'Mallory', 'email' => 'mallory@secret-outsider.test', 'password' => 'secret']);
        secPost($this->bob, 'Quarterly plan by Bob');
        secPost($this->mallory, 'Quarterly plan by Mallory');
        secPost($this->alice, 'Quarterly plan by Alice');
    });

    it('SECS-1: shows a related attribute only for related records inside the related entity scope, in one query', function () {
        DB::enableQueryLog();
        DB::flushQueryLog();

        expect(subtitles())->toBe([
            'Quarterly plan by Alice' => 'alice@team.test',
            'Quarterly plan by Mallory' => null,
            'Quarterly plan by Bob' => 'bob@team.test',
        ]);

        $userQueries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => preg_match('/^select [^(]* from "users"/', $sql) === 1);

        // The eager load of the authors and one batched scope check.
        expect($userQueries)->toHaveCount(2)
            ->and($userQueries->last())->toContain('like')->toContain('"users"."id" in');
    });

    it('SECS-1: shows no related attribute when the related model has no registered entity', function () {
        secEntities([SubtitlePost::class]);

        expect(array_values(subtitles()))->toBe([null, null, null]);
    });

    it('SECS-1: shows no related attribute when the user may not list the related entity', function () {
        Gate::policy(User::class, DenyBeforePolicy::class);

        expect(array_values(subtitles()))->toBe([null, null, null]);
    });

    it('SECS-2: shows no related attribute whose field the related entity hides by canSee', function () {
        Probe::$seeEmail = false;

        expect(array_values(subtitles()))->toBe([null, null, null]);
    });

    it('SECS-2: shows no relation subtitle when the relation field itself is hidden by canSee', function () {
        SubtitlePost::$subtitle = 'author.name';

        expect(subtitles()['Quarterly plan by Bob'])->toBe('Bob Team');

        Probe::$seeAuthor = false;

        expect(array_values(subtitles()))->toBe([null, null, null]);
    });

    it('SECS-7: applies the related scope to a relation title too', function () {
        SubtitlePost::$title = 'author.name';
        SubtitlePost::$subtitle = null;

        $titles = array_column(secSearch('quarterly')->assertOk()->json('groups.0.results'), 'title');

        expect($titles)->toBe(['Alice Team', '', 'Bob Team'])
            ->and(secSearch('quarterly')->getContent())->not->toContain('Mallory');
    });

    it('does not show a relation chain deeper than one level', function () {
        SubtitlePost::$subtitle = 'author.posts.title';

        expect(array_values(subtitles()))->toBe([null, null, null]);
    });

    it('CRS-05: eager loads the relation of the subtitle, safe under preventLazyLoading', function () {
        Model::preventLazyLoading();

        try {
            expect(subtitles()['Quarterly plan by Bob'])->toBe('bob@team.test');
        } finally {
            Model::preventLazyLoading(false);
        }
    });
});

// --- Input: validation and LIKE escaping (AC-11, NFR-028) --------------------------------------------

it('AC-11: rejects a non-string, missing or overlong search with 422 before searching', function (string $query) {
    secPost($this->bob, 'Anything');

    $this->getJson('/admin/api/search?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search')
        ->assertJsonMissingPath('groups')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(Probe::called('tenant-posts'))->toBe(0);
})->with([
    'list' => ['search[]=any'],
    'keyed array' => ['search[a]=any'],
    'nested array' => ['search[a][b]=any'],
    'missing' => ['q=any'],
    'empty' => ['search='],
    '101 multibyte characters' => ['search='.rawurlencode(str_repeat('ї', 101))],
    '101 ascii characters' => ['search='.str_repeat('a', 101)],
]);

it('AC-11: reads the term from the query string only, not from a request body', function () {
    $this->json('GET', '/admin/api/search', ['search' => 'anything'])->assertUnprocessable();
    $this->json('GET', '/admin/api/search?search=ab', ['search' => ['x']])->assertOk()->assertJsonPath('search', 'ab');
});

it('AC-11: accepts 100 multibyte characters', function () {
    secSearch(str_repeat('ї', 100))->assertOk()->assertJsonPath('search', str_repeat('ї', 100));
});

it('AC-11: escapes LIKE wildcards and the escape character in the term', function (string $term, array $titles) {
    foreach (['Plain text', '100% sure', 'snake_case', 'wow!bang', 'back\\slash', 'it\'s quoted'] as $title) {
        secPost($this->bob, $title);
    }

    expect(secTitles($term)['tenant-posts'] ?? [])->toBe($titles);
})->with([
    'percent wildcard' => ['%%', []],
    'underscore wildcard' => ['__', []],
    'mixed wildcards' => ['%_', []],
    'literal percent' => ['0%', ['100% sure']],
    'literal underscore' => ['e_c', ['snake_case']],
    'escape character' => ['w!b', ['wow!bang']],
    'escape then wildcard' => ['!%', []],
    'literal backslash' => ['k\\s', ['back\\slash']],
    'backslash then wildcard' => ['\\%', []],
    'quote' => ['t\'s', ['it\'s quoted']],
]);

it('AC-11: treats hostile terms as data: no error, no match, no damage', function (string $term) {
    Exceptions::fake();
    secPost($this->bob, 'Victim');

    secSearch($term)->assertOk()->assertJsonPath('groups', [])->assertJsonPath('failed', []);

    expect(Schema::hasTable('posts'))->toBeTrue()
        ->and(Post::count())->toBe(1);

    Exceptions::assertNothingReported();
})->with([
    'sql tautology' => ["' OR '1'='1"],
    'stacked query' => ['1; DROP TABLE posts; --'],
    'key tautology' => ['1 OR 1=1'],
    'comment' => ['%\' --'],
    'null byte' => ["ab\0cd"],
    '19 digit key' => ['1234567890123456789'],
    'negative key' => ['-1'],
    'right-to-left override' => ["\u{202E}ab"],
]);

it('AC-11: redirects a guest browser to the login page and answers 401 to a guest XHR', function () {
    auth()->logout();

    $this->get('/admin/api/search?search=ab')->assertRedirect('/admin/login');
    secSearch('ab')->assertUnauthorized()->assertJsonMissingPath('groups');
});

it('AC-11: answers 403 to a user without panel access (viewLarsadmin)', function () {
    secPost($this->bob, 'Gate check');
    Larsadmin::auth(fn (Request $request) => $request->user()?->email === 'admin@team.test');

    secSearch('gate')->assertForbidden()->assertJsonMissingPath('groups');

    expect(Probe::called('tenant-posts'))->toBe(0);
});

it('AC-11: throttles with 429, counting rejected requests too, without searching', function () {
    config(['larsadmin.search.throttle' => 2]);
    secPost($this->bob, 'Throttled');

    secSearch(['bad'])->assertUnprocessable();
    secSearch('throttled')->assertOk();

    $calls = Probe::called('tenant-posts');

    secSearch('throttled')->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonMissingPath('groups');

    expect(Probe::called('tenant-posts'))->toBe($calls);
});

// --- Response: headers, URLs, raw data, errors -------------------------------------------------------

it('marks every answer no-store, private', function (Closure $request) {
    $request()->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'results' => [fn () => secSearch('anything')],
    'too short' => [fn () => secSearch('a')],
    'invalid' => [fn () => secSearch(['x'])],
]);

it('returns titles and subtitles as raw JSON strings: no server-side HTML', function () {
    $html = '<img src=x onerror=alert(1)>';
    secPost($this->bob, "Report {$html}", '<b>bold</b> & "quoted"');

    $response = secSearch('report')->assertOk()->assertHeader('Content-Type', 'application/json');

    expect($response->json('groups.0.results.0'))->toMatchArray([
        'title' => "Report {$html}",
        'subtitle' => '<b>bold</b> & "quoted"',
    ])->and($response->getContent())->not->toContain('<mark>')->not->toContain('&lt;');
});

it('links only to the panel\'s own detail and index routes', function () {
    secEntities([TenantPost::class, TeamUser::class, SearchNote::class]);
    $post = secPost($this->bob, 'Link target');

    config(['larsadmin.search.limit' => 20]);

    foreach (['//evil.example', 'https://evil.example', '..', '.', 'a\\b', "a\nb", 'javascript:alert(1)', 'plain-key', 'q?x=1', 'h#x', '%2e%2e'] as $key) {
        Note::create(['id' => $key, 'title' => 'Link target note']);
    }

    $term = 'link target&x=1#frag';
    Post::whereKey($post->id)->update(['title' => 'Link target&x=1#frag']);
    Note::query()->update(['title' => 'Link target&x=1#frag note']);

    $groups = collect(secSearch($term)->assertOk()->json('groups'))->keyBy('entity');

    expect($groups->keys()->all())->toBe(['tenant-posts', 'notes'])
        ->and($groups['tenant-posts']['results'][0]['url'])->toBe('/admin/entities/tenant-posts/'.$post->id)
        // Keys with a slash, a backslash, a control character or dot segments are dropped; the rest are encoded.
        ->and(array_column($groups['notes']['results'], 'url', 'id'))->toEqualCanonicalizing([
            'q?x=1' => '/admin/entities/notes/q%3Fx=1',
            'plain-key' => '/admin/entities/notes/plain-key',
            'javascript:alert(1)' => '/admin/entities/notes/javascript:alert%281%29',
            'h#x' => '/admin/entities/notes/h%23x',
            '%2e%2e' => '/admin/entities/notes/%252e%252e',
        ]);

    foreach ($groups as $uriKey => $group) {
        foreach ($group['results'] as $result) {
            expect($result['url'])->toStartWith("/admin/entities/{$uriKey}/")
                ->and(substr($result['url'], strlen("/admin/entities/{$uriKey}/")))->not->toContain('/')->not->toContain('?')->not->toContain('#');
        }

        parse_str((string) parse_url($group['indexUrl'], PHP_URL_QUERY), $query);

        expect($group['indexUrl'])->toStartWith("/admin/entities/{$uriKey}?")
            ->and(parse_url($group['indexUrl'], PHP_URL_FRAGMENT))->toBeNull()
            ->and($query)->toBe(['search' => $term]);
    }
});

it('AC-12: reports a failing entity without leaking the exception message, even in debug mode', function () {
    Exceptions::fake();
    config(['app.debug' => true]);
    secEntities([BrokenEntity::class, TenantPost::class]);
    secPost($this->bob, '42 answers');

    $response = secSearch('42')->assertOk();

    expect($response->json('failed'))->toBe([['entity' => 'broken', 'label' => 'Broken']])
        ->and(array_column($response->json('groups'), 'entity'))->toBe(['tenant-posts'])
        ->and($response->getContent())->not->toContain('index query failed')->not->toContain('RuntimeException')
        ->and($response->json())->toHaveKeys(['search', 'groups', 'failed', 'partial'])
        ->and(array_keys($response->json()))->toHaveCount(4);

    Exceptions::assertReported(RuntimeException::class);
});

// --- Shared prop larsadmin.search (D12) --------------------------------------------------------------

it('shares a null search prop with guests and users who cannot use the panel', function () {
    expect(secSharedSearch($this->bob))->toBe(['minLength' => 2, 'maxLength' => 100])
        ->and(secSharedSearch(null))->toBeNull();

    Larsadmin::auth(fn () => false);

    expect(secSharedSearch($this->bob))->toBeNull();
});

it('shares a null search prop when no opt-in entity is listable, without naming or querying any', function (Closure $setup) {
    Exceptions::fake();
    $setup();

    expect(secSharedSearch($this->bob))->toBeNull()
        ->and(Probe::$indexCalls)->toBe([]);
})->with([
    'only non-opt-in entities' => [fn () => secEntities([NonOptInProbe::class, TeamUser::class])],
    'only a denied entity' => [fn () => secEntities([DeniedProbe::class])],
    'viewAny denied by policy' => [function () {
        Probe::$viewAny = false;
        Gate::policy(Post::class, RecordPolicy::class);
    }],
    'no policy, denied without policy' => [fn () => config(['larsadmin.allow_without_policy' => false])],
]);

// --- Scout path (ADR-021) ----------------------------------------------------------------------------

describe('Scout path', function () {
    beforeEach(function () {
        if (! class_exists('Laravel\Scout\Builder')) {
            $this->markTestSkipped('laravel/scout is not installed.');
        }

        $this->app->register('Laravel\Scout\ScoutServiceProvider');

        Schema::create('scout_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->unsignedInteger('team_id')->default(1);
            $table->timestamps();
        });

        ScoutPost::$engine = null;
        secEntities([TenantScoutPost::class]);

        // Ids 1..4: Alice's team (1) owns 1 and 3, Bob's team (2) owns 2 and 4.
        foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $i => $title) {
            ScoutPost::create(['title' => $title, 'body' => "shared word {$title}", 'team_id' => $i % 2 + 1]);
        }
    });

    afterEach(fn () => ScoutPost::$engine = null);

    it('AC-8: drops engine keys outside the tenant scope', function () {
        ScoutPost::$engine = new RankedEngine([1, 2, 3, 4]);

        $group = secSearch('anything')->assertOk()->json('groups.0');

        expect(array_column($group['results'], 'title'))->toBe(['Beta', 'Delta'])
            ->and($group['more'])->toBeFalse();

        ScoutPost::$engine = new RankedEngine([1, 3]);

        expect(secTitles('anything'))->toBe([]);
    });

    it('AC-8: drops forged, non-numeric and oversized keys and binds the rest as integers', function () {
        ScoutPost::$engine = new RankedEngine([
            '1 OR 1=1', "2' --", '2;DROP TABLE scout_posts', '-4', '0x4', '', '99999999999999999999', 'Delta', 4,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        expect(secTitles('anything'))->toBe(['tenant-scout-posts' => ['Delta']])
            ->and(Schema::hasTable('scout_posts'))->toBeTrue();

        $lookup = collect(DB::getQueryLog())->first(fn (array $query) => str_contains($query['query'], '"scout_posts"."id" in'));

        // The tenant binding (team 2), then the only well-formed key, as an integer.
        expect($lookup['bindings'])->toBe([2, 4]);
    });

    it('AC-8: a key loosely equal to a real one (" 4", "4.0", "4e0") does not hide the real record', function () {
        ScoutPost::$engine = new RankedEngine([' 4', '4 ', '4.0', '4e0', '04', 4]);

        expect(secTitles('anything'))->toBe(['tenant-scout-posts' => ['Delta']]);
    });

    it('AC-8: collapses duplicate keys into a single result', function () {
        ScoutPost::$engine = new RankedEngine([2, '2', 2, 4, '4']);

        expect(secTitles('anything'))->toBe(['tenant-scout-posts' => ['Beta', 'Delta']]);
    });

    it('AC-9: drops records the user may not view on the Scout path', function () {
        Gate::policy(ScoutPost::class, RecordPolicy::class);
        ScoutPost::create(['title' => 'Private plan', 'team_id' => 2]);
        ScoutPost::$engine = new RankedEngine([5, 2]);

        $response = secSearch('plan')->assertOk();

        expect(array_column($response->json('groups.0.results'), 'title'))->toBe(['Beta'])
            ->and($response->getContent())->not->toContain('Private plan');
    });

    it('AC-1/AC-7: never asks the engine for an entity that did not opt in or may not be listed', function (Closure $setup) {
        $engine = ScoutPost::$engine = new RankedEngine([1, 2, 3, 4]);
        $setup();

        secSearch('anything')->assertOk()->assertJsonPath('groups', [])->assertJsonPath('failed', []);

        expect($engine->builders())->toBeEmpty()
            ->and(Probe::$indexCalls)->toBe([]);
    })->with([
        'not opted in' => [fn () => secEntities([HiddenScoutPost::class])],
        'viewAny denied' => [function () {
            Probe::$viewAny = false;
            Gate::policy(ScoutPost::class, RecordPolicy::class);
        }],
    ]);

    it('AC-12: lists a failing engine in failed without leaking its message', function () {
        Exceptions::fake();
        config(['app.debug' => true]);
        ScoutPost::$engine = new RankedEngine([], new RuntimeException('meili key sk_live_123 rejected'));

        $response = secSearch('anything')->assertOk();

        expect($response->json('failed'))->toBe([['entity' => 'tenant-scout-posts', 'label' => 'Tenant Scout Posts']])
            ->and($response->getContent())->not->toContain('sk_live_123');

        Exceptions::assertReported(RuntimeException::class);
    });
});
