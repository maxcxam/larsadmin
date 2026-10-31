<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Cards\StatusCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Cards\TopPosts;
use Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks\HookedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks\TagIds;
use Maxcxam\Larsadmin\Tests\Fixtures\Extensions\FixtureExtensions;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\Rating;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use PHPUnit\Framework\AssertionFailedError;

uses(InteractsWithLarsadmin::class);

beforeEach(function () {
    TopPosts::reset();
    Rating::$calls = [];
    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
});

afterEach(function () {
    TopPosts::reset();
});

it('serializes a field resolved from a record', function () {
    $post = Post::create(['title' => 'Post', 'status' => 'live', 'views' => 4]);

    $json = $this->serializeField(Rating::make('Rating', 'views')->readonly(), $post);

    expect($json)->toMatchArray([
        'component' => 'acme/rating',
        'attribute' => 'views',
        'value' => 4,
        'displayedAs' => '****',
        'readonly' => true,
        'max' => 5,
    ]);
});

it('serializes a field for the create form from its default', function () {
    $field = Rating::make('Rating', 'views')->default(2);

    expect($this->serializeField($field, null, Field::CREATE))->toMatchArray(['value' => 2, 'displayedAs' => null, 'required' => false])
        ->and($this->serializeField($field, new Post, Field::CREATE)['value'])->toBe(2)
        ->and($field->value)->toBeNull(); // the given field is not changed
});

it('validates, fills, saves and runs the deferred closure', function () {
    $post = Post::create(['title' => 'Post', 'status' => 'live']);
    $saved = null;
    $field = Rating::make('Rating', 'views')->afterSave(function (Post $record) use (&$saved) {
        $saved = $record->exists ? $record->views : 'unsaved';
    });

    $result = $this->fillField($field, $post, ['views' => '5']);

    expect($result)->toBe($post)
        ->and($post->fresh()->views)->toBe(5)
        ->and($saved)->toBe(5);
});

it('throws a validation exception for invalid input', function () {
    $post = Post::create(['title' => 'Post', 'status' => 'live', 'views' => 1]);

    try {
        $this->fillField(Rating::make('Rating', 'views')->rules('required'), $post, ['views' => '9']);
        $this->fail('No validation exception.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('views')
            ->and($e->errors()['views'][0])->toContain('Rating')
            ->and($post->fresh()->views)->toBe(1);
    }
});

it('passes uploaded files to the request', function () {
    $file = UploadedFile::fake()->create('a.txt', 1);
    $post = Post::create(['title' => 'Post', 'status' => 'live']);
    $seen = null;

    $field = Rating::make('Rating', 'views')->multipart()->fillUsing(function ($request) use (&$seen) {
        $seen = $request->file('views');
    });

    $this->fillField($field, $post, [], ['views' => $file], Field::CREATE);

    expect($seen)->toBe($file);
});

it('returns the data of a data card as the given user', function () {
    Post::create(['title' => 'One', 'status' => 'live']);
    Post::create(['title' => 'Two', 'status' => 'draft']);

    $data = $this->cardData(TopPosts::make(), ['status' => 'draft', 'evil' => 1], $this->user);

    expect($data)->toBe([['id' => 2, 'title' => 'Two', 'user' => $this->user->id]])
        ->and(TopPosts::$lastRequest->parameters)->toBe(['status' => 'draft'])
        ->and(TopPosts::$lastRequest->owner)->toBe('dashboard:test');
});

it('uses the acting user and the given owner', function () {
    $this->actingAs($this->user);

    expect($this->cardData(StatusCard::make(), owner: 'entity:posts'))->toMatchArray(['ok' => true, 'owner' => 'entity:posts']);
});

it('throws an authorization exception for an invisible card', function () {
    $this->cardData(StatusCard::make()->canSee(fn () => false));
})->throws(AuthorizationException::class);

it('throws a validation exception for invalid card parameters', function () {
    $this->cardData(TopPosts::make(), ['limit' => 'many']);
})->throws(ValidationException::class);

it('asserts that an extension is registered', function () {
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');

    $this->assertExtensionRegistered('acme/demo');

    expect(fn () => $this->assertExtensionRegistered('acme/missing'))->toThrow(AssertionFailedError::class, 'acme/missing');
});

it('fetches an extension file through its versioned URL', function () {
    Larsadmin::auth(fn () => true);
    Larsadmin::extension('acme/demo', FixtureExtensions::DEMO.'/dist');
    $this->actingAs($this->user);

    $this->extensionAssetResponse('acme/demo', 'extension.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

    $this->extensionAssetResponse('acme/demo', '../outside.js')->assertNotFound();

    expect(fn () => $this->extensionAssetResponse('acme/missing', 'extension.js'))->toThrow(AssertionFailedError::class);
});

it('binds the field to an entity before serializing it', function (string $entity) {
    Larsadmin::entities([HookedPost::class]);

    $json = $this->serializeField(TagIds::make('Tags', 'tag_ids'), Post::create(['title' => 'Post', 'status' => 'live']), Field::DETAIL, $entity);

    expect($json['boundTo'])->toBe(HookedPost::class)
        ->and($this->serializeField(TagIds::make('Tags', 'tag_ids'))['boundTo'])->toBeNull();
})->with([
    'entity class' => [HookedPost::class],
    'uri key' => ['hooked-posts'],
]);

it('refuses to bind a field to something that is not an entity', function () {
    $this->serializeField(TagIds::make('Tags', 'tag_ids'), null, Field::CREATE, 'nope');
})->throws(AssertionFailedError::class, 'not an Entity');

it('fills a field that only overrides fillInto(), with its deferred closure and file journal', function () {
    Storage::fake('local');
    $post = Post::create(['title' => 'Post', 'status' => 'live']);

    $this->fillField(TagIds::make('Tags', 'tag_ids'), $post, ['tag_ids' => [3, 4]]);

    expect(DB::table('post_tag')->where('post_id', $post->id)->orderBy('tag_id')->pluck('tag_id')->all())->toBe([3, 4])
        ->and(Storage::disk('local')->files('exports'))->toHaveCount(1);
});

it('deletes the files of a failed fill and rolls the save back', function () {
    Storage::fake('local');
    $post = Post::create(['title' => 'Post', 'status' => 'live']);
    TagIds::$fail = true;

    try {
        expect(fn () => $this->fillField(TagIds::make('Tags', 'tag_ids'), $post, ['tag_ids' => [3]]))->toThrow(RuntimeException::class);

        expect(Storage::disk('local')->allFiles())->toBe([])
            ->and(DB::table('post_tag')->where('post_id', $post->id)->count())->toBe(0);
    } finally {
        TagIds::$fail = false;
    }
});
