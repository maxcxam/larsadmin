<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Entities\Post::class, Entities\User::class]);

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->admin);
});

function post(array $attributes = []): Post
{
    return Post::create(array_merge(['title' => 'Hello', 'status' => 'draft'], $attributes));
}

it('derives entity keys and labels from the class name', function () {
    expect(Entities\Post::uriKey())->toBe('posts')
        ->and(Entities\Post::label())->toBe('Posts')
        ->and(Entities\Post::singularLabel())->toBe('Post')
        ->and(Larsadmin::entityForKey('users'))->toBe(Entities\User::class)
        ->and(Larsadmin::entityForKey('missing'))->toBeNull();
});

it('shares the navigation', function () {
    $this->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->has('navigation', 2)
        ->where('navigation.1', ['uriKey' => 'users', 'label' => 'Users', 'group' => 'Access']));
});

it('returns 404 for unknown entities', function () {
    $this->get('/admin/entities/missing')->assertNotFound();
});

it('lists records with index fields', function () {
    post(['title' => 'First', 'body' => 'Long text']);

    $this->get('/admin/entities/posts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Entities/Index')
            ->where('entity.label', 'Posts')
            ->where('columns.0.attribute', 'id')
            ->missing('columns.6')
            ->has('records.data', 1)
            ->where('records.data.0.title', 'First')
            ->where('records.data.0.fields.1.value', 'First')
            ->where('records.total', 1));
});

it('paginates records', function () {
    foreach (range(1, 30) as $i) {
        post(['title' => "Post {$i}"]);
    }

    $this->get('/admin/entities/posts?page=2')->assertInertia(fn (Assert $page) => $page
        ->has('records.data', 5)
        ->where('records.current_page', 2));

    $this->get('/admin/entities/posts?perPage=50')->assertInertia(fn (Assert $page) => $page
        ->has('records.data', 30)
        ->where('query.perPage', 50));

    $this->get('/admin/entities/posts?perPage=7')->assertInertia(fn (Assert $page) => $page
        ->where('query.perPage', 25));
});

it('searches the searchable columns', function () {
    post(['title' => 'Laravel tips']);
    post(['title' => 'Vue tricks']);

    $this->get('/admin/entities/posts?search=vue')->assertInertia(fn (Assert $page) => $page
        ->has('records.data', 1)
        ->where('records.data.0.title', 'Vue tricks'));
});

it('sorts by sortable columns only', function () {
    post(['title' => 'B', 'views' => 5]);
    post(['title' => 'A', 'views' => 10]);
    post(['title' => 'C', 'views' => 1]);

    $this->get('/admin/entities/posts?sort=title&direction=asc')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.title', 'A')
        ->where('records.data.2.title', 'C'));

    $this->get('/admin/entities/posts?sort=views&direction=desc')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.title', 'A'));

    $this->get('/admin/entities/posts?sort=status')->assertInertia(fn (Assert $page) => $page
        ->where('query.sort', null)
        ->where('records.data.0.title', 'C'));
});

it('shows a record', function () {
    $post = post(['title' => 'Detail', 'body' => 'Body text']);

    $this->get("/admin/entities/posts/{$post->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Entities/Detail')
            ->where('record.id', $post->id)
            ->where('record.title', 'Detail')
            ->where('record.fields.2.attribute', 'body'));
});

it('renders the create form with defaults', function () {
    $this->get('/admin/entities/posts/create')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Entities/Create')
            ->where('fields.0.attribute', 'title')
            ->where('fields.0.required', true)
            ->where('fields.2.value', 0)
            ->where('fields.3.value', false));
});

it('validates and stores a record', function () {
    $this->post('/admin/entities/posts', ['title' => '', 'status' => ''])
        ->assertSessionHasErrors(['title', 'status']);

    $this->post('/admin/entities/posts', [
        'title' => 'Created',
        'body' => null,
        'views' => '3',
        'published' => true,
        'status' => 'live',
        'published_at' => '2026-10-29T10:00',
    ])->assertRedirect('/admin/entities/posts/1')->assertSessionHas('larsadmin.toast', ['message' => 'Запис створено.', 'type' => 'success']);

    expect(Post::first())
        ->title->toBe('Created')
        ->views->toBe(3)
        ->published->toBeTrue()
        ->published_at->format('Y-m-d H:i')->toBe('2026-10-29 10:00');
});

it('updates a record', function () {
    $post = post();

    $this->get("/admin/entities/posts/{$post->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->component('Entities/Edit')
        ->where('record.fields.0.value', 'Hello'));

    $this->put("/admin/entities/posts/{$post->id}", ['title' => 'Changed', 'status' => 'live', 'published' => false])
        ->assertRedirect("/admin/entities/posts/{$post->id}");

    expect($post->fresh())->title->toBe('Changed')->status->toBe('live');
});

it('ignores the current record in unique rules and keeps the password', function () {
    $this->put("/admin/entities/users/{$this->admin->id}", [
        'name' => 'Renamed',
        'email' => 'admin@example.com',
        'password' => '',
    ])->assertSessionHasNoErrors();

    $admin = $this->admin->fresh();

    expect($admin->name)->toBe('Renamed')
        ->and(Hash::check('secret', $admin->password))->toBeTrue();
});

it('requires a password only when creating', function () {
    $this->post('/admin/entities/users', ['name' => 'New', 'email' => 'new@example.com'])
        ->assertSessionHasErrors('password');
});

it('deletes a record when the policy allows it', function () {
    Gate::policy(Post::class, PostPolicy::class);
    $post = post();

    PostPolicy::$allowDelete = false;
    $this->delete("/admin/entities/posts/{$post->id}")->assertForbidden();

    PostPolicy::$allowDelete = true;
    $this->delete("/admin/entities/posts/{$post->id}")
        ->assertRedirect('/admin/entities/posts')
        ->assertSessionHas('larsadmin.toast', ['message' => 'Запис видалено.', 'type' => 'success']);

    expect(Post::count())->toBe(0);
});

it('exposes per-record authorization', function () {
    Gate::policy(Post::class, PostPolicy::class);
    PostPolicy::$allowDelete = false;
    post();

    $this->get('/admin/entities/posts')->assertInertia(fn (Assert $page) => $page
        ->where('records.data.0.authorizedToView', true)
        ->where('records.data.0.authorizedToDelete', false));
});

it('generates an entity class', function () {
    $path = app_path('Larsadmin/Article.php');

    $this->artisan('larsadmin:entity', ['name' => 'Article'])->assertSuccessful();

    expect(file_get_contents($path))
        ->toContain('namespace App\Larsadmin;')
        ->toContain('class Article extends Entity')
        ->toContain('public static string $model = \App\Models\Article::class;');

    unlink($path);
});

it('builds fields once per entity instance per request', function () {
    Larsadmin::entities([Entities\SpyPost::class]);
    $post = post(['title' => 'Spy']);

    // The prototypes are built once per request and entity class, shared by every instance.
    Entities\SpyPost::$calls = 0;
    $this->get("/admin/entities/spy-posts/{$post->id}/edit")->assertOk();
    expect(Entities\SpyPost::$calls)->toBe(1);

    Entities\SpyPost::$calls = 0;
    $this->put("/admin/entities/spy-posts/{$post->id}", ['headline' => 'Changed'])->assertRedirect();
    expect(Entities\SpyPost::$calls)->toBe(1)
        ->and($post->fresh()->title)->toBe('Changed');
});

it('validates by request key and writes through the fill callback', function () {
    Larsadmin::entities([Entities\SpyPost::class]);

    $this->post('/admin/entities/spy-posts', ['title' => 'wrong key'])->assertSessionHasErrors('headline');

    $this->post('/admin/entities/spy-posts', ['headline' => 'Right'])->assertRedirect();
    expect(Post::where('title', 'Right')->exists())->toBeTrue();
});

it('rolls back the record when an after-save callback throws', function () {
    Larsadmin::entities([Entities\SpyPost::class]);
    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/admin/entities/spy-posts', ['headline' => 'Doomed', 'boom' => '1']))
        ->toThrow(RuntimeException::class, 'after-save failed')
        ->and(Post::where('title', 'Doomed')->exists())->toBeFalse();
});

it('searches wildcard characters literally on the index', function () {
    post(['title' => '50%_off']);
    post(['title' => '50 percent off']);

    $this->get('/admin/entities/posts?search='.urlencode('50%_off'))->assertInertia(fn (Assert $page) => $page
        ->has('records.data', 1)
        ->where('records.data.0.title', '50%_off'));
});

it('returns JSON 401 and 403 for panel routes', function () {
    config(['larsadmin.locale' => 'en']);
    auth()->logout();
    $this->getJson('/admin/entities/posts')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    $this->get('/admin/entities/posts')->assertRedirect(route('larsadmin.login'));

    $this->actingAs($this->admin);
    Larsadmin::auth(fn () => false);
    $this->getJson('/admin/entities/posts')->assertForbidden();
});

it('translates the JSON 401 and 403 messages', function () {
    config(['larsadmin.locale' => 'uk']);

    auth()->logout();
    $this->getJson('/admin/entities/posts')->assertUnauthorized()->assertExactJson(['message' => 'Потрібна автентифікація.']);

    $this->actingAs($this->admin);
    Larsadmin::auth(fn () => false);
    $this->getJson('/admin/entities/posts')->assertForbidden()->assertExactJson(['message' => 'Доступ заборонено.']);
});
