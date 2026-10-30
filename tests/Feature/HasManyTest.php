<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities;
use Maxcxam\Larsadmin\Tests\Fixtures\NoCreatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\UpdateOnlyPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Maxcxam\Larsadmin\Tests\Fixtures\UserPolicy;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Entities\PanelUser::class, Entities\PanelPost::class]);

    Entities\PanelPost::$indexUsing = null;
    UserPolicy::$viewAny = true;
    UserPolicy::$view = true;
    PostPolicy::$allowDelete = false;

    $this->owner = hmUser('Owner');
    $this->actingAs($this->owner);
});

function hmUser(string $name): User
{
    return User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'secret']);
}

function hmPosts(User $author, int $count, string $prefix = 'Post'): void
{
    foreach (range(1, $count) as $i) {
        Post::create(['title' => "{$prefix} {$i}", 'status' => 'draft', 'author_id' => $author->id]);
    }
}

function hmRelated(User $user, string $query = '')
{
    return test()->getJson("/admin/api/entities/users/{$user->id}/related/posts".($query ? "?{$query}" : ''), ['X-Requested-With' => 'XMLHttpRequest']);
}

it('serves the panel field on detail only with the contract keys', function () {
    $this->get("/admin/entities/users/{$this->owner->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.component', 'has-many-field')
        ->where('record.fields.2.panel', true)
        ->where('record.fields.2.name', 'Posts')
        ->where('record.fields.2.attribute', 'posts')
        ->where('record.fields.2.endpoint', "/admin/api/entities/users/{$this->owner->id}/related/posts")
        ->where('record.fields.2.createUrl', "/admin/entities/posts/create?viaEntity=users&viaId={$this->owner->id}&viaField=author")
        ->where('record.fields.2.via', ['viaEntity' => 'users', 'viaId' => $this->owner->id, 'viaField' => 'author'])
        ->where('record.fields.2.relatedEntity.uriKey', 'posts'));
});

it('never shows the panel on index or forms', function () {
    $this->get('/admin/entities/users')->assertInertia(fn (Assert $page) => $page
        ->has('columns', 2)
        ->where('columns.1.attribute', 'name'));

    $this->get("/admin/entities/users/{$this->owner->id}/edit")->assertInertia(fn (Assert $page) => $page->has('record.fields', 1));
    $this->get('/admin/entities/users/create')->assertInertia(fn (Assert $page) => $page->has('fields', 1));
});

it('ignores panel input on update', function () {
    $this->put("/admin/entities/users/{$this->owner->id}", ['name' => 'Renamed', 'posts' => [1, 2]])->assertRedirect();

    expect($this->owner->fresh()->name)->toBe('Renamed');
});

it('hides createUrl when the related entity cannot be created', function () {
    Gate::policy(Post::class, NoCreatePolicy::class);

    $this->get("/admin/entities/users/{$this->owner->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.createUrl', null));
});

it('lists the related records with the contract shape', function () {
    hmPosts($this->owner, 2);
    hmPosts(hmUser('Other'), 3, 'Foreign');

    $response = hmRelated($this->owner)->assertOk()
        ->assertJsonStructure([
            'columns' => [['attribute', 'name', 'component', 'sortable']],
            'records' => ['data' => [['id', 'title', 'fields', 'authorizedToView', 'authorizedToUpdate', 'authorizedToDelete', 'authorizedToDetach']], 'current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            'query' => ['search', 'sort', 'direction', 'perPage'],
        ])
        ->assertJsonPath('records.total', 2)
        ->assertJsonPath('records.per_page', 10)
        ->assertJsonPath('records.from', 1)
        ->assertJsonPath('records.to', 2)
        ->assertJsonPath('records.data.0.title', 'Post 2')
        ->assertJsonPath('records.data.0.authorizedToView', true)
        ->assertJsonPath('records.data.0.authorizedToDetach', false)
        ->assertJsonPath('query', ['search' => '', 'sort' => null, 'direction' => null, 'perPage' => 10, 'defaultSort' => null]);

    expect(array_keys($response->json('records')))->toBe(['data', 'current_page', 'last_page', 'per_page', 'total', 'from', 'to']);
});

it('returns an empty page with null from and to', function () {
    hmRelated($this->owner)->assertOk()
        ->assertJsonPath('records.total', 0)
        ->assertJsonPath('records.from', null)
        ->assertJsonPath('records.to', null);
});

it('applies the related index query on top of the parent constraint', function () {
    hmPosts($this->owner, 3);
    Entities\PanelPost::$indexUsing = fn ($query) => $query->where('title', '!=', 'Post 2');

    hmRelated($this->owner)->assertOk()->assertJsonPath('records.total', 2);
});

it('searches, sorts and paginates the related list', function () {
    hmPosts($this->owner, 12);

    hmRelated($this->owner, 'search='.urlencode(' Post 1 '))->assertOk()->assertJsonPath('records.total', 4)->assertJsonPath('query.search', 'Post 1');
    hmRelated($this->owner, 'sort=title&direction=asc')->assertOk()->assertJsonPath('records.data.0.title', 'Post 1')->assertJsonPath('query.direction', 'asc');
    hmRelated($this->owner, 'page=2')->assertOk()->assertJsonPath('records.current_page', 2)->assertJsonCount(2, 'records.data');
    hmRelated($this->owner, 'perPage=25')->assertOk()->assertJsonPath('records.per_page', 25)->assertJsonCount(12, 'records.data');
});

it('hardens the related query parameters like the index', function (string $query) {
    hmPosts($this->owner, 2);

    hmRelated($this->owner, $query)->assertOk()->assertJsonPath('records.total', 2)->assertJsonPath('query.sort', null)->assertJsonPath('query.perPage', 10);
})->with([
    'array search' => 'search[]=a',
    'unknown sort' => 'sort=password',
    'injected sort' => 'sort[]=title',
    'bad perPage' => 'perPage=100000',
    'array perPage' => 'perPage[]=5',
    'bad page' => 'page=abc',
]);

it('escapes search wildcards', function () {
    hmPosts($this->owner, 2);

    hmRelated($this->owner, 'search=%25')->assertOk()->assertJsonPath('records.total', 0);
});

it('denies the related list when not permitted', function (string $case) {
    match ($case) {
        'panel access' => Larsadmin::auth(fn () => false),
        'parent viewAny' => [Gate::policy(User::class, UserPolicy::class), UserPolicy::$viewAny = false],
        'parent view' => [Gate::policy(User::class, UserPolicy::class), UserPolicy::$view = false],
        'child viewAny' => Gate::policy(Post::class, UpdateOnlyPolicy::class),
    };

    hmRelated($this->owner)->assertForbidden();
})->with(['panel access', 'parent viewAny', 'parent view', 'child viewAny']);

it('returns 404 for unknown parents, records and fields', function () {
    $this->getJson('/admin/api/entities/nope/1/related/posts')->assertNotFound();
    $this->getJson('/admin/api/entities/users/999/related/posts')->assertNotFound();
    $this->getJson('/admin/api/entities/users/abc/related/posts')->assertNotFound();
    $this->getJson("/admin/api/entities/users/{$this->owner->id}/related/name")->assertNotFound();
    $this->getJson("/admin/api/entities/users/{$this->owner->id}/related/missing")->assertNotFound();
});

it('serves per-row abilities from the related policy', function () {
    hmPosts($this->owner, 1);
    Gate::policy(Post::class, PostPolicy::class);

    hmRelated($this->owner)->assertOk()
        ->assertJsonPath('records.data.0.authorizedToUpdate', true)
        ->assertJsonPath('records.data.0.authorizedToDelete', false);

    PostPolicy::$allowDelete = true;

    hmRelated($this->owner)->assertJsonPath('records.data.0.authorizedToDelete', true);
});

it('keeps the query count constant regardless of the row count', function () {
    $count = function (int $rows) {
        $user = hmUser("User{$rows}");
        hmPosts($user, $rows);

        DB::flushQueryLog();
        DB::enableQueryLog();
        hmRelated($user, 'perPage=25')->assertOk()->assertJsonCount($rows, 'records.data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(25))->toBe($count(2));
});

it('deletes a child from the panel and returns to the parent detail', function () {
    hmPosts($this->owner, 1);
    $post = Post::first();
    PostPolicy::$allowDelete = true;
    Gate::policy(Post::class, PostPolicy::class);

    $this->delete("/admin/entities/posts/{$post->id}?viaEntity=users&viaId={$this->owner->id}&viaField=author")
        ->assertRedirect("/admin/entities/users/{$this->owner->id}")
        ->assertSessionHas('larsadmin.toast.type', 'success');

    expect(Post::count())->toBe(0);
});

it('redirects to the child index when the via parameters are invalid', function () {
    hmPosts($this->owner, 1);
    $post = Post::first();
    PostPolicy::$allowDelete = true;
    Gate::policy(Post::class, PostPolicy::class);

    $this->delete("/admin/entities/posts/{$post->id}?viaEntity=users&viaId=999&viaField=author")
        ->assertRedirect('/admin/entities/posts');
});

it('prefills the author when creating through the panel link and returns to the parent', function () {
    $this->get("/admin/entities/posts/create?viaEntity=users&viaId={$this->owner->id}&viaField=author")
        ->assertInertia(fn (Assert $page) => $page->where('fields.1.value', $this->owner->id)->where('fields.1.locked', true));

    $this->post("/admin/entities/posts?viaEntity=users&viaId={$this->owner->id}&viaField=author", [
        'title' => 'Via panel', 'author' => $this->owner->id,
    ])->assertRedirect("/admin/entities/users/{$this->owner->id}");

    expect(Post::first()->author_id)->toBe($this->owner->id);
});
