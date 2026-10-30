<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Events\Attached;
use Maxcxam\Larsadmin\Events\Detached;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\DenyBeforePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\DenyTagPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities;
use Maxcxam\Larsadmin\Tests\Fixtures\NoUpdatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\TagPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Entities\TaggedPost::class, Entities\TagEntity::class]);

    Entities\TagEntity::$indexUsing = null;
    Entities\TagEntity::$relatableUsing = null;
    TagPostPolicy::$attachAny = true;
    TagPostPolicy::$attach = true;
    TagPostPolicy::$detach = true;
    TagPostPolicy::$allowDelete = false;

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
    $this->entry = Post::create(['title' => 'Hello', 'status' => 'draft']);
});

function bmTags(int $count, string $prefix = 'Tag'): array
{
    return collect(range(1, $count))->map(fn (int $i) => Tag::create(['name' => "{$prefix} {$i}"]))->all();
}

function bmRelated(Post $post, string $query = '')
{
    return test()->getJson("/admin/api/entities/posts/{$post->id}/related/tags".($query ? "?{$query}" : ''), ['X-Requested-With' => 'XMLHttpRequest']);
}

function bmOptions(Post $post, string $query = '')
{
    return test()->getJson("/admin/api/entities/posts/fields/tags/options?context=attach&resourceId={$post->id}".($query ? "&{$query}" : ''), ['X-Requested-With' => 'XMLHttpRequest']);
}

function bmAttach(Post $post, mixed $related)
{
    return test()->post("/admin/entities/posts/{$post->id}/attach/tags", ['related' => $related]);
}

function bmDetach(Post $post, string|int $relatedId)
{
    return test()->delete("/admin/entities/posts/{$post->id}/attach/tags/{$relatedId}");
}

function bmUsePolicy(): void
{
    Gate::policy(Post::class, TagPostPolicy::class);
}

// Field JSON

it('serves the belongs-to-many panel on detail only with the contract keys', function () {
    $this->get("/admin/entities/posts/{$this->entry->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.component', 'belongs-to-many-field')
        ->where('record.fields.2.panel', true)
        ->where('record.fields.2.name', 'Tags')
        ->where('record.fields.2.attribute', 'tags')
        ->where('record.fields.2.endpoint', "/admin/api/entities/posts/{$this->entry->id}/related/tags")
        ->where('record.fields.2.optionsUrl', '/admin/api/entities/posts/fields/tags/options')
        ->where('record.fields.2.attachUrl', "/admin/entities/posts/{$this->entry->id}/attach/tags")
        ->where('record.fields.2.searchable', true)
        ->where('record.fields.2.createUrl', null));

    $this->get('/admin/entities/posts')->assertInertia(fn (Assert $page) => $page->has('columns', 2));
    $this->get("/admin/entities/posts/{$this->entry->id}/edit")->assertInertia(fn (Assert $page) => $page->has('record.fields', 1));
});

it('hides attachUrl when attach is not allowed', function () {
    bmUsePolicy();
    TagPostPolicy::$attachAny = false;

    $this->get("/admin/entities/posts/{$this->entry->id}")->assertInertia(fn (Assert $page) => $page
        ->where('record.fields.2.attachUrl', null));
});

// Related list

it('lists only the attached tags with the contract shape and detach flags', function () {
    [$one, $two, $three] = bmTags(3);
    $this->entry->tags()->attach([$one->id, $three->id]);
    bmUsePolicy();

    bmRelated($this->entry)->assertOk()
        ->assertJsonStructure([
            'columns' => [['attribute', 'name', 'component', 'sortable']],
            'records' => ['data' => [['id', 'title', 'fields', 'authorizedToView', 'authorizedToUpdate', 'authorizedToDelete', 'authorizedToDetach']], 'current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            'query' => ['search', 'sort', 'direction', 'perPage'],
        ])
        ->assertJsonPath('records.total', 2)
        ->assertJsonPath('records.data.0.title', 'Tag 3')
        ->assertJsonPath('records.data.0.authorizedToDetach', true);

    TagPostPolicy::$detach = false;

    bmRelated($this->entry)->assertJsonPath('records.data.0.authorizedToDetach', false);
});

it('applies the related index query and search to the attached list', function () {
    [$one, $two] = bmTags(2);
    $this->entry->tags()->attach([$one->id, $two->id]);
    Entities\TagEntity::$indexUsing = fn ($query) => $query->where('name', '!=', 'Tag 1');

    bmRelated($this->entry)->assertOk()->assertJsonPath('records.total', 1);
    bmRelated($this->entry, 'search=nothing')->assertOk()->assertJsonPath('records.total', 0);
    bmRelated($this->entry, 'sort=name&direction=asc&perPage=bogus')->assertOk()->assertJsonPath('query.perPage', 10);
});

it('denies the attached list when not permitted', function (string $case) {
    match ($case) {
        'panel access' => Larsadmin::auth(fn () => false),
        'parent view' => Gate::policy(Post::class, DenyBeforePolicy::class),
        'related viewAny' => Gate::policy(Tag::class, DenyTagPolicy::class),
    };

    bmRelated($this->entry)->assertForbidden();
})->with(['panel access', 'parent view', 'related viewAny']);

it('keeps the attached list query count constant', function () {
    $count = function (int $rows) {
        $post = Post::create(['title' => "Post {$rows}", 'status' => 'draft']);
        $post->tags()->attach(collect(bmTags($rows, "T{$rows}-"))->pluck('id')->all());
        bmUsePolicy();

        DB::flushQueryLog();
        DB::enableQueryLog();
        bmRelated($post, 'perPage=25')->assertOk()->assertJsonCount($rows, 'records.data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(25))->toBe($count(2));
});

// Attachable options

it('lists attachable options excluding attached ones', function () {
    [$one, $two, $three] = bmTags(3);
    $this->entry->tags()->attach($two->id);

    bmOptions($this->entry)->assertOk()->assertExactJson([
        'data' => [['id' => $three->id, 'title' => 'Tag 3'], ['id' => $one->id, 'title' => 'Tag 1']],
        'hasMore' => false,
    ]);
});

it('scopes, searches and paginates attachable options', function () {
    bmTags(30);
    Entities\TagEntity::$relatableUsing = fn ($query) => $query->where('name', '!=', 'Tag 30');

    $first = bmOptions($this->entry)->assertOk()->json();
    expect($first['data'])->toHaveCount(25)->and($first['hasMore'])->toBeTrue()->and($first['data'][0]['title'])->toBe('Tag 29');
    expect(bmOptions($this->entry, 'page=2')->json('data'))->toHaveCount(4);
    expect(bmOptions($this->entry, 'search=Tag+1')->json('data'))->not->toBeEmpty();
});

it('denies attachable options when attach is not allowed', function (string $case) {
    bmUsePolicy();

    match ($case) {
        'attachAny' => TagPostPolicy::$attachAny = false,
        'related viewAny' => Gate::policy(Tag::class, DenyTagPolicy::class),
        'parent view' => Gate::policy(Post::class, DenyBeforePolicy::class),
    };

    bmOptions($this->entry)->assertForbidden();
})->with(['attachAny', 'related viewAny', 'parent view']);

it('falls back to update for attachable options', function () {
    Gate::policy(Post::class, NoUpdatePolicy::class);

    bmOptions($this->entry)->assertForbidden();
});

it('returns 404 or 422 for bad attach option requests', function () {
    $this->getJson("/admin/api/entities/posts/fields/title/options?context=attach&resourceId={$this->entry->id}")->assertNotFound();
    $this->getJson("/admin/api/entities/posts/fields/missing/options?context=attach&resourceId={$this->entry->id}")->assertNotFound();
    $this->getJson('/admin/api/entities/posts/fields/tags/options?context=attach&resourceId=999')->assertNotFound();
    $this->getJson('/admin/api/entities/posts/fields/tags/options?context=attach')->assertUnprocessable();
    $this->getJson("/admin/api/entities/posts/fields/tags/options?context=update&resourceId={$this->entry->id}")->assertNotFound();
});

// Attach

it('attaches a tag, redirects to the parent detail and toasts', function () {
    [$tag] = bmTags(1);
    $events = [];
    Event::listen(Attached::class, function (Attached $event) use (&$events) {
        $events[] = [$event->parent->getKey(), $event->relation, $event->related->getKey(), $event->user?->getKey()];
    });

    bmAttach($this->entry, $tag->id)
        ->assertRedirect("/admin/entities/posts/{$this->entry->id}")
        ->assertSessionHas('larsadmin.toast', ['message' => __('larsadmin::messages.attached'), 'type' => 'success']);

    expect($this->entry->tags()->pluck('tags.id')->all())->toBe([$tag->id])
        ->and($events)->toBe([[$this->entry->id, 'tags', $tag->id, 1]]);
});

it('accepts a string id on attach', function () {
    [$tag] = bmTags(1);

    bmAttach($this->entry, (string) $tag->id)->assertRedirect();

    expect($this->entry->tags()->count())->toBe(1);
});

it('rejects invalid attach input with a related error', function (mixed $related) {
    bmTags(1);

    bmAttach($this->entry, $related)->assertSessionHasErrors('related');

    expect($this->entry->tags()->count())->toBe(0);
})->with([
    'missing' => [null],
    'unknown id' => [999],
    'text' => ['abc'],
    'array' => [[1]],
    'float' => ['1.5'],
]);

it('rejects an already attached tag', function () {
    [$tag] = bmTags(1);
    $this->entry->tags()->attach($tag->id);
    $fired = false;
    Event::listen(Attached::class, function () use (&$fired) {
        $fired = true;
    });

    bmAttach($this->entry, $tag->id)->assertSessionHasErrors(['related' => __('larsadmin::messages.already_attached')]);

    expect($this->entry->tags()->count())->toBe(1)->and($fired)->toBeFalse();
});

it('rejects tags outside the relatable scope', function () {
    [$one] = bmTags(1);
    Entities\TagEntity::$relatableUsing = fn ($query) => $query->where('name', 'other');

    bmAttach($this->entry, $one->id)->assertSessionHasErrors('related');

    expect($this->entry->tags()->count())->toBe(0);
});

it('denies attach when not permitted', function (string $case) {
    [$tag] = bmTags(1);
    bmUsePolicy();

    match ($case) {
        'attachAny' => TagPostPolicy::$attachAny = false,
        'attach' => TagPostPolicy::$attach = false,
        'related viewAny' => Gate::policy(Tag::class, DenyTagPolicy::class),
        'before' => Gate::policy(Post::class, DenyBeforePolicy::class),
        'panel access' => Larsadmin::auth(fn () => false),
    };

    bmAttach($this->entry, $tag->id)->assertForbidden();

    expect($this->entry->tags()->count())->toBe(0);
})->with(['attachAny', 'attach', 'related viewAny', 'before', 'panel access']);

it('falls back to update for attach and detach', function () {
    [$tag] = bmTags(1);
    $this->entry->tags()->attach($tag->id);
    Gate::policy(Post::class, NoUpdatePolicy::class);

    bmAttach($this->entry, $tag->id)->assertForbidden();
    bmDetach($this->entry, $tag->id)->assertForbidden();

    expect($this->entry->tags()->count())->toBe(1);
});

it('allows attach and detach on an updatable policy without specific abilities', function () {
    [$tag] = bmTags(1);
    Gate::policy(Post::class, PostPolicy::class);

    bmAttach($this->entry, $tag->id)->assertRedirect();
    bmDetach($this->entry, $tag->id)->assertRedirect();
});

it('returns 404 for attach on unknown parents, records and fields', function () {
    $this->post('/admin/entities/nope/1/attach/tags', ['related' => 1])->assertNotFound();
    $this->post('/admin/entities/posts/999/attach/tags', ['related' => 1])->assertNotFound();
    $this->post("/admin/entities/posts/{$this->entry->id}/attach/title", ['related' => 1])->assertNotFound();
});

it('rolls the attach back when a listener throws', function () {
    [$tag] = bmTags(1);
    Event::listen(Attached::class, fn () => throw new RuntimeException('listener failed'));
    $this->withoutExceptionHandling();

    expect(fn () => bmAttach($this->entry, $tag->id))->toThrow(RuntimeException::class);
    expect(DB::table('post_tag')->count())->toBe(0);
});

// Detach

it('detaches a tag, redirects to the parent detail and fires the event', function () {
    [$one, $two] = bmTags(2);
    $this->entry->tags()->attach([$one->id, $two->id]);
    $events = [];
    Event::listen(Detached::class, function (Detached $event) use (&$events) {
        $events[] = [$event->relation, $event->related->getKey()];
    });

    bmDetach($this->entry, $one->id)
        ->assertRedirect("/admin/entities/posts/{$this->entry->id}")
        ->assertSessionHas('larsadmin.toast', ['message' => __('larsadmin::messages.detached'), 'type' => 'success']);

    expect($this->entry->tags()->pluck('tags.id')->all())->toBe([$two->id])
        ->and($events)->toBe([['tags', $one->id]])
        ->and(Tag::count())->toBe(2);
});

it('returns 404 when detaching a tag that is not attached', function () {
    [$tag] = bmTags(1);

    bmDetach($this->entry, $tag->id)->assertNotFound();
    bmDetach($this->entry, 'abc')->assertNotFound();
    bmDetach($this->entry, 999)->assertNotFound();
});

it('denies detach when not permitted', function (string $case) {
    [$tag] = bmTags(1);
    $this->entry->tags()->attach($tag->id);
    bmUsePolicy();

    match ($case) {
        'detach' => TagPostPolicy::$detach = false,
        'related viewAny' => Gate::policy(Tag::class, DenyTagPolicy::class),
        'before' => Gate::policy(Post::class, DenyBeforePolicy::class),
        'panel access' => Larsadmin::auth(fn () => false),
    };

    bmDetach($this->entry, $tag->id)->assertForbidden();

    expect($this->entry->tags()->count())->toBe(1);
})->with(['detach', 'related viewAny', 'before', 'panel access']);
