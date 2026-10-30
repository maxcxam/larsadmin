<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\ActionPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\ClosureAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\DefaultedActionPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities;
use Maxcxam\Larsadmin\Tests\Fixtures\NoCreatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Note;
use Maxcxam\Larsadmin\Tests\Fixtures\NoUpdatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Security;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\TagPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Maxcxam\Larsadmin\Tests\Fixtures\UserPolicy;

beforeEach(function () {
    config(['larsadmin.locale' => 'en']);
    ActionPost::reset();
    PostPolicy::$allowDelete = false;
    TagPostPolicy::$detach = true;
    TagPostPolicy::$allowDelete = false;
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ActionPost::class]);

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->admin);
});

function frPost(string $title, string $status = 'draft'): Post
{
    return Post::create(['title' => $title, 'status' => $status]);
}

function frRun(object $test, array $body = [], string $key = 'mark-live', string $entity = 'action-posts')
{
    return $test->from("/admin/entities/{$entity}")->post("/admin/entities/{$entity}/actions/{$key}", $body);
}

function frJson(string $url)
{
    return test()->getJson($url, ['X-Requested-With' => 'XMLHttpRequest']);
}

// SF-1 / FR-2 ------------------------------------------------------------------------------------

it('denies a standalone action whose canRun returns false (PoC)', function () {
    Gate::policy(Post::class, NoUpdatePolicy::class);
    ActionPost::$actions = [(new ClosureAction('wipe'))->standalone()->canRun(fn () => false)];

    frRun($this, key: 'wipe')->assertForbidden();

    expect(ActionPost::$calls)->toBe([]);
});

it('calls canRun with a null model for a standalone action and runs when it allows', function () {
    $received = 'unset';
    ActionPost::$actions = [(new ClosureAction('wipe'))->standalone()->canRun(function ($request, $model) use (&$received) {
        $received = $model;

        return true;
    })];

    frRun($this, key: 'wipe')->assertRedirect();

    expect($received)->toBeNull()->and(ActionPost::$calls)->toHaveCount(1);
});

it('defaults a standalone action to the entity create ability and fails closed', function () {
    ActionPost::$actions = [(new ClosureAction('wipe'))->standalone()];

    Gate::policy(Post::class, NoCreatePolicy::class);
    frRun($this, key: 'wipe')->assertForbidden();
    expect(ActionPost::$calls)->toBe([]);

    Gate::policy(Post::class, PostPolicy::class);
    frRun($this, key: 'wipe')->assertRedirect();
    expect(ActionPost::$calls)->toHaveCount(1);
});

// SF-2: per-record urls ----------------------------------------------------------------------------

it('serializes absolute-path urls on index, detail and edit records', function () {
    Gate::policy(Post::class, PostPolicy::class);
    PostPolicy::$allowDelete = true;
    $post = frPost('Linked');

    $expected = [
        'show' => "/admin/entities/action-posts/{$post->id}",
        'edit' => "/admin/entities/action-posts/{$post->id}/edit",
        'destroy' => "/admin/entities/action-posts/{$post->id}",
    ];

    $this->get('/admin/entities/action-posts')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.urls', $expected)
        ->where('records.data.0.id', $post->id)
        ->where('records.data.0.authorizedToUpdate', true));
    $this->get("/admin/entities/action-posts/{$post->id}")->assertInertia(fn (Assert $page) => $page->where('record.urls', $expected));
    $this->get("/admin/entities/action-posts/{$post->id}/edit")->assertInertia(fn (Assert $page) => $page->where('record.urls', $expected));
});

it('nulls the urls of actions the user may not perform', function () {
    Gate::policy(Post::class, NoUpdatePolicy::class);
    $post = frPost('Restricted');

    $this->get('/admin/entities/action-posts')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.urls', [
            'show' => "/admin/entities/action-posts/{$post->id}",
            'edit' => null,
            'destroy' => null,
        ]));
});

it('never builds a url from a key that could traverse the path', function () {
    Larsadmin::entities([Entities\Note::class]);

    $evil = (new Note)->forceFill(['id' => '../users/1', 'title' => 'Evil']);
    $evil->save();
    $good = Note::create(['title' => 'Good']);

    $this->get('/admin/entities/notes')->assertInertia(function (Assert $page) use ($good) {
        $rows = collect($page->toArray()['props']['records']['data'])->keyBy('title');

        expect($rows['Evil']['urls'])->toBe(['show' => null, 'edit' => null, 'destroy' => null])
            ->and($rows['Good']['urls']['show'])->toBe("/admin/entities/notes/{$good->id}");
    });
});

it('serializes show, edit, destroy and via-aware urls on HasMany panel rows', function () {
    Larsadmin::entities([Entities\PanelUser::class, Entities\PanelPost::class]);
    Gate::policy(Post::class, PostPolicy::class);
    PostPolicy::$allowDelete = true;
    $post = Post::create(['title' => 'Owned', 'status' => 'draft', 'author_id' => $this->admin->id]);

    $urls = frJson("/admin/api/entities/users/{$this->admin->id}/related/posts")->assertOk()->json('records.data.0.urls');

    expect($urls)->toBe([
        'show' => "/admin/entities/posts/{$post->id}",
        'edit' => "/admin/entities/posts/{$post->id}/edit",
        'destroy' => "/admin/entities/posts/{$post->id}?viaEntity=users&viaId={$this->admin->id}&viaField=author",
        'detach' => null,
    ]);

    PostPolicy::$allowDelete = false;

    frJson("/admin/api/entities/users/{$this->admin->id}/related/posts")->assertJsonPath('records.data.0.urls.destroy', null);
});

it('returns to the parent after deleting a related row through the destroy url', function () {
    Larsadmin::entities([Entities\PanelUser::class, Entities\PanelPost::class]);
    Gate::policy(Post::class, PostPolicy::class);
    PostPolicy::$allowDelete = true;
    Post::create(['title' => 'Owned', 'status' => 'draft', 'author_id' => $this->admin->id]);

    $url = frJson("/admin/api/entities/users/{$this->admin->id}/related/posts")->json('records.data.0.urls.destroy');

    $this->delete($url)->assertRedirect("/admin/entities/users/{$this->admin->id}");
    expect(Post::count())->toBe(0);
});

it('serializes the detach url on BelongsToMany panel rows only when allowed', function () {
    Larsadmin::entities([Entities\TaggedPost::class, Entities\TagEntity::class]);
    Gate::policy(Post::class, TagPostPolicy::class);
    $post = frPost('Tagged');
    $tag = Tag::create(['name' => 'T']);
    $post->tags()->attach($tag->id);

    $url = "/admin/api/entities/posts/{$post->id}/related/tags";

    frJson($url)->assertOk()
        ->assertJsonPath('records.data.0.urls.detach', "/admin/entities/posts/{$post->id}/attach/tags/{$tag->id}")
        ->assertJsonPath('records.data.0.urls.show', "/admin/entities/tags/{$tag->id}");

    TagPostPolicy::$detach = false;

    frJson($url)->assertJsonPath('records.data.0.urls.detach', null)->assertJsonPath('records.data.0.authorizedToDetach', false);
});

it('no longer duplicates related.uriKey on panel fields', function () {
    Larsadmin::entities([Entities\PanelUser::class, Entities\PanelPost::class]);

    $this->get("/admin/entities/users/{$this->admin->id}")->assertInertia(fn (Assert $page) => $page
        ->missing('record.fields.2.related')
        ->where('record.fields.2.relatedEntity.uriKey', 'posts'));
});

// FR-1: explicit empty filter clears a default ----------------------------------------------------

it('clears a defaulted filter on the related list with an explicit empty value', function () {
    Larsadmin::entities([Entities\DefaultedPanelUser::class, Entities\DefaultedPanelPost::class]);
    Post::create(['title' => 'D', 'status' => 'draft', 'author_id' => $this->admin->id]);
    Post::create(['title' => 'L', 'status' => 'live', 'author_id' => $this->admin->id]);

    $url = "/admin/api/entities/users/{$this->admin->id}/related/posts";

    frJson($url)->assertJsonPath('records.total', 1)->assertJsonPath('query.filters.status', 'live');
    frJson($url.'?filters[status]=')->assertJsonPath('records.total', 2);
    frJson($url.'?filters[status]=draft')->assertJsonPath('records.total', 1);
});

it('clears a defaulted filter for the bulk "all" selection with an explicit empty value', function () {
    Larsadmin::entities([DefaultedActionPost::class]);
    frPost('D', 'draft');
    $live = frPost('L', 'live');
    ActionPost::$actions = [new ClosureAction];

    frRun($this, ['resources' => 'all'], entity: 'defaulted-action-posts')->assertRedirect();
    expect(ActionPost::$calls[0]['ids'])->toBe([$live->id]);

    ActionPost::$calls = [];
    frRun($this, ['resources' => 'all', 'filters' => ['status' => '']], entity: 'defaulted-action-posts')->assertRedirect();
    expect(ActionPost::$calls[0]['ids'])->toHaveCount(2);
});

// SF-3 / FR-4: bulk actions ---------------------------------------------------------------------

it('rejects an "all" selection above the configured cap with a 422 and runs nothing', function () {
    config(['larsadmin.actions.max_all' => 2]);
    foreach (range(1, 3) as $i) {
        frPost("P{$i}");
    }
    ActionPost::$actions = [new ClosureAction];

    frRun($this, ['resources' => 'all'])
        ->assertSessionHasErrors(['resources' => 'Too many records selected: 3 matched, the limit is 2. Narrow the search or filters.']);

    expect(ActionPost::$calls)->toBe([]);

    frRun($this, ['resources' => 'all', 'search' => 'P1'])->assertSessionHasNoErrors()->assertRedirect();
    expect(ActionPost::$calls)->toHaveCount(1);
});

it('answers a selection that matched nothing with an error toast, not a 403', function () {
    ActionPost::$actions = [new ClosureAction];
    frPost('A');

    frRun($this, ['resources' => 'all', 'search' => 'zzz'])
        ->assertRedirect()
        ->assertSessionHas('larsadmin.toast', ['message' => 'No records matched.', 'type' => 'error']);

    frRun($this, ['resources' => [99999]])
        ->assertRedirect()
        ->assertSessionHas('larsadmin.toast', ['message' => 'No records matched.', 'type' => 'error']);

    expect(ActionPost::$calls)->toBe([]);
});

it('still answers 403 when rows matched but none is authorized', function () {
    $post = frPost('A');
    ActionPost::$actions = [(new ClosureAction)->canRun(fn () => false)];

    frRun($this, ['resources' => [$post->id]])->assertForbidden();
});

it('keeps the most severe chunk response instead of the last one', function (array $responses, string $type, string $message) {
    foreach (range(1, count($responses)) as $i) {
        frPost("P{$i}");
    }

    $queue = $responses;
    $action = new ClosureAction(callback: function () use (&$queue) {
        return array_shift($queue)();
    });
    $action->chunkSize = 1;
    ActionPost::$actions = [$action];

    $response = frRun($this, ['resources' => 'all']);

    if ($type === 'redirect') {
        $response->assertRedirect('/admin/somewhere');
    } else {
        $response->assertSessionHas('larsadmin.toast', ['message' => $message, 'type' => $type]);
    }
})->with([
    'danger before message' => [[fn () => ActionResponse::danger('Bad'), fn () => ActionResponse::message('Fine')], 'error', 'Bad'],
    'danger after message' => [[fn () => ActionResponse::message('Fine'), fn () => ActionResponse::danger('Bad')], 'error', 'Bad'],
    'redirect before message' => [[fn () => ActionResponse::redirect('/admin/somewhere'), fn () => ActionResponse::message('Fine')], 'redirect', ''],
    'danger beats redirect' => [[fn () => ActionResponse::redirect('/admin/somewhere'), fn () => ActionResponse::danger('Bad')], 'error', 'Bad'],
]);

// SF-5: relation search columns honour the related index scope ------------------------------------

it('searches a relation column only within the related entity index scope', function () {
    Larsadmin::entities([Entities\SearchByAuthorPost::class, Entities\VisibleUser::class]);

    $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
    $harry = User::create(['name' => 'Hidden Harry', 'email' => 'harry@example.com', 'password' => 'secret']);
    Post::create(['title' => 'By Alice', 'status' => 'draft', 'author_id' => $alice->id]);
    Post::create(['title' => 'By Harry', 'status' => 'draft', 'author_id' => $harry->id]);

    $this->get('/admin/entities/posts?search=alice@')->assertInertia(fn (Assert $page) => $page
        ->has('records.data', 1)->where('records.data.0.title', 'By Alice'));

    // The hidden author is not an existence oracle.
    $this->get('/admin/entities/posts?search=harry@')->assertInertia(fn (Assert $page) => $page->has('records.data', 0));
});

// FR-3: one perPage policy ------------------------------------------------------------------------

it('applies one perPage policy to index and related lists', function () {
    Larsadmin::entities([Entities\PanelUser::class, Entities\PanelPost::class]);
    Post::create(['title' => 'One', 'status' => 'draft', 'author_id' => $this->admin->id]);

    $related = "/admin/api/entities/users/{$this->admin->id}/related/posts";

    frJson($related.'?perPage=25')->assertJsonPath('query.perPage', 25);
    frJson($related.'?perPage=7')->assertJsonPath('query.perPage', 10);
    frJson($related.'?perPage=-1')->assertJsonPath('query.perPage', 10);
    $this->get('/admin/entities/posts?perPage=7')->assertInertia(fn (Assert $page) => $page->where('query.perPage', 25));
});

// FR-11: prototypes are cached per request -----------------------------------------------------------

it('builds the fields once per request however many rows are serialized', function () {
    Larsadmin::entities([Entities\SpyPost::class]);
    foreach (range(1, 5) as $i) {
        frPost("Row {$i}");
    }

    Entities\SpyPost::$calls = 0;
    $this->get('/admin/entities/spy-posts')->assertOk();

    expect(Entities\SpyPost::$calls)->toBe(1);
});

// FR-14: returnTo falls back when the via parent is not viewable -----------------------------------

it('falls back to the record page when the via parent is pickable but not viewable', function () {
    Larsadmin::entities([Entities\PanelUser::class, Entities\PanelPost::class]);
    Gate::policy(Post::class, PostPolicy::class);
    Gate::policy(User::class, UserPolicy::class);
    UserPolicy::$viewAny = true;
    UserPolicy::$view = false;

    $response = $this->post("/admin/entities/posts?viaEntity=users&viaId={$this->admin->id}&viaField=author", [
        'title' => 'Fallback',
        'author' => $this->admin->id,
    ]);

    $post = Post::where('title', 'Fallback')->firstOrFail();
    $response->assertRedirect("/admin/entities/posts/{$post->id}");

    UserPolicy::$view = true;
});

// FR-18: date range is index friendly ---------------------------------------------------------------

it('filters the date range with a half-open interval instead of whereDate', function () {
    Larsadmin::entities([Entities\FilterPost::class]);
    foreach (['2026-01-09 23:59:59', '2026-01-10 00:00:00', '2026-01-10 23:59:59', '2026-01-11 00:00:00'] as $at) {
        Post::create(['title' => $at, 'status' => 'live', 'published_at' => $at, 'author_id' => $this->admin->id]);
    }

    DB::enableQueryLog();
    $titles = collect($this->get('/admin/entities/posts?filters[published-range][from]=2026-01-10&filters[published-range][to]=2026-01-10')
        ->viewData('page')['props']['records']['data'])->pluck('title')->sort()->values()->all();
    $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ');

    expect($titles)->toBe(['2026-01-10 00:00:00', '2026-01-10 23:59:59'])
        ->and($sql)->not->toContain('strftime')
        ->and($sql)->toContain('"published_at" >= ?')
        ->and($sql)->toContain('"published_at" < ?');
});

// FR-19: asset hashes ------------------------------------------------------------------------------------

it('hashes a published asset once per process', function () {
    $dir = public_path('vendor/larsadmin');
    @mkdir($dir, 0777, true);
    file_put_contents($dir.'/probe.js', 'one');

    $first = Larsadmin::asset('probe.js');
    file_put_contents($dir.'/probe.js', 'two');

    expect(Larsadmin::asset('probe.js'))->toBe($first);

    Larsadmin::flushState();
    expect(Larsadmin::asset('probe.js'))->not->toBe($first);

    unlink($dir.'/probe.js');
});

// Item 14: BelongsTo inside action fields -----------------------------------------------------------------------

function frBelongsToAction(): ClosureAction
{
    return new ClosureAction(fieldList: [BelongsTo::make('Author', 'author', Entities\User::class)->rules('required')]);
}

it('hands the related key of a BelongsTo action field to the action', function () {
    Larsadmin::entities([ActionPost::class, Entities\User::class]);
    $post = frPost('A');
    $author = User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret']);
    ActionPost::$actions = [frBelongsToAction()];

    frRun($this, ['resources' => [$post->id], 'author' => $author->id])->assertRedirect();

    expect(ActionPost::$calls[0]['fields'])->toBe(['author' => $author->id]);
});

it('validates the BelongsTo action field against the related scope', function () {
    Larsadmin::entities([ActionPost::class, Entities\User::class]);
    $post = frPost('A');
    ActionPost::$actions = [frBelongsToAction()];

    frRun($this, ['resources' => [$post->id], 'author' => 99999])->assertSessionHasErrors('author');
    frRun($this, ['resources' => [$post->id]])->assertSessionHasErrors('author');

    expect(ActionPost::$calls)->toBe([]);
});

it('serves the options of a BelongsTo action field in the action context', function () {
    Larsadmin::entities([ActionPost::class, Entities\User::class]);
    User::create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret']);
    ActionPost::$actions = [frBelongsToAction()];

    frJson('/admin/api/entities/action-posts/fields/author/options?context=action&action=mark-live')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('denies the action-context options when the action is hidden, unknown or the field is not a BelongsTo', function () {
    Larsadmin::entities([ActionPost::class, Entities\User::class]);
    ActionPost::$actions = [frBelongsToAction()->canSee(fn () => false), new ClosureAction('other', fieldList: [Text::make('Note')])];

    $base = '/admin/api/entities/action-posts/fields/author/options?context=action';

    frJson($base.'&action=mark-live')->assertNotFound();
    frJson($base.'&action=missing')->assertNotFound();
    frJson('/admin/api/entities/action-posts/fields/note/options?context=action&action=other')->assertNotFound();
    frJson($base)->assertUnprocessable();
});

it('applies the related viewAny to the action-context options', function () {
    Larsadmin::entities([ActionPost::class, Entities\User::class]);
    Gate::policy(User::class, Security\DenyAllPolicy::class);
    ActionPost::$actions = [frBelongsToAction()];

    frJson('/admin/api/entities/action-posts/fields/author/options?context=action&action=mark-live')->assertForbidden();
});
