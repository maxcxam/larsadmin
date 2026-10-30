<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Security;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([Security\SecPost::class, Security\SecMember::class, Security\SecTag::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
    $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);
});

/**
 * Build a dataset of the given size, then run $request and return the number of queries it issued.
 */
function perfQueries(int $rows, Closure $request): int
{
    Post::query()->delete();
    Tag::query()->delete();
    DB::table('post_tag')->delete();

    $parent = Post::create(['title' => 'Parent', 'status' => 'draft', 'author_id' => test()->owner->id]);

    // Bulk inserts: the dataset only needs to exist, not to go through model events.
    $now = now()->toDateTimeString();
    $users = $posts = $tags = [];

    foreach (range(1, $rows) as $i) {
        $users[] = ['name' => "Author {$rows}-{$i}", 'email' => "a{$rows}-{$i}@example.com", 'password' => 'x', 'created_at' => $now, 'updated_at' => $now];
        $tags[] = ['name' => "Tag {$i}", 'created_at' => $now, 'updated_at' => $now];
        $tags[] = ['name' => "Spare {$i}", 'created_at' => $now, 'updated_at' => $now];
    }

    DB::table('users')->insert($users);
    DB::table('tags')->insert($tags);

    foreach (DB::table('users')->where('email', 'like', "a{$rows}-%")->orderBy('id')->pluck('id') as $index => $authorId) {
        $i = $index + 1;
        $posts[] = ['title' => "Post {$i}", 'status' => 'draft', 'author_id' => $authorId, 'created_at' => $now, 'updated_at' => $now];
        $posts[] = ['title' => "Owned {$i}", 'status' => 'draft', 'author_id' => test()->owner->id, 'created_at' => $now, 'updated_at' => $now];
    }

    DB::table('posts')->insert($posts);
    $parent->tags()->attach(DB::table('tags')->where('name', 'like', 'Tag %')->pluck('id')->all());

    test()->parent = $parent;

    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function perfJson(string $url)
{
    return test()->getJson($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
}

function perfEndpoints(): array
{
    return [
        'index with BelongsTo' => fn () => test()->get('/admin/entities/posts?perPage=50')->assertOk(),
        'detail with BelongsTo and tags panel' => fn () => test()->get('/admin/entities/posts/'.test()->parent->id)->assertOk(),
        'user detail with two panels' => fn () => test()->get('/admin/entities/users/'.test()->owner->id)->assertOk(),
        'tags panel endpoint' => fn () => perfJson('/admin/api/entities/posts/'.test()->parent->id.'/related/tags?perPage=50'),
        'posts panel endpoint' => fn () => perfJson('/admin/api/entities/users/'.test()->owner->id.'/related/posts?perPage=50'),
        'drafts panel endpoint' => fn () => perfJson('/admin/api/entities/users/'.test()->owner->id.'/related/drafts?perPage=50'),
        'attach options endpoint' => fn () => perfJson('/admin/api/entities/posts/fields/tags/options?context=attach&resourceId='.test()->parent->id),
        'author options endpoint' => fn () => perfJson('/admin/api/entities/posts/fields/author/options?context=create'),
        'edit form with BelongsTo' => fn () => test()->get('/admin/entities/posts/'.test()->parent->id.'/edit')->assertOk(),
    ];
}

it('keeps query counts independent of the row count', function (string $name) {
    $request = perfEndpoints()[$name];

    $small = perfQueries(2, $request);
    $large = perfQueries(30, $request);

    expect($large)->toBe($small)
        ->and($small)->toBeLessThanOrEqual(6);
})->with([
    'index with BelongsTo',
    'detail with BelongsTo and tags panel',
    'user detail with two panels',
    'tags panel endpoint',
    'posts panel endpoint',
    'drafts panel endpoint',
    'attach options endpoint',
    'author options endpoint',
    'edit form with BelongsTo',
]);

it('keeps query counts independent of the row count with active policies', function () {
    Gate::policy(Post::class, PostPolicy::class);

    $request = perfEndpoints()['posts panel endpoint'];

    expect(perfQueries(30, $request))->toBe(perfQueries(2, $request));
});

it('returns every row of a full page so the count comparison is meaningful', function () {
    perfQueries(30, fn () => null);

    $posts = perfJson('/admin/api/entities/users/'.test()->owner->id.'/related/posts?perPage=50');
    $tags = perfJson('/admin/api/entities/posts/'.test()->parent->id.'/related/tags?perPage=50');

    expect($posts->json('records.data'))->toHaveCount(31)
        ->and($tags->json('records.data'))->toHaveCount(30);
});
