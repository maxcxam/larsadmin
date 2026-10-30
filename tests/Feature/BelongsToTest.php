<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\DenyBeforePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities;
use Maxcxam\Larsadmin\Tests\Fixtures\NoCreatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\NoUpdatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Maxcxam\Larsadmin\Tests\Fixtures\UserPolicy;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Entities\AuthoredPost::class, Entities\VisibleUser::class]);

    Entities\AuthoredPost::$required = false;
    Entities\AuthoredPost::$relatableUsing = null;
    UserPolicy::$viewAny = true;
    UserPolicy::$view = true;
    PostPolicy::$allowDelete = false;

    $this->admin = makeUser('Admin');
    $this->actingAs($this->admin);
});

function makeUser(string $name): User
{
    return User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@example.com', 'password' => 'secret']);
}

function authoredPost(array $attributes = []): Post
{
    return Post::create(array_merge(['title' => 'Hello', 'status' => 'draft'], $attributes));
}

function options(string $query = 'context=create', string $field = 'author', string $entity = 'posts')
{
    return test()->getJson("/admin/api/entities/{$entity}/fields/{$field}/options?{$query}", ['X-Requested-With' => 'XMLHttpRequest']);
}

it('lists options newest first with the contract shape', function () {
    makeUser('Alice');

    options()->assertOk()
        ->assertExactJson([
            'data' => [['id' => 2, 'title' => 'Alice'], ['id' => 1, 'title' => 'Admin']],
            'hasMore' => false,
        ]);
});

it('paginates options without a count query', function () {
    foreach (range(1, 29) as $i) {
        makeUser("User {$i}");
    }

    $first = options()->assertOk()->json();
    expect($first['data'])->toHaveCount(25)->and($first['hasMore'])->toBeTrue();

    $second = options('context=create&page=2')->assertOk()->json();
    expect($second['data'])->toHaveCount(5)->and($second['hasMore'])->toBeFalse()
        ->and($second['data'][0]['id'])->toBeLessThan($first['data'][24]['id']);
});

it('searches options', function () {
    makeUser('Alice Smith');
    makeUser('Bob');

    options('context=create&search='.urlencode('  alice '))->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Alice Smith');
});

it('serves update context options for an editable record', function () {
    $post = authoredPost();

    options("context=update&resourceId={$post->id}")->assertOk()->assertJsonCount(1, 'data');
});

it('rejects malformed query parameters', function (string $query) {
    options($query)->assertUnprocessable();
})->with([
    'array search' => 'context=create&search[]=a',
    'long search' => 'context=create&search='.str_repeat('a', 101),
    'array page' => 'context=create&page[]=1',
    'page zero' => 'context=create&page=0',
    'page too large' => 'context=create&page=100000',
    'page text' => 'context=create&page=abc',
    'missing context' => 'search=a',
    'unsupported context' => 'context=bogus&resourceId=1',
    'attach without record' => 'context=attach',
    'update without record' => 'context=update',
    'array resource' => 'context=update&resourceId[]=1',
]);

it('requires authentication and panel access', function () {
    Larsadmin::auth(fn () => false);
    options()->assertForbidden();

    Larsadmin::auth(fn () => true);
    auth()->logout();
    options()->assertUnauthorized();
});

it('denies options without the parent entity abilities', function (string $policy, string $query) {
    Gate::policy(Post::class, $policy);
    $post = authoredPost();

    options(str_replace('{id}', (string) $post->id, $query))->assertForbidden();
})->with([
    'no create' => [NoCreatePolicy::class, 'context=create'],
    'no update' => [NoUpdatePolicy::class, 'context=update&resourceId={id}'],
]);

it('denies options without parent viewAny', function () {
    Gate::policy(Post::class, DenyBeforePolicy::class);

    options()->assertForbidden();
});

it('denies options without related viewAny', function () {
    Gate::policy(User::class, UserPolicy::class);
    UserPolicy::$viewAny = false;

    options()->assertForbidden();
});

it('returns 404 for unknown parents, fields and records', function () {
    options('context=create', 'author', 'missing')->assertNotFound();
    options('context=create', 'nope')->assertNotFound();
    options('context=create', 'title')->assertNotFound();
    options('context=update&resourceId=999')->assertNotFound();
    options('context=update&resourceId=abc')->assertNotFound();
});

it('applies the related index scope and relatableQueryUsing', function () {
    makeUser('Hidden Harry');
    makeUser('Alice');
    makeUser('Bob');

    options()->assertOk()->assertJsonCount(3, 'data')->assertJsonMissing(['title' => 'Hidden Harry']);

    Entities\AuthoredPost::$relatableUsing = fn ($request, $query, $record) => $query->where('name', '!=', 'Bob');

    options()->assertOk()->assertJsonCount(2, 'data')->assertJsonMissing(['title' => 'Bob']);
});

it('passes the edited record to relatableQueryUsing', function () {
    $post = authoredPost();
    $seen = [];
    Entities\AuthoredPost::$relatableUsing = function ($request, $query, $record) use (&$seen) {
        $seen[] = $record?->getKey();

        return $query;
    };

    options('context=create')->assertOk();
    options("context=update&resourceId={$post->id}")->assertOk();

    expect($seen)->toBe([null, $post->id]);
});

it('stores the author and rejects ids outside the scope', function () {
    $alice = makeUser('Alice');
    $hidden = makeUser('Hidden Harry');

    $this->post('/admin/entities/posts', ['title' => 'A', 'author' => $hidden->id])->assertSessionHasErrors('author');
    $this->post('/admin/entities/posts', ['title' => 'A', 'author' => 9999])->assertSessionHasErrors('author');
    $this->post('/admin/entities/posts', ['title' => 'A', 'author' => ['1']])->assertSessionHasErrors('author');
    $this->post('/admin/entities/posts', ['title' => 'A', 'author' => 'abc'])->assertSessionHasErrors('author');
    expect(Post::count())->toBe(0);

    $this->post('/admin/entities/posts', ['title' => 'A', 'author' => (string) $alice->id])->assertSessionDoesntHaveErrors();
    expect(Post::first()->author_id)->toBe($alice->id);
});

it('rejects ids outside relatableQueryUsing and without related viewAny', function () {
    $bob = makeUser('Bob');
    Entities\AuthoredPost::$relatableUsing = fn ($request, $query) => $query->where('name', '!=', 'Bob');

    $this->post('/admin/entities/posts', ['title' => 'A', 'author' => $bob->id])->assertSessionHasErrors('author');

    Entities\AuthoredPost::$relatableUsing = null;
    Gate::policy(User::class, UserPolicy::class);
    UserPolicy::$viewAny = false;

    $this->post('/admin/entities/posts', ['title' => 'A', 'author' => $bob->id])->assertSessionHasErrors('author');
});

it('accepts a nullable author and requires a non-nullable one', function () {
    $this->post('/admin/entities/posts', ['title' => 'A', 'author' => ''])->assertSessionDoesntHaveErrors();
    expect(Post::first()->author_id)->toBeNull();

    Entities\AuthoredPost::$required = true;

    $this->post('/admin/entities/posts', ['title' => 'B', 'author' => ''])->assertSessionHasErrors('author');
    $this->post('/admin/entities/posts', ['title' => 'B'])->assertSessionHasErrors('author');
});

it('keeps an unchanged out-of-scope author on update but not a new one', function () {
    $hidden = makeUser('Hidden Harry');
    $other = makeUser('Hidden Hank');
    $post = authoredPost(['author_id' => $hidden->id]);

    $this->put("/admin/entities/posts/{$post->id}", ['title' => 'Renamed', 'author' => $hidden->id])
        ->assertSessionDoesntHaveErrors();
    expect($post->fresh()->title)->toBe('Renamed');

    $this->put("/admin/entities/posts/{$post->id}", ['title' => 'Renamed', 'author' => $other->id])
        ->assertSessionHasErrors('author');
});

it('changes and clears the author on update', function () {
    $alice = makeUser('Alice');
    $post = authoredPost(['author_id' => $this->admin->id]);

    $this->put("/admin/entities/posts/{$post->id}", ['title' => 'T', 'author' => $alice->id]);
    expect($post->fresh()->author_id)->toBe($alice->id);

    $this->put("/admin/entities/posts/{$post->id}", ['title' => 'T', 'author' => null]);
    expect($post->fresh()->author_id)->toBeNull();
});

it('serializes the field for the edit form', function () {
    $post = authoredPost(['author_id' => $this->admin->id]);

    $this->get("/admin/entities/posts/{$post->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.1.component', 'belongs-to-field')
        ->where('record.fields.1.value', $this->admin->id)
        ->where('record.fields.1.selected', ['id' => $this->admin->id, 'title' => 'Admin'])
        ->where('record.fields.1.link', null)
        ->where('record.fields.1.optionsUrl', '/admin/api/entities/posts/fields/author/options')
        ->where('record.fields.1.searchable', true)
        ->where('record.fields.1.nullable', true)
        ->where('record.fields.1.locked', false));
});

it('serializes the display value and link on detail, and hides the link when not viewable', function () {
    $post = authoredPost(['author_id' => $this->admin->id]);

    $this->get("/admin/entities/posts/{$post->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.displayedAs', 'Admin')
        ->where('record.fields.2.link', "/admin/entities/users/{$this->admin->id}"));

    Gate::policy(User::class, UserPolicy::class);
    UserPolicy::$view = false;

    $this->get("/admin/entities/posts/{$post->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.displayedAs', 'Admin')
        ->where('record.fields.2.link', null));
});

it('renders empty values', function () {
    $post = authoredPost();

    $this->get("/admin/entities/posts/{$post->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.value', null)
        ->where('record.fields.2.displayedAs', null)
        ->where('record.fields.2.selected', null)
        ->where('record.fields.2.link', null));
});

it('eager loads the relation on index with a constant query count', function () {
    $count = function (int $posts) {
        Post::query()->delete();
        foreach (range(1, $posts) as $i) {
            authoredPost(['title' => "P{$i}", 'author_id' => $this->admin->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/admin/entities/posts')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count(2);
    $large = $count(25);

    expect($large)->toBe($small);
});

it('checks related visibility in one query for distinct visible and hidden authors on index', function () {
    $count = function (int $posts) {
        Post::query()->delete();
        User::query()->where('id', '!=', $this->admin->id)->delete();

        foreach (range(1, $posts) as $i) {
            $author = makeUser($i % 2 ? "Author {$i}" : "Hidden {$i}");
            authoredPost(['title' => "P{$i}", 'author_id' => $author->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get('/admin/entities/posts')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $authors = collect($response->viewData('page')['props']['records']['data'])
            ->map(fn ($row) => collect($row['fields'])->firstWhere('attribute', 'author'));

        expect($authors->where('hidden', true)->pluck('displayedAs')->filter()->all())->toBe([])
            ->and($authors->where('hidden', false)->pluck('displayedAs')->filter())->not->toBeEmpty();

        return $queries;
    };

    expect($count(25))->toBe($count(2));
});

it('shows a visible but not viewable author with its title and no link', function () {
    Gate::policy(User::class, UserPolicy::class);
    UserPolicy::$view = false;
    $post = authoredPost(['author_id' => $this->admin->id]);

    $field = collect($this->get('/admin/entities/posts')->assertOk()->viewData('page')['props']['records']['data'])
        ->firstWhere('id', $post->id)['fields'];
    $author = collect($field)->firstWhere('attribute', 'author');

    expect($author['hidden'])->toBeFalse()
        ->and($author['displayedAs'])->toBe('Admin')
        ->and($author['link'])->toBeNull();
});

it('sorts the index by the related title', function () {
    $zed = makeUser('Zed');
    $alice = makeUser('Alice');
    authoredPost(['title' => 'ByZed', 'author_id' => $zed->id]);
    authoredPost(['title' => 'ByAlice', 'author_id' => $alice->id]);
    authoredPost(['title' => 'ByAdmin', 'author_id' => $this->admin->id]);

    $titles = fn (string $direction) => collect($this->get("/admin/entities/posts?sort=author&direction={$direction}")
        ->assertOk()->viewData('page')['props']['records']['data'])->pluck('title')->all();

    expect($titles('asc'))->toBe(['ByAdmin', 'ByAlice', 'ByZed'])
        ->and($titles('desc'))->toBe(['ByZed', 'ByAlice', 'ByAdmin']);
});

it('prefills and locks the author from valid via parameters on create', function () {
    $this->get("/admin/entities/posts/create?viaEntity=users&viaId={$this->admin->id}&viaField=author")
        ->assertInertia(fn (Assert $page) => $page
            ->where('fields.1.value', $this->admin->id)
            ->where('fields.1.locked', true)
            ->where('fields.1.selected', ['id' => $this->admin->id, 'title' => 'Admin']));
});

it('ignores invalid via parameters', function (string $query) {
    makeUser('Hidden Harry');

    $this->get('/admin/entities/posts/create?'.$query)->assertInertia(fn (Assert $page) => $page
        ->where('fields.1.value', null)
        ->where('fields.1.locked', false)
        ->where('fields.1.selected', null));
})->with([
    'wrong field' => 'viaEntity=users&viaId=1&viaField=title',
    'unknown field' => 'viaEntity=users&viaId=1&viaField=nope',
    'wrong entity' => 'viaEntity=posts&viaId=1&viaField=author',
    'unknown entity' => 'viaEntity=missing&viaId=1&viaField=author',
    'missing id' => 'viaEntity=users&viaId=999&viaField=author',
    'out of scope id' => 'viaEntity=users&viaId=2&viaField=author',
    'array id' => 'viaEntity=users&viaId[]=1&viaField=author',
    'non numeric id' => 'viaEntity=users&viaId=abc&viaField=author',
]);

it('ignores via parameters without related viewAny', function () {
    Gate::policy(User::class, UserPolicy::class);
    UserPolicy::$viewAny = false;

    $this->get("/admin/entities/posts/create?viaEntity=users&viaId={$this->admin->id}&viaField=author")
        ->assertInertia(fn (Assert $page) => $page->where('fields.1.locked', false)->where('fields.1.value', null));
});

it('returns to the via parent after store, update and destroy', function () {
    Gate::policy(Post::class, PostPolicy::class);
    PostPolicy::$allowDelete = true;
    $via = "?viaEntity=users&viaId={$this->admin->id}&viaField=author";
    $parent = "/admin/entities/users/{$this->admin->id}";

    $this->post('/admin/entities/posts'.$via, ['title' => 'A', 'author' => $this->admin->id])->assertRedirect($parent);

    $post = Post::first();

    $this->put("/admin/entities/posts/{$post->id}".$via, ['title' => 'B', 'author' => $this->admin->id])->assertRedirect($parent);
    $this->delete("/admin/entities/posts/{$post->id}".$via)->assertRedirect($parent);
    expect(Post::count())->toBe(0);
});

it('never redirects to an invalid or foreign via target', function () {
    $this->post('/admin/entities/posts?viaEntity=users&viaId=999&viaField=author', ['title' => 'A', 'author' => ''])
        ->assertRedirect('/admin/entities/posts/1');

    $this->post('/admin/entities/posts?viaEntity=https://evil.test&viaId=1&viaField=author', ['title' => 'B', 'author' => ''])
        ->assertRedirect('/admin/entities/posts/2');
});

it('resolves the related entity from the registered model', function () {
    expect(Larsadmin::entityForModel(User::class))->toBe(Entities\VisibleUser::class)
        ->and(Larsadmin::entityForModel(Post::class))->toBe(Entities\AuthoredPost::class)
        ->and(Larsadmin::entityForModel('Nope'))->toBeNull();
});

it('rejects a relatable limit outside 1..100', function () {
    expect(fn () => BelongsTo::make('A', 'author')->relatableLimit(101))->toThrow(InvalidArgumentException::class);
});

it('fails clearly when the attribute is not a BelongsTo relation', function () {
    $field = BelongsTo::make('Title', 'title', Entities\VisibleUser::class);
    $field->bindTo(request(), Entities\AuthoredPost::class);

    expect(fn () => $field->fillAttribute())->toThrow(LogicException::class);
});
