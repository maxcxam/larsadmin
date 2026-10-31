<?php

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Maxcxam\Larsadmin\Audit\Snapshot;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\ExtensionHooks\HookedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    config(['larsadmin.audit.enabled' => true]);
    Storage::fake('local');
    HookedPost::reset();
    HookedPost::$auditable = true;

    Larsadmin::auth(fn () => true);
    Larsadmin::entities([HookedPost::class]);

    $this->user = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);

    $this->post = Post::create(['title' => 'Post', 'status' => 'draft']);
    $this->request = Request::create('/admin/ext/acme/tags');
    $this->request->setUserResolver(fn () => $this->user);
});

afterEach(function () {
    HookedPost::reset();
    HookedPost::$auditable = true;
});

function hookTags(Post $post, array $ids): void
{
    DB::table('post_tag')->where('post_id', $post->id)->delete();
    DB::table('post_tag')->insert(array_map(fn ($id) => ['post_id' => $post->id, 'tag_id' => $id], $ids));
}

it('audits a field without a column through auditValue() on a form save', function () {
    hookTags($this->post, [3]);

    $this->put("/admin/entities/hooked-posts/{$this->post->id}", ['title' => 'Post', 'status' => 'draft', 'tag_ids' => [2, 1]])
        ->assertRedirect();

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('updated')
        ->and($entry->changes)->toBe(['tag_ids' => ['old' => [3], 'new' => [1, 2]]]);
});

it('records the field value when a record is created', function () {
    $this->post('/admin/entities/hooked-posts', ['title' => 'New', 'status' => 'draft', 'tag_ids' => [5]])->assertRedirect();

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('created')
        ->and($entry->changes['tag_ids'])->toBe(['old' => null, 'new' => [5]]);
});

it('writes no entry when the field value did not change', function () {
    hookTags($this->post, [1, 2]);

    $this->put("/admin/entities/hooked-posts/{$this->post->id}", ['title' => 'Post', 'status' => 'draft', 'tag_ids' => [2, 1]])->assertRedirect();

    expect(AuditEntry::query()->count())->toBe(0);
});

it('masks a sensitive field value', function () {
    HookedPost::$sensitiveTags = true;

    $this->put("/admin/entities/hooked-posts/{$this->post->id}", ['title' => 'Post', 'status' => 'draft', 'tag_ids' => [7]])->assertRedirect();

    expect(AuditEntry::query()->sole()->changes)->toBe(['tag_ids' => ['masked' => true]]);
});

it('keeps column fields as before: hasAuditValue() is false by default', function () {
    $field = Text::make('Title');

    expect($field->hasAuditValue())->toBeFalse()
        ->and($field->auditValue($this->post))->toBeNull();

    $before = Snapshot::of(new Collection([$field]), $this->post);
    $this->post->title = 'Changed';

    expect($before->diff(Snapshot::of(new Collection([$field]), $this->post)))->toBe(['title' => ['old' => 'Post', 'new' => 'Changed']]);
});

it('audits a change made outside the form with audited()', function () {
    $result = Larsadmin::audited($this->request, 'hooked-posts', $this->post, function (Post $post) {
        hookTags($post, [4]);
        $post->update(['title' => 'Renamed']);

        return 'done';
    });

    $entry = AuditEntry::query()->sole();

    expect($result)->toBe('done')
        ->and($entry->event)->toBe('updated')
        ->and($entry->auditable_id)->toBe((string) $this->post->id)
        ->and($entry->actor_id)->toBe((string) $this->user->id)
        ->and($entry->changes)->toBe(['title' => ['old' => 'Post', 'new' => 'Renamed'], 'tag_ids' => ['old' => [], 'new' => [4]]]);
});

it('writes no entry from audited() when nothing changed, the log is off or the entity is not auditable', function (Closure $setup) {
    ($setup())(); // the dataset holds factories of the setup closures

    $result = Larsadmin::audited($this->request, 'hooked-posts', $this->post, function (Post $post) {
        if (config('larsadmin.audit.enabled') && HookedPost::$auditable) {
            return 'unchanged';
        }

        hookTags($post, [9]);

        return 'changed';
    });

    expect(AuditEntry::query()->count())->toBe(0)
        ->and($result)->toBeString();
})->with([
    'nothing changed' => [fn () => fn () => null],
    'log disabled' => [fn () => fn () => config(['larsadmin.audit.enabled' => false])],
    'entity not auditable' => [fn () => fn () => HookedPost::$auditable = false],
]);

it('rolls back the change and writes no entry when the closure throws', function () {
    expect(fn () => Larsadmin::audited($this->request, 'hooked-posts', $this->post, function (Post $post) {
        hookTags($post, [4]);
        $post->update(['title' => 'Renamed']);

        throw new RuntimeException('failed');
    }))->toThrow(RuntimeException::class, 'failed');

    expect(AuditEntry::query()->count())->toBe(0)
        ->and($this->post->fresh()->title)->toBe('Post')
        ->and(DB::table('post_tag')->where('post_id', $this->post->id)->count())->toBe(0);
});

it('answers 404 from audited() for an unknown entity', function () {
    Larsadmin::audited($this->request, 'nope', $this->post, fn () => null);
})->throws(NotFoundHttpException::class);

it('refuses a record of another model in audited()', function () {
    Larsadmin::audited($this->request, 'hooked-posts', $this->user, fn () => null);
})->throws(InvalidArgumentException::class, 'is not a model of the entity');
