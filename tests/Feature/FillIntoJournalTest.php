<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Files\FileJournal;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks\HookedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks\TagIds;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    Storage::fake('local');
    HookedPost::reset();

    Larsadmin::auth(fn () => true);
    Larsadmin::entities([HookedPost::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
    $this->post = Post::create(['title' => 'Post', 'status' => 'draft']);
});

afterEach(fn () => HookedPost::reset());

function hookedUpdate(Post $post, array $data): void
{
    $request = Request::create("/admin/entities/hooked-posts/{$post->id}", 'PUT', $data);
    $request->setUserResolver(fn () => auth()->user());

    HookedPost::make($post)->save($request, 'update');
}

it('keeps the files a field registered as created when the save commits, and purges obsolete ones', function () {
    hookedUpdate($this->post, ['title' => 'One', 'status' => 'draft', 'tag_ids' => [1]]);
    $first = Storage::disk('local')->files('exports');

    hookedUpdate($this->post, ['title' => 'Two', 'status' => 'draft', 'tag_ids' => [2]]);
    $second = Storage::disk('local')->files('exports');

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($second)->not->toBe($first)
        ->and(Storage::disk('local')->get($second[0]))->toBe('2')
        ->and($this->post->fresh()->title)->toBe('Two');
});

it('deletes the files a field wrote in its deferred closure when the save rolls back', function () {
    hookedUpdate($this->post, ['title' => 'One', 'status' => 'draft', 'tag_ids' => [1]]);
    $kept = Storage::disk('local')->files('exports');

    TagIds::$fail = true;

    expect(fn () => hookedUpdate($this->post->fresh(), ['title' => 'Two', 'status' => 'draft', 'tag_ids' => [2]]))
        ->toThrow(RuntimeException::class, 'The export failed.');

    // No orphan from the failed save; the file of the committed save is not purged.
    expect(Storage::disk('local')->files('exports'))->toBe($kept)
        ->and($this->post->fresh()->title)->toBe('One')
        ->and(DB::table('post_tag')->where('post_id', $this->post->id)->pluck('tag_id')->all())->toBe([1]);
});

it('cleans up through the HTTP form save as well', function () {
    TagIds::$fail = true;
    $this->withoutExceptionHandling();

    expect(fn () => $this->put("/admin/entities/hooked-posts/{$this->post->id}", ['title' => 'X', 'status' => 'draft', 'tag_ids' => [3]]))
        ->toThrow(RuntimeException::class);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and($this->post->fresh()->title)->toBe('Post');
});

it('calls fill() from the default fillInto()', function () {
    $post = new Post;
    $result = Text::make('Title')->fillInto(Request::create('/', 'POST', ['title' => 'Filled']), $post, new FileJournal);

    expect($result)->toBeNull()->and($post->title)->toBe('Filled');
});

it('passes the deferred closure of fill() through the default fillInto()', function () {
    $closure = fn () => null;
    $field = Text::make('Title')->fillUsing(fn () => $closure);

    expect($field->fillInto(Request::create('/'), new Post, new FileJournal))->toBe($closure);
});
