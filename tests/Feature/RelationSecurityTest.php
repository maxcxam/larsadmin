<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Security;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    config(['larsadmin.locale' => 'en']);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Security\SecPost::class, Security\SecMember::class, Security\SecTag::class]);
    PostPolicy::$allowDelete = false;

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->admin);

    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
    $this->hidden = User::create(['name' => 'Hidden Harry', 'email' => 'harry@example.com', 'password' => 'secret']);

    $this->post = Post::create(['title' => 'Visible', 'status' => 'draft', 'author_id' => $this->alice->id]);
    $this->secret = Post::create(['title' => 'Secret', 'status' => 'secret', 'author_id' => $this->alice->id]);
    $this->pointing = Post::create(['title' => 'Points at hidden', 'status' => 'draft', 'author_id' => $this->hidden->id]);

    $this->tag = Tag::create(['name' => 'Open']);
    $this->hiddenTag = Tag::create(['name' => 'Hidden Tag']);
});

function secJson(string $url)
{
    return test()->getJson($url, ['X-Requested-With' => 'XMLHttpRequest']);
}

function secProps($response): array
{
    return $response->viewData('page')['props'];
}

function secUrl(string $path): string
{
    $t = test();

    return str_replace(
        ['{post}', '{secret}', '{user}', '{hidden}', '{tag}'],
        [$t->post->id, $t->secret->id, $t->alice->id, $t->hidden->id, $t->tag->id],
        $path,
    );
}

function secRequest(string $method, string $path)
{
    return test()->call($method, secUrl($path), ['related' => test()->tag->id], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ]);
}

// Hidden records are unreachable through every read and write path

it('returns 404 for a hidden record on every record route', function (string $method, string $path) {
    test()->call($method, secUrl($path), ['title' => 'X', 'name' => 'X'])->assertNotFound();

    expect(Post::count())->toBe(3)->and(User::count())->toBe(3);
})->with([
    'post detail' => ['GET', '/admin/entities/posts/{secret}'],
    'post edit' => ['GET', '/admin/entities/posts/{secret}/edit'],
    'post update' => ['PUT', '/admin/entities/posts/{secret}'],
    'post destroy' => ['DELETE', '/admin/entities/posts/{secret}'],
    'user detail' => ['GET', '/admin/entities/users/{hidden}'],
    'user update' => ['PUT', '/admin/entities/users/{hidden}'],
    'user destroy' => ['DELETE', '/admin/entities/users/{hidden}'],
]);

it('returns 404 for a hidden parent on every relation endpoint', function (string $method, string $path) {
    $this->secret->tags()->attach($this->tag->id);

    secRequest($method, $path)->assertNotFound();

    expect($this->secret->tags()->count())->toBe(1);
})->with([
    'related tags' => ['GET', '/admin/api/entities/posts/{secret}/related/tags'],
    'related posts' => ['GET', '/admin/api/entities/users/{hidden}/related/posts'],
    'related drafts' => ['GET', '/admin/api/entities/users/{hidden}/related/drafts'],
    'attach' => ['POST', '/admin/entities/posts/{secret}/attach/tags'],
    'detach' => ['DELETE', '/admin/entities/posts/{secret}/attach/tags/{tag}'],
    'attach options' => ['GET', '/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={secret}'],
    'author options on update' => ['GET', '/admin/api/entities/posts/fields/author/options?context=update&resourceId={secret}'],
]);

it('answers a hidden and a missing resourceId identically on the options endpoint', function (string $context, string $field) {
    $hiddenResponse = secJson("/admin/api/entities/posts/fields/{$field}/options?context={$context}&resourceId={$this->secret->id}");
    $missingResponse = secJson("/admin/api/entities/posts/fields/{$field}/options?context={$context}&resourceId=99999");

    $hiddenResponse->assertNotFound();
    expect($hiddenResponse->status())->toBe($missingResponse->status())
        ->and($hiddenResponse->getContent())->toBe($missingResponse->getContent());
})->with([
    'update' => ['update', 'author'],
    'attach' => ['attach', 'tags'],
]);

it('keeps hidden records out of related lists and pickers', function () {
    $this->post->tags()->attach([$this->tag->id, $this->hiddenTag->id]);

    $tags = secJson("/admin/api/entities/posts/{$this->post->id}/related/tags")->assertOk();
    expect($tags->json('records.total'))->toBe(1)
        ->and(collect($tags->json('records.data'))->pluck('title')->all())->toBe(['Open']);

    $posts = secJson("/admin/api/entities/users/{$this->alice->id}/related/posts")->assertOk();
    expect(collect($posts->json('records.data'))->pluck('title')->all())->toBe(['Visible']);

    $other = Post::create(['title' => 'Other', 'status' => 'draft']);
    $options = secJson("/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={$other->id}")->assertOk();
    expect(collect($options->json('data'))->pluck('title')->all())->toBe(['Open'])
        ->and(json_encode($options->json()))->not->toContain('Hidden');

    foreach (['create', "update&resourceId={$this->post->id}"] as $context) {
        $authors = secJson("/admin/api/entities/posts/fields/author/options?context={$context}")->assertOk();
        expect(collect($authors->json('data'))->pluck('title')->all())->not->toContain('Hidden Harry');
    }
});

it('does not let searches reveal hidden records in pickers or panels', function () {
    $this->post->tags()->attach($this->hiddenTag->id);
    $other = Post::create(['title' => 'Other', 'status' => 'draft']);

    expect(secJson("/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={$other->id}&search=Hidden")->json('data'))->toBe([])
        ->and(secJson('/admin/api/entities/posts/fields/author/options?context=create&search=Harry')->json('data'))->toBe([])
        ->and(secJson("/admin/api/entities/posts/{$this->post->id}/related/tags?search=Hidden")->json('records.total'))->toBe(0);
});

// A visible record pointing at a hidden one

it('does not expose a hidden BelongsTo target on detail, index or its link', function () {
    $detail = secProps($this->get("/admin/entities/posts/{$this->pointing->id}")->assertOk());
    $author = collect($detail['record']['fields'])->firstWhere('attribute', 'author');

    expect($author['link'])->toBeNull()
        ->and($author['displayedAs'])->toBeNull()
        ->and(json_encode($detail))->not->toContain('Hidden Harry');

    expect(json_encode(secProps($this->get('/admin/entities/posts')->assertOk())))->not->toContain('Hidden Harry');
});

it('exposes a hidden BelongsTo target on the edit form only as the unchanged id with no link', function () {
    $edit = secProps($this->get("/admin/entities/posts/{$this->pointing->id}/edit")->assertOk());
    $author = collect($edit['record']['fields'])->firstWhere('attribute', 'author');

    expect($author['value'])->toBe($this->hidden->id)
        ->and($author['link'])->toBeNull()
        ->and($author['hidden'])->toBeTrue()
        ->and($author['displayedAs'])->toBeNull()
        ->and($author['selected'])->toBe(['id' => $this->hidden->id, 'title' => null, 'hidden' => true])
        ->and(json_encode(secProps($this->get("/admin/entities/posts/{$this->pointing->id}/edit"))))->not->toContain('Hidden Harry');
});

it('marks hidden BelongsTo targets as hidden on index and detail and visible ones normally', function () {
    $index = secProps($this->get('/admin/entities/posts')->assertOk());
    $rows = collect($index['records']['data'] ?? [])->keyBy('id');
    $authors = $rows->map(fn ($row) => collect($row['fields'])->firstWhere('attribute', 'author'));

    expect($authors[$this->pointing->id]['hidden'])->toBeTrue()
        ->and($authors[$this->pointing->id]['displayedAs'])->toBeNull()
        ->and($authors[$this->post->id]['hidden'])->toBeFalse()
        ->and($authors[$this->post->id]['displayedAs'])->toBe('Alice')
        ->and($authors[$this->post->id]['link'])->toBe("/admin/entities/users/{$this->alice->id}");

    $detail = secProps($this->get("/admin/entities/posts/{$this->pointing->id}")->assertOk());

    expect(collect($detail['record']['fields'])->firstWhere('attribute', 'author')['hidden'])->toBeTrue();
});

it('keeps an unchanged hidden author on update but rejects switching to another hidden one', function () {
    $other = User::create(['name' => 'Hidden Hank', 'email' => 'hank@example.com', 'password' => 'secret']);

    $this->put("/admin/entities/posts/{$this->pointing->id}", ['title' => 'Renamed', 'author' => $this->hidden->id])->assertSessionDoesntHaveErrors();
    $this->put("/admin/entities/posts/{$this->pointing->id}", ['title' => 'Renamed', 'author' => $other->id])->assertSessionHasErrors('author');

    expect($this->pointing->fresh()->author_id)->toBe($this->hidden->id);
});

// Writes of hidden ids

it('rejects hidden and malformed ids on create and update', function (string $method, mixed $author) {
    $url = $method === 'post' ? '/admin/entities/posts' : "/admin/entities/posts/{$this->post->id}";
    $author = $author === 'hidden' ? $this->hidden->id : $author;

    test()->{$method}($url, ['title' => 'Forged', 'author' => $author])->assertSessionHasErrors('author');

    expect(Post::where('title', 'Forged')->exists())->toBeFalse()
        ->and($this->post->fresh()->author_id)->toBe($this->alice->id);
})->with([
    'create hidden' => ['post', 'hidden'],
    'update hidden' => ['put', 'hidden'],
    'create sql-ish' => ['post', '1 or 1=1'],
    'update array' => ['put', [1]],
    'update unknown' => ['put', 424242],
]);

it('rejects attaching a hidden tag and attaching to a record that is not viewable', function () {
    $this->post("/admin/entities/posts/{$this->post->id}/attach/tags", ['related' => $this->hiddenTag->id])->assertSessionHasErrors('related');
    $this->post("/admin/entities/posts/{$this->secret->id}/attach/tags", ['related' => $this->tag->id])->assertNotFound();

    expect(DB::table('post_tag')->count())->toBe(0);
});

it('refuses to attach, detach or list options for a parent the user cannot update', function () {
    $this->post->tags()->attach($this->tag->id);
    $new = Tag::create(['name' => 'New']);
    Gate::policy(Post::class, Security\ViewOnlyPolicy::class);
    Gate::policy(Tag::class, Security\ViewOnlyPolicy::class);

    $this->delete("/admin/entities/posts/{$this->post->id}/attach/tags/{$this->tag->id}")->assertForbidden();
    $this->post("/admin/entities/posts/{$this->post->id}/attach/tags", ['related' => $new->id])->assertForbidden();
    secJson("/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={$this->post->id}")->assertForbidden();

    expect($this->post->tags()->pluck('tags.id')->all())->toBe([$this->tag->id]);
});

// via parameters

it('never lets via parameters prefill or lock a value the user could not pick', function (string $query) {
    $this->get('/admin/entities/posts/create?'.secUrl($query))->assertInertia(fn (Assert $page) => $page
        ->where('fields.1.attribute', 'author')
        ->where('fields.1.value', null)
        ->where('fields.1.locked', false)
        ->where('fields.1.selected', null));
})->with([
    'hidden user' => 'viaEntity=users&viaId={hidden}&viaField=author',
    'wrong entity key' => 'viaEntity=posts&viaId={secret}&viaField=author',
    'panel field name' => 'viaEntity=users&viaId={hidden}&viaField=tags',
    'url as entity' => 'viaEntity=//evil.test&viaId={hidden}&viaField=author',
    'nested arrays' => 'viaEntity[]=users&viaId[]={hidden}&viaField[]=author',
]);

it('ignores forged via parameters when redirecting after store, update and delete', function () {
    Gate::policy(Post::class, PostPolicy::class);
    PostPolicy::$allowDelete = true;
    $via = "?viaEntity=users&viaId={$this->hidden->id}&viaField=author";

    $this->post('/admin/entities/posts'.$via, ['title' => 'A', 'author' => ''])
        ->assertRedirect('/admin/entities/posts/'.Post::max('id'));

    $this->put("/admin/entities/posts/{$this->post->id}".$via, ['title' => 'B', 'author' => $this->alice->id])
        ->assertRedirect("/admin/entities/posts/{$this->post->id}");

    $this->delete("/admin/entities/posts/{$this->post->id}".$via)->assertRedirect('/admin/entities/posts');

    expect(Post::find($this->post->id))->toBeNull();
});

it('cannot delete through a panel with forged via parameters when the policy denies it', function () {
    Gate::policy(Post::class, PostPolicy::class);

    $this->delete("/admin/entities/posts/{$this->post->id}?viaEntity=users&viaId={$this->alice->id}&viaField=author")->assertForbidden();

    expect(Post::find($this->post->id))->not->toBeNull();
});

// Mixed contexts

it('rejects bad context combinations on the options endpoint', function (string $query, int $status) {
    secJson('/admin/api/entities/posts/fields/'.secUrl($query))->assertStatus($status);
})->with([
    'attach for a BelongsTo field' => ['author/options?context=attach&resourceId={post}', 404],
    'attach for a text field' => ['title/options?context=attach&resourceId={post}', 404],
    'create for a panel field' => ['tags/options?context=create', 404],
    'update for a panel field' => ['tags/options?context=update&resourceId={post}', 404],
    'unknown context' => ['tags/options?context=delete&resourceId={post}', 422],
    'missing context' => ['tags/options?resourceId={post}', 422],
    'attach without resourceId' => ['tags/options?context=attach', 422],
    'array resourceId' => ['tags/options?context=attach&resourceId[]=1', 422],
    'huge page' => ['tags/options?context=attach&resourceId={post}&page=100000', 422],
    'array search' => ['tags/options?context=attach&resourceId={post}&search[]=a', 422],
]);

// Fail-closed policy matrix applied across relations

dataset('relation requests', [
    'post detail' => ['GET', '/admin/entities/posts/{post}'],
    'post edit' => ['GET', '/admin/entities/posts/{post}/edit'],
    'related tags' => ['GET', '/admin/api/entities/posts/{post}/related/tags'],
    'related posts' => ['GET', '/admin/api/entities/users/{user}/related/posts'],
    'related drafts' => ['GET', '/admin/api/entities/users/{user}/related/drafts'],
    'attach options' => ['GET', '/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={post}'],
    'author options' => ['GET', '/admin/api/entities/posts/fields/author/options?context=update&resourceId={post}'],
    'attach' => ['POST', '/admin/entities/posts/{post}/attach/tags'],
    'detach' => ['DELETE', '/admin/entities/posts/{post}/attach/tags/{tag}'],
]);

it('denies every post request when a before() hook denies the model', function (string $method, string $path) {
    $this->post->tags()->attach($this->tag->id);
    Gate::policy(Post::class, Security\DenyAllPolicy::class);

    secRequest($method, $path)->assertForbidden();

    expect($this->post->tags()->count())->toBe(1);
})->with('relation requests');

it('denies the user panels when a before() hook denies the user model', function (string $path) {
    Gate::policy(User::class, Security\DenyAllPolicy::class);

    secRequest('GET', $path)->assertForbidden();
})->with([
    'related posts' => '/admin/api/entities/users/{user}/related/posts',
    'related drafts' => '/admin/api/entities/users/{user}/related/drafts',
    'author options' => '/admin/api/entities/posts/fields/author/options?context=create',
]);

it('denies every tag-bound request when the related model denies everything', function (string $method, string $path) {
    $this->post->tags()->attach($this->tag->id);
    Gate::policy(Tag::class, Security\DenyAllPolicy::class);

    secRequest($method, $path)->assertForbidden();

    expect($this->post->tags()->count())->toBe(1);
})->with([
    'related tags' => ['GET', '/admin/api/entities/posts/{post}/related/tags'],
    'attach options' => ['GET', '/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={post}'],
    'attach' => ['POST', '/admin/entities/posts/{post}/attach/tags'],
    'detach' => ['DELETE', '/admin/entities/posts/{post}/attach/tags/{tag}'],
]);

it('fails closed when the policy lacks update and the attach abilities', function (string $method, string $path) {
    $this->post->tags()->attach($this->tag->id);
    Gate::policy(Post::class, Security\ViewOnlyPolicy::class);
    Gate::policy(Tag::class, Security\ViewOnlyPolicy::class);

    secRequest($method, $path)->assertForbidden();

    expect($this->post->tags()->count())->toBe(1)->and(Post::count())->toBe(3);
})->with([
    'attach options' => ['GET', '/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={post}'],
    'author options' => ['GET', '/admin/api/entities/posts/fields/author/options?context=update&resourceId={post}'],
    'attach' => ['POST', '/admin/entities/posts/{post}/attach/tags'],
    'detach' => ['DELETE', '/admin/entities/posts/{post}/attach/tags/{tag}'],
    'edit' => ['GET', '/admin/entities/posts/{post}/edit'],
    'update' => ['PUT', '/admin/entities/posts/{post}'],
    'destroy' => ['DELETE', '/admin/entities/posts/{post}'],
]);

// Guests and non-panel users

dataset('json endpoints', [
    'related tags' => '/admin/api/entities/posts/{post}/related/tags',
    'related posts' => '/admin/api/entities/users/{user}/related/posts',
    'attach options' => '/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={post}',
    'author options' => '/admin/api/entities/posts/fields/author/options?context=create',
]);

dataset('inertia routes', [
    'dashboard' => ['GET', '/admin'],
    'index' => ['GET', '/admin/entities/posts'],
    'create' => ['GET', '/admin/entities/posts/create'],
    'store' => ['POST', '/admin/entities/posts'],
    'detail' => ['GET', '/admin/entities/posts/{post}'],
    'edit' => ['GET', '/admin/entities/posts/{post}/edit'],
    'update' => ['PUT', '/admin/entities/posts/{post}'],
    'destroy' => ['DELETE', '/admin/entities/posts/{post}'],
    'attach' => ['POST', '/admin/entities/posts/{post}/attach/tags'],
    'detach' => ['DELETE', '/admin/entities/posts/{post}/attach/tags/{tag}'],
]);

it('answers guests on JSON endpoints with a JSON 401', function (string $path) {
    $this->app['auth']->forgetGuards();

    secJson(secUrl($path))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
})->with('json endpoints');

it('answers non-panel users on JSON endpoints with a JSON 403', function (string $path) {
    Larsadmin::auth(fn () => false);

    secJson(secUrl($path))->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
})->with('json endpoints');

it('redirects guests away from every Inertia route and changes nothing', function (string $method, string $path) {
    $this->post->tags()->attach($this->tag->id);
    $this->app['auth']->forgetGuards();

    $this->call($method, secUrl($path), ['title' => 'X', 'related' => $this->tag->id])->assertRedirect('/admin/login');

    expect(Post::count())->toBe(3)->and($this->post->tags()->count())->toBe(1)->and($this->post->fresh()->title)->toBe('Visible');
})->with('inertia routes');

it('forbids non-panel users on every Inertia route and changes nothing', function (string $method, string $path) {
    $this->post->tags()->attach($this->tag->id);
    Larsadmin::auth(fn () => false);

    $this->call($method, secUrl($path), ['title' => 'X', 'related' => $this->tag->id])->assertForbidden();

    expect(Post::count())->toBe(3)->and($this->post->tags()->count())->toBe(1)->and($this->post->fresh()->title)->toBe('Visible');
})->with('inertia routes');

// Mass assignment

it('ignores unknown keys and relation fields in store and update payloads', function () {
    $extra = [
        'id' => 9999,
        'author_id' => $this->hidden->id,
        'views' => 500,
        'published' => true,
        'created_at' => '2001-01-01 00:00:00',
        'tags' => [$this->tag->id],
        'posts' => [$this->post->id],
        'drafts' => [$this->post->id],
    ];

    $this->post('/admin/entities/posts', ['title' => 'Mass'] + $extra)->assertSessionDoesntHaveErrors();

    $created = Post::where('title', 'Mass')->firstOrFail();
    expect($created->id)->not->toBe(9999)
        ->and($created->author_id)->toBeNull()
        ->and($created->status)->toBe('draft')
        ->and($created->views)->toBe(0)
        ->and($created->published)->toBeFalse()
        ->and($created->created_at->year)->not->toBe(2001)
        ->and($created->tags()->count())->toBe(0);

    $this->put("/admin/entities/posts/{$this->post->id}", ['title' => 'Mass 2'] + $extra)->assertSessionDoesntHaveErrors();

    $updated = $this->post->fresh();
    expect($updated->title)->toBe('Mass 2')
        ->and($updated->id)->toBe($this->post->id)
        ->and($updated->views)->toBe(0)
        ->and($updated->tags()->count())->toBe(0);
});

it('never fills the HasMany panels through the user form', function () {
    $this->put("/admin/entities/users/{$this->alice->id}", ['name' => 'Alice 2', 'posts' => [$this->pointing->id], 'drafts' => [$this->pointing->id]])
        ->assertSessionDoesntHaveErrors();

    expect($this->alice->fresh()->name)->toBe('Alice 2')
        ->and($this->pointing->fresh()->author_id)->toBe($this->hidden->id);
});

it('uses only the related key of an attach payload', function () {
    $other = Tag::create(['name' => 'Other']);

    $this->post("/admin/entities/posts/{$this->post->id}/attach/tags", [
        'related' => $this->tag->id,
        'tag_id' => $other->id,
        'post_id' => $this->pointing->id,
        'pivot' => ['x' => 1],
        'id' => $other->id,
    ])->assertRedirect();

    expect($this->post->tags()->pluck('tags.id')->all())->toBe([$this->tag->id])
        ->and($this->pointing->tags()->count())->toBe(0);
});
