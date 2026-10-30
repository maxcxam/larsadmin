<?php

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Maxcxam\Larsadmin\Events\Attached;
use Maxcxam\Larsadmin\Events\Detached;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Actions\ActionAuditedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Actions\AuditAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\Actions\ScopedActionPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditSchema;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\TagEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\TaggedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\User as UserEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    config(['larsadmin.audit.enabled' => true, 'larsadmin.locale' => 'en']);
    AuditSchema::create();
    ActionAuditedPost::reset();
    TagEntity::$indexUsing = null;
    TagEntity::$relatableUsing = null;

    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ActionAuditedPost::class, TaggedPost::class, TagEntity::class]);

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    $this->actingAs($this->admin);
});

afterEach(function () {
    ActionAuditedPost::reset();
    ScopedActionPost::reset();
});

function actionPosts(int $count, string $status = 'draft'): array
{
    return collect(range(1, $count))
        ->map(fn (int $i) => AuditPost::create(['title' => "Post {$i}", 'status' => $status]))
        ->all();
}

function runAuditAction(array $body, string $key = 'publish')
{
    return test()->from('/admin/entities/action-audited-posts')->post("/admin/entities/action-audited-posts/actions/{$key}", $body);
}

function actionEntries()
{
    return AuditEntry::query()->orderBy('id')->get();
}

function publishAction(array $fields = []): AuditAction
{
    return new AuditAction('publish', function (ActionFields $values, Collection $models) {
        $models->each->update(['status' => 'live']);

        return null;
    }, $fields);
}

// AC-3: one entry per record, fields masked, diff

it('writes one action entry per record with the action, its fields and the diff (AC-3)', function () {
    [$a, $b, $c] = actionPosts(3);
    ActionAuditedPost::$actions = [publishAction([
        Text::make('Reason'),
        Password::make('Pin'),
        Boolean::make('Notify'),
        Text::make('Remark')->sensitive(),
    ])];

    runAuditAction(['resources' => [$a->id, $c->id], 'reason' => 'Ready', 'pin' => '1234', 'notify' => true, 'remark' => 'hush'])
        ->assertSessionHas('larsadmin.toast.type', 'success');

    $entries = actionEntries();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('auditable_id')->all())->toBe([(string) $a->id, (string) $c->id])
        ->and($entries->pluck('event')->unique()->all())->toBe(['action'])
        ->and($entries->pluck('entity')->unique()->all())->toBe(['action-audited-posts'])
        ->and($entries->pluck('batch_id')->unique())->toHaveCount(1)
        ->and($entries[0]->actor_id)->toBe((string) $this->admin->id)
        ->and($entries[0]->changes)->toBe(['status' => ['old' => 'draft', 'new' => 'live']])
        ->and($entries[0]->meta)->toBe([
            'action' => 'publish',
            'action_name' => 'Опублікувати',
            'fields' => [
                'reason' => 'Ready',
                'pin' => ['masked' => true],
                'notify' => true,
                'remark' => ['masked' => true],
            ],
        ])
        ->and(json_encode(DB::table('larsadmin_audit_entries')->get()))->not->toContain('1234')->not->toContain('hush')
        ->and($b->fresh()->status->value)->toBe('draft');
});

it('includes rows updated through the query builder in the diff (AC-3)', function () {
    [$a, $b] = actionPosts(2);
    ActionAuditedPost::$actions = [new AuditAction('flag', function (ActionFields $fields, Collection $models) {
        AuditPost::query()->whereKey($models->modelKeys())->update(['body' => 'flagged', 'note' => 'secret note']);

        return null;
    })];

    runAuditAction(['resources' => [$a->id, $b->id]], 'flag')->assertRedirect();

    $entries = actionEntries();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->changes)->toBe(['body' => ['old' => null, 'new' => 'flagged'], 'note' => ['masked' => true]])
        ->and($entries[0]->meta)->toBe(['action' => 'flag', 'action_name' => 'Опублікувати']);
});

it('writes an entry without changes when the action changed nothing (AC-3)', function () {
    [$a] = actionPosts(1);
    ActionAuditedPost::$actions = [new AuditAction('noop', fn () => null)];

    runAuditAction(['resources' => [$a->id]], 'noop')->assertRedirect();

    expect(actionEntries()->sole()->changes)->toBeNull();
});

it('writes one entry without a subject for a standalone action (AC-3)', function () {
    ActionAuditedPost::$actions = [(new AuditAction('ping', fn () => null, [Text::make('Topic')]))->standalone()];

    runAuditAction(['resources' => [], 'topic' => 'Hello'], 'ping')->assertSessionHas('larsadmin.toast.type', 'success');

    $entry = actionEntries()->sole();

    expect($entry->event)->toBe('action')
        ->and($entry->auditable_type)->toBeNull()
        ->and($entry->auditable_id)->toBeNull()
        ->and($entry->changes)->toBeNull()
        ->and($entry->meta)->toBe(['action' => 'ping', 'action_name' => 'Опублікувати', 'fields' => ['topic' => 'Hello']]);
});

it('does not duplicate entries for save() called inside the action (AC-3)', function () {
    [$a, $b] = actionPosts(2);
    ActionAuditedPost::$actions = [new AuditAction('rename', function (ActionFields $fields, Collection $models) {
        foreach ($models as $model) {
            $request = Request::create('/', 'PUT', ['title' => 'Renamed '.$model->id]);
            $request->setUserResolver(fn () => auth()->user());

            ActionAuditedPost::make($model)->save($request, Field::UPDATE);
        }

        return null;
    })];

    runAuditAction(['resources' => [$a->id, $b->id]], 'rename')->assertRedirect();

    $entries = actionEntries();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('event')->unique()->all())->toBe(['action'])
        ->and($entries[0]->changes)->toBe(['title' => ['old' => 'Post 1', 'new' => 'Renamed '.$a->id]]);
});

it('marks records deleted by the action and does not duplicate the delete entry (AC-3)', function () {
    [$a, $b] = actionPosts(2);
    ActionAuditedPost::$actions = [new AuditAction('remove', function (ActionFields $fields, Collection $models) {
        $models->each(fn (AuditPost $model) => ActionAuditedPost::make($model)->deleteRecord(request()));

        return null;
    })];

    runAuditAction(['resources' => [$a->id, $b->id]], 'remove')->assertRedirect();

    $entries = actionEntries();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('event')->unique()->all())->toBe(['action'])
        ->and($entries[0]->meta['record_deleted'])->toBeTrue()
        ->and($entries[0]->changes)->toBe([
            'id' => ['old' => $a->id, 'new' => null],
            'title' => ['old' => 'Post 1', 'new' => null],
            'status' => ['old' => 'draft', 'new' => null],
        ])
        ->and(AuditPost::count())->toBe(0);
});

it('does not log records filtered out by authorizedToRun (AC-3)', function () {
    [$a, $b] = actionPosts(2);
    ActionAuditedPost::$actions = [publishAction()->canRun(fn ($request, $model) => $model->id === $a->id)];

    runAuditAction(['resources' => [$a->id, $b->id]])->assertRedirect();

    expect(actionEntries()->pluck('auditable_id')->all())->toBe([(string) $a->id]);
});

it('shares one batch id across chunks and costs two queries per chunk (AC-3)', function () {
    $off = actionPosts(5);
    $on = actionPosts(5);
    $action = publishAction();
    $action->chunkSize = 2;
    ActionAuditedPost::$actions = [$action];

    $count = function (array $posts) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        runAuditAction(['resources' => array_map(fn ($post) => $post->id, $posts)])->assertRedirect();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    config(['larsadmin.audit.enabled' => false]);
    $without = $count($off);

    config(['larsadmin.audit.enabled' => true]);
    $with = $count($on);

    $entries = actionEntries();

    expect($entries)->toHaveCount(5)
        ->and($entries->pluck('batch_id')->unique())->toHaveCount(1)
        ->and($with - $without)->toBe(2 * 3);
});

it('keeps the entries of committed chunks when a later chunk throws (AC-5)', function () {
    $posts = actionPosts(4);
    $action = new AuditAction('publish', function (ActionFields $fields, Collection $models, int $chunk) {
        $models->each->update(['status' => 'live']);

        if ($chunk === 2) {
            throw new RuntimeException('second chunk failed');
        }

        return null;
    });
    $action->chunkSize = 2;
    ActionAuditedPost::$actions = [$action];

    runAuditAction(['resources' => array_map(fn ($post) => $post->id, $posts)])
        ->assertSessionHas('larsadmin.toast.type', 'error');

    expect(actionEntries()->pluck('auditable_id')->all())->toBe([(string) $posts[0]->id, (string) $posts[1]->id])
        ->and($posts[2]->fresh()->status->value)->toBe('draft');
});

it('writes nothing when the only chunk throws (AC-5)', function () {
    [$a] = actionPosts(1);
    ActionAuditedPost::$actions = [new AuditAction('publish', function (ActionFields $fields, Collection $models) {
        $models->each->update(['status' => 'live']);

        throw new RuntimeException('failed');
    })];

    $this->withoutExceptionHandling();

    expect(fn () => runAuditAction(['resources' => [$a->id]]))->toThrow(RuntimeException::class);

    expect(actionEntries())->toBeEmpty()->and($a->fresh()->status->value)->toBe('draft');
});

it('writes nothing for actions while the log is disabled or the entity opts out (AC-12)', function () {
    [$a, $b] = actionPosts(2);
    ActionAuditedPost::$actions = [publishAction(), (new AuditAction('ping', fn () => null))->standalone()];

    config(['larsadmin.audit.enabled' => false]);
    runAuditAction(['resources' => [$a->id]])->assertRedirect();
    runAuditAction(['resources' => []], 'ping')->assertRedirect();

    config(['larsadmin.audit.enabled' => true]);
    ActionAuditedPost::$auditable = false;
    runAuditAction(['resources' => [$b->id]])->assertRedirect();

    expect(actionEntries())->toBeEmpty()->and($b->fresh()->status->value)->toBe('live');
});

// AC-4: attach / detach

it('records attach and detach on the parent record (AC-4)', function () {
    $post = Post::create(['title' => 'Hello', 'status' => 'draft']);
    $tag = Tag::create(['name' => 'News']);

    $this->post("/admin/entities/posts/{$post->id}/attach/tags", ['related' => $tag->id])->assertSessionHasNoErrors();
    $this->delete("/admin/entities/posts/{$post->id}/attach/tags/{$tag->id}")->assertRedirect();

    [$attached, $detached] = actionEntries()->all();

    expect($attached->event)->toBe('attached')
        ->and($attached->entity)->toBe('posts')
        ->and($attached->auditable_type)->toBe(Post::class)
        ->and($attached->auditable_id)->toBe((string) $post->id)
        ->and($attached->changes)->toBeNull()
        ->and($attached->meta)->toBe(['field' => 'tags', 'related_entity' => 'tags', 'related_id' => (string) $tag->id])
        ->and($detached->event)->toBe('detached')
        ->and($detached->meta)->toBe(['field' => 'tags', 'related_entity' => 'tags', 'related_id' => (string) $tag->id])
        ->and($attached->batch_id)->not->toBe($detached->batch_id);
});

it('rolls back the attach and its entry when a listener throws (AC-5)', function () {
    $post = Post::create(['title' => 'Hello', 'status' => 'draft']);
    $tag = Tag::create(['name' => 'News']);
    Event::listen(Attached::class, fn () => throw new RuntimeException('listener failed'));
    Event::listen(Detached::class, fn () => throw new RuntimeException('listener failed'));

    $this->post("/admin/entities/posts/{$post->id}/attach/tags", ['related' => $tag->id])->assertServerError();

    expect(actionEntries())->toBeEmpty()->and($post->tags()->count())->toBe(0);

    $post->tags()->attach($tag);
    $this->delete("/admin/entities/posts/{$post->id}/attach/tags/{$tag->id}")->assertServerError();

    expect(actionEntries())->toBeEmpty()->and($post->tags()->count())->toBe(1);
});

it('does not record attach while the log is disabled (AC-12)', function () {
    config(['larsadmin.audit.enabled' => false]);
    $post = Post::create(['title' => 'Hello', 'status' => 'draft']);
    $tag = Tag::create(['name' => 'News']);

    $this->post("/admin/entities/posts/{$post->id}/attach/tags", ['related' => $tag->id])->assertSessionHasNoErrors();

    expect(actionEntries())->toBeEmpty()->and($post->tags()->count())->toBe(1);
});

// CRA-03: the chunk query may load other columns than the re-read

it('records no phantom changes for columns only the row scope added or left out (CRA-03)', function (string $scope) {
    Larsadmin::entities([ScopedActionPost::class]);
    ScopedActionPost::$scope = $scope === 'computed'
        ? fn ($query) => $query->select('*')->selectRaw('5 as comments_count')
        : fn ($query) => $query->select(['id', 'title', 'status']);
    ScopedActionPost::$actions = [publishAction()];
    [$a] = actionPosts(1);
    AuditPost::query()->whereKey($a->id)->update(['body' => 'Unselected body']);

    $this->post('/admin/entities/scoped-action-posts/actions/publish', ['resources' => [$a->id]])->assertRedirect();

    expect(actionEntries()->sole()->changes)->toBe(['status' => ['old' => 'draft', 'new' => 'live']]);
})->with(['computed' => ['computed'], 'subset' => ['subset']]);

// CRA-04 / SECA-07: only the handled records are covered

it('records saves of other entities and records made by an action, in its batch (CRA-04)', function () {
    [$a] = actionPosts(1);
    Larsadmin::entities([UserEntity::class]);
    ActionAuditedPost::$actions = [new AuditAction('rename-author', function (ActionFields $fields, Collection $models) {
        $request = Request::create('/', 'PUT', ['name' => 'Renamed', 'email' => 'admin@example.com']);
        $request->setUserResolver(fn () => auth()->user());

        UserEntity::make(User::firstOrFail())->save($request, Field::UPDATE);
        $models->each->update(['status' => 'live']);

        return null;
    })];

    runAuditAction(['resources' => [$a->id]], 'rename-author')->assertRedirect();

    $entries = actionEntries();

    expect($entries->pluck('event', 'entity')->all())->toBe(['users' => 'updated', 'action-audited-posts' => 'action'])
        ->and($entries->pluck('batch_id')->unique())->toHaveCount(1)
        ->and($entries[0]->changes)->toBe(['name' => ['old' => 'Admin', 'new' => 'Renamed']]);
});

it('records records a standalone action creates', function () {
    ActionAuditedPost::$actions = [(new AuditAction('import', function () {
        $request = Request::create('/', 'POST', ['title' => 'Imported', 'status' => 'draft']);
        $request->setUserResolver(fn () => auth()->user());

        ActionAuditedPost::make()->save($request, Field::CREATE);

        return null;
    }))->standalone()];

    runAuditAction(['resources' => []], 'import')->assertRedirect();

    expect(actionEntries()->pluck('event')->all())->toBe(['created', 'action'])
        ->and(actionEntries()->pluck('batch_id')->unique())->toHaveCount(1);
});
