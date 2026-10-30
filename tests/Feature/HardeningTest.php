<?php

use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\DenyBeforePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities;
use Maxcxam\Larsadmin\Tests\Fixtures\Note;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\UpdateOnlyPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([
        Entities\Post::class,
        Entities\ScopedPost::class,
        Entities\OpenPost::class,
        Entities\Note::class,
    ]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

function makePost(array $attributes = []): Post
{
    return Post::create(array_merge(['title' => 'Hello', 'status' => 'draft'], $attributes));
}

// SEC-1

it('denies abilities missing from an existing policy (fails closed)', function () {
    Gate::policy(Post::class, UpdateOnlyPolicy::class);
    $post = makePost();

    $this->get('/admin/entities/posts')->assertForbidden();
    $this->get('/admin/entities/posts/create')->assertForbidden();
    $this->post('/admin/entities/posts', ['title' => 'X', 'status' => 'live'])->assertForbidden();
    $this->get("/admin/entities/posts/{$post->id}")->assertForbidden();
    $this->delete("/admin/entities/posts/{$post->id}")->assertForbidden();
    expect(Post::count())->toBe(1);
});

it('honours policy before() hooks', function () {
    Gate::policy(Post::class, DenyBeforePolicy::class);
    PostPolicy::$allowDelete = true;
    $post = makePost();

    $this->get('/admin/entities/posts')->assertForbidden();
    $this->get("/admin/entities/posts/{$post->id}/edit")->assertForbidden();
    $this->put("/admin/entities/posts/{$post->id}", ['title' => 'X', 'status' => 'live'])->assertForbidden();
    $this->delete("/admin/entities/posts/{$post->id}")->assertForbidden();
    expect(Post::count())->toBe(1);
});

it('allows models without a policy only when allow_without_policy is true', function () {
    $this->get('/admin/entities/posts')->assertOk();

    config(['larsadmin.allow_without_policy' => false]);
    $this->get('/admin/entities/posts')->assertForbidden();
});

// SEC-8

it('checks viewAny before resolving the record so existence is not revealed', function () {
    Gate::policy(Post::class, UpdateOnlyPolicy::class);
    $post = makePost();

    foreach ([$post->id, 9999] as $id) {
        $this->get("/admin/entities/posts/{$id}")->assertForbidden();
        $this->get("/admin/entities/posts/{$id}/edit")->assertForbidden();
        $this->put("/admin/entities/posts/{$id}", [])->assertForbidden();
        $this->delete("/admin/entities/posts/{$id}")->assertForbidden();
    }
});

// SEC-3

it('resolves records through detailQuery, which defaults to indexQuery', function () {
    $draft = makePost(['status' => 'draft']);
    $live = makePost(['status' => 'live']);

    $this->get("/admin/entities/scoped-posts/{$live->id}")->assertOk();
    $this->get("/admin/entities/scoped-posts/{$draft->id}")->assertNotFound();
    $this->get("/admin/entities/scoped-posts/{$draft->id}/edit")->assertNotFound();
    $this->put("/admin/entities/scoped-posts/{$draft->id}", ['title' => 'X', 'status' => 'live'])->assertNotFound();
    $this->delete("/admin/entities/scoped-posts/{$draft->id}")->assertNotFound();

    expect($draft->fresh())->title->toBe('Hello');
});

it('lets an entity override detailQuery', function () {
    $draft = makePost(['status' => 'draft']);

    $this->get("/admin/entities/open-posts/{$draft->id}")->assertOk();
});

// SEC-4 / CR-C3 / SEC-7

it('rejects select values that are not options', function () {
    $this->post('/admin/entities/posts', ['title' => 'X', 'status' => 'superadmin'])
        ->assertSessionHasErrors('status');

    expect(Post::count())->toBe(0);
});

it('validates number, date, boolean and string input implicitly', function () {
    $base = ['title' => 'X', 'status' => 'live'];

    $this->post('/admin/entities/posts', $base + ['views' => 'abc'])->assertSessionHasErrors('views');
    $this->post('/admin/entities/posts', $base + ['views' => '-1'])->assertSessionHasErrors('views');
    $this->post('/admin/entities/posts', $base + ['published_at' => 'not a date'])->assertSessionHasErrors('published_at');
    $this->post('/admin/entities/posts', $base + ['published' => 'maybe'])->assertSessionHasErrors('published');
    $this->post('/admin/entities/posts', ['title' => ['a'], 'status' => 'live'])->assertSessionHasErrors('title');
    $this->post('/admin/entities/posts', $base + ['body' => ['a']])->assertSessionHasErrors('body');

    expect(Post::count())->toBe(0);
});

it('accepts null for nullable fields and valid values', function () {
    $this->post('/admin/entities/posts', [
        'title' => 'X', 'status' => 'live', 'body' => null, 'published_at' => null, 'views' => '5',
    ])->assertSessionHasNoErrors();

    expect(Post::first())->views->toBe(5)->published_at->toBeNull();
});

// CR-C2

it('keeps seconds of datetime values when editing', function () {
    $post = makePost(['published_at' => '2026-10-29 10:00:37']);

    $this->get("/admin/entities/posts/{$post->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.5.value', '2026-10-29T10:00:37'));

    $this->put("/admin/entities/posts/{$post->id}", [
        'title' => 'Hello', 'status' => 'draft', 'published_at' => '2026-10-29T10:00:37',
    ])->assertSessionHasNoErrors();

    expect($post->fresh()->published_at->format('H:i:s'))->toBe('10:00:37');
});

// CR-C1

it('accepts pipe-string rules on fields end to end', function () {
    $this->post('/admin/entities/posts', ['title' => str_repeat('a', 256), 'status' => 'live'])
        ->assertSessionHasErrors('title');
});

// CR-W10

it('stores an empty string for empty optional non-nullable text input', function () {
    $this->post('/admin/entities/posts', [
        'title' => 'X', 'status' => 'live', 'subtitle' => null, 'body' => null,
    ])->assertSessionHasNoErrors();

    $post = Post::first();

    expect($post->subtitle)->toBe('')->and($post->body)->toBeNull();
});

// CR-W2

it('never fails on array query parameters', function () {
    makePost();

    $this->get('/admin/entities/posts?search[]=x&sort[]=title&direction[]=asc&perPage[]=50&page[]=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('query.search', '')
            ->where('query.sort', null)
            ->where('query.perPage', 25)
            ->has('records.data', 1));

    $this->get('/admin/entities/posts?page=abc&perPage=1e3')->assertOk();
});

// CR-W3

it('returns 404 for malformed integer ids without querying', function () {
    foreach (['abc', '1e1', '1.5', '-1', '99999999999999999999999'] as $id) {
        $this->get("/admin/entities/posts/{$id}")->assertNotFound();
        $this->delete("/admin/entities/posts/{$id}")->assertNotFound();
    }
});

it('supports string primary keys for show and search', function () {
    $note = Note::create(['title' => 'Memo']);
    Note::create(['title' => 'Other']);

    $this->get("/admin/entities/notes/{$note->id}")->assertOk();
    $this->get('/admin/entities/notes/not-a-real-uuid')->assertNotFound();

    $this->get("/admin/entities/notes?search={$note->id}")->assertInertia(fn (Assert $page) => $page
        ->has('records.data', 1)
        ->where('records.data.0.title', 'Memo'));
});

it('redirects updates to the canonical record key', function () {
    $post = makePost();

    $this->put('/admin/entities/posts/0'.$post->id, ['title' => 'X', 'status' => 'live'])
        ->assertRedirect("/admin/entities/posts/{$post->id}");

    $note = Note::create(['title' => 'Memo']);
    $this->put("/admin/entities/notes/{$note->id}", ['title' => 'New'])
        ->assertRedirect("/admin/entities/notes/{$note->id}");
});

// S2

it('returns to the index with the previous query string after deleting', function () {
    $post = makePost();

    $this->delete("/admin/entities/posts/{$post->id}", [], ['HTTP_REFERER' => url('/admin/entities/posts?search=x&page=2')])
        ->assertRedirect('/admin/entities/posts?search=x&page=2');
});

it('ignores a referer that is not the same entity index after deleting', function () {
    $post = makePost();
    $other = makePost();
    $third = makePost();

    $this->delete("/admin/entities/posts/{$post->id}", [], ['HTTP_REFERER' => url('/admin/entities/notes?search=x')])
        ->assertRedirect('/admin/entities/posts');

    $this->delete("/admin/entities/posts/{$other->id}", [], ['HTTP_REFERER' => 'https://evil.test/admin/entities/posts?a=1'])
        ->assertRedirect('/admin/entities/posts');

    $this->delete("/admin/entities/posts/{$third->id}")->assertRedirect('/admin/entities/posts');
});
