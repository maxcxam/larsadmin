<?php

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\ReplayedRequest;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Events\ActionRunFinished;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\PlainUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

beforeEach(function () {
    config([
        'larsadmin.notifications.enabled' => true,
        'larsadmin.locale' => 'en',
        'auth.providers.users.model' => NotifiableUser::class,
    ]);
    QueuedPost::reset();
    QueuedPostPolicy::reset();
    Gate::policy(Post::class, QueuedPostPolicy::class);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([QueuedPost::class]);

    $this->user = NotifiableUser::create(['name' => 'Olena', 'email' => 'olena@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);
});

function qwPosts(int $count, string $status = 'draft', string $prefix = 'Post'): array
{
    return collect(range(1, $count))->map(fn (int $i) => Post::create(['title' => "{$prefix} {$i}", 'status' => $status]))->all();
}

/**
 * Queue a run through the panel (the queue faked) and return it.
 */
function qwQueue(QueuedAction $action, array $body = [], int $chunkSize = 2): ActionRun
{
    $action->chunkSize = $chunkSize;
    QueuedPost::$actions = [$action];

    Bus::fake([RunQueuedAction::class]);
    test()->from('/admin/entities/queued-posts')->post("/admin/entities/queued-posts/actions/{$action->uriKey()}", $body)->assertSessionHas('larsadmin.run');

    return ActionRun::query()->latest('id')->firstOrFail();
}

function qwWork(ActionRun $run): ActionRun
{
    (new RunQueuedAction($run->id))->handle();

    return $run->refresh();
}

it('runs the chunks as the user, records progress and completes', function () {
    Notification::fake();
    Event::fake([ActionRunFinished::class]);
    $posts = qwPosts(5);
    $run = qwQueue(new QueuedAction, ['resources' => array_map(fn ($p) => $p->id, $posts)]);
    $progress = [];
    QueuedPost::$actions[0]->callback = function ($fields, $models) use ($run, &$progress) {
        $models->each->update(['status' => 'live']);
        $progress[] = ActionRun::find($run->id)->processed;

        return null;
    };

    $run = qwWork($run);

    expect($run->only(['status', 'level', 'message', 'total', 'processed', 'ran', 'skipped', 'payload', 'result']))->toBe([
        'status' => 'completed', 'level' => 'success', 'message' => 'Action completed.', 'total' => 5,
        'processed' => 5, 'ran' => 5, 'skipped' => 0, 'payload' => null, 'result' => null,
    ])
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($progress)->toBe([0, 2, 4])
        ->and(Post::where('status', 'live')->count())->toBe(5)
        ->and(array_column(QueuedAction::$calls, 'user'))->toBe([$this->user->id, $this->user->id, $this->user->id]);

    Notification::assertSentTo($this->user, LarsadminNotification::class, function (LarsadminNotification $notification) use ($run) {
        $data = $notification->toArray($this->user);

        return $data['title'] === '“Publish” finished' && $data['level'] === 'success' && $data['run'] === $run->id && $data['body'] === 'Action completed.' && $data['icon'] === 'check';
    });
    Event::assertDispatched(ActionRunFinished::class, fn (ActionRunFinished $event) => $event->run->is($run));
});

it('stores the notification in the bell with the run link', function () {
    Storage::fake('local');
    Storage::disk('local')->put('exports/posts.csv', 'a,b');
    [$post] = qwPosts(1);
    $run = qwWork(qwQueue(new QueuedAction(callback: fn () => ActionResponse::file('local', 'exports/posts.csv')), ['resources' => [$post->id]]));

    $items = $this->getJson('/admin/api/notifications')->assertOk()->json('items');

    expect($items)->toHaveCount(1)
        ->and($items[0])->toMatchArray([
            'title' => '“Publish” finished', 'level' => 'success', 'icon' => 'download', 'run' => $run->id,
            'url' => "/admin/runs/{$run->id}/download", 'urlLabel' => 'Download posts.csv', 'download' => true,
        ]);

    // The panel serves its own copy (SECN-03): the host's original is left alone.
    expect($run->result['path'])->toBe("larsadmin/runs/{$run->id}/posts.csv")
        ->and(Storage::disk('local')->get($run->result['path']))->toBe('a,b')
        ->and(Storage::disk('local')->exists('exports/posts.csv'))->toBeTrue();

    $this->get("/admin/runs/{$run->id}/download")->assertOk();
    Storage::disk('local')->delete($run->result['path']);
    $this->get("/admin/runs/{$run->id}/download")->assertNotFound();
});

it('completes without a link when the result file cannot be copied', function () {
    Exceptions::fake();
    Storage::fake('local');
    [$post] = qwPosts(1);

    $run = qwWork(qwQueue(new QueuedAction(callback: fn () => ActionResponse::file('local', 'exports/missing.csv')), ['resources' => [$post->id]]));

    expect($run->only(['status', 'result']))->toBe(['status' => 'completed', 'result' => null]);
    Exceptions::assertReported(RuntimeException::class);
});

it('renders messages and runs handle() in the locale of the run', function () {
    Notification::fake();
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    $run->forceFill(['locale' => 'uk'])->save();

    $run = qwWork($run);

    expect($run->message)->toBe('Дію виконано.')
        ->and(QueuedAction::$calls[0]['locale'])->toBe('uk')
        ->and(app()->getLocale())->toBe('en');

    Notification::assertSentTo($this->user, LarsadminNotification::class, fn ($n) => $n->toArray($this->user)['title'] === 'Дію «Publish» завершено');
});

it('restores the request, the guard, its user and the locale afterwards', function () {
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    $other = NotifiableUser::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'x']);
    Auth::guard('web')->setUser($other);
    $request = app('request');

    qwWork($run);

    expect(app('request'))->toBe($request)
        ->and(Auth::guard('web')->user()->is($other))->toBeTrue()
        ->and(Auth::getDefaultDriver())->toBe('web')
        ->and(QueuedAction::$calls[0]['user'])->toBe($this->user->id);
});

it('leaves no user behind in a worker that had none', function () {
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    Auth::guard('web')->forgetUser();

    qwWork($run);

    expect(Auth::guard('web')->hasUser())->toBeFalse()->and($run->status)->toBe('completed');
});

it('re-authorizes as the user at run time and fails closed', function (Closure $revoke, string $message) {
    Notification::fake();
    $posts = qwPosts(2);
    $run = qwQueue(new QueuedAction, ['resources' => array_map(fn ($p) => $p->id, $posts)]);

    $revoke($this);
    $run = qwWork($run);

    expect($run->status)->toBe('failed')
        ->and($run->level)->toBe('danger')
        ->and($run->message)->toBe($message)
        ->and($run->payload)->toBeNull()
        ->and(Post::where('status', 'live')->count())->toBe(0);
})->with([
    'policy revoked' => [fn () => QueuedPostPolicy::$update = false, 'You are not allowed to run this action.'],
    'viewAny revoked' => [fn () => QueuedPostPolicy::$viewAny = false, 'You are not allowed to run this action.'],
    'panel access revoked' => [fn () => Larsadmin::auth(fn () => false), 'You are not allowed to run this action.'],
    'action hidden' => [fn () => QueuedPost::$actions[0]->canSee(fn () => false), 'You are not allowed to run this action.'],
    'entity unregistered' => [function () {
        Larsadmin::flushState();
        Larsadmin::auth(fn () => true);
    }, 'You are not allowed to run this action.'],
    'user deleted' => [fn ($test) => DB::table('users')->where('id', $test->user->id)->delete(), 'You are not allowed to run this action.'],
    'payload undecryptable' => [fn () => DB::table('larsadmin_action_runs')->update(['payload' => 'garbage']), 'An error occurred while running the action.'],
    'payload lost' => [fn () => DB::table('larsadmin_action_runs')->update(['payload' => null]), 'You are not allowed to run this action.'],
]);

it('fails closed for a user of another type or without a guard provider', function () {
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);

    $run->forceFill(['user_type' => 'App\\Models\\Admin'])->save();
    expect(ReplayedRequest::user($run))->toBeNull();

    $run->forceFill(['user_type' => $this->user->getMorphClass(), 'guard' => 'missing'])->save();
    expect(ReplayedRequest::user($run))->toBeNull();

    $run->forceFill(['guard' => 'web'])->save();
    expect(ReplayedRequest::user($run)?->is($this->user))->toBeTrue();
});

it('fails a standalone run whose create ability was revoked', function () {
    $run = qwQueue((new QueuedAction('export'))->standalone());
    QueuedPostPolicy::$create = false;

    expect(qwWork($run)->status)->toBe('failed')->and(QueuedAction::$calls)->toBe([]);
});

it('completes a standalone run', function () {
    $run = qwWork(qwQueue((new QueuedAction('export', fn () => ActionResponse::message('Exported.')))->standalone()));

    expect($run->only(['status', 'level', 'message', 'total']))->toBe(['status' => 'completed', 'level' => 'success', 'message' => 'Exported.', 'total' => null]);
});

it('skips the records the user may not run on, and fails when none is left', function () {
    $posts = [...qwPosts(2), ...qwPosts(1, prefix: 'locked')];
    $run = qwWork(qwQueue(new QueuedAction, ['resources' => array_map(fn ($p) => $p->id, $posts)]));

    expect($run->only(['status', 'ran', 'skipped', 'processed']))->toBe(['status' => 'completed', 'ran' => 2, 'skipped' => 1, 'processed' => 3])
        ->and($run->message)->toBe('Action completed. 1 record skipped (not allowed).');

    $locked = qwPosts(1, prefix: 'locked');
    $run = qwWork(qwQueue(new QueuedAction, ['resources' => [$locked[0]->id]]));

    expect($run->status)->toBe('failed')->and($run->message)->toBe('You are not allowed to run this action.');
});

it('rebuilds an "all" selection from the filters and search at run time', function () {
    Post::create(['title' => 'Alpha 1', 'status' => 'draft']);
    Post::create(['title' => 'Alpha live', 'status' => 'live']);
    Post::create(['title' => 'Beta', 'status' => 'draft']);
    $run = qwQueue(new QueuedAction, ['resources' => 'all', 'search' => 'Alpha', 'filters' => ['status' => 'draft']]);
    expect($run->total)->toBe(1);

    Post::create(['title' => 'Alpha 2', 'status' => 'draft']);
    $run = qwWork($run);

    expect($run->total)->toBe(2)
        ->and($run->ran)->toBe(2)
        ->and(Post::orderBy('id')->pluck('status', 'title')->all())->toBe(['Alpha 1' => 'live', 'Alpha live' => 'live', 'Beta' => 'draft', 'Alpha 2' => 'live']);
});

it('completes with a warning when nothing matches any more', function () {
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    $post->delete();

    expect(qwWork($run)->only(['status', 'level', 'message', 'total']))->toBe(['status' => 'completed', 'level' => 'warning', 'message' => 'No records matched.', 'total' => 0]);
});

it('stops between chunks when cancelled', function () {
    Notification::fake();
    $posts = qwPosts(5);
    $run = qwQueue(new QueuedAction, ['resources' => array_map(fn ($p) => $p->id, $posts)]);
    QueuedPost::$actions[0]->callback = function ($fields, $models) use ($run) {
        $models->each->update(['status' => 'live']);
        ActionRun::query()->whereKey($run->id)->update(['cancel_requested_at' => now()]);

        return null;
    };

    $run = qwWork($run);

    expect($run->only(['status', 'level', 'message', 'ran']))->toBe(['status' => 'cancelled', 'level' => 'warning', 'message' => 'Processed 2 of 5 records.', 'ran' => 2])
        ->and(Post::where('status', 'live')->count())->toBe(2);

    Notification::assertSentTo($this->user, LarsadminNotification::class, fn ($n) => $n->toArray($this->user)['title'] === '“Publish” was cancelled' && $n->toArray($this->user)['level'] === 'warning');
});

it('cancels a run whose cancellation was requested before it started', function () {
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    $run->forceFill(['cancel_requested_at' => now()])->save();

    expect(qwWork($run)->status)->toBe('cancelled')->and(QueuedAction::$calls)->toBe([]);
});

it('claims a run once: a second delivery is a no-op', function () {
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);

    qwWork($run);
    qwWork($run);
    (new RunQueuedAction('01jc0000000000000000000000'))->handle();

    expect(QueuedAction::$calls)->toHaveCount(1);
});

it('maps the action results', function (Closure $respond, array $expected) {
    Storage::fake('local');
    [$post] = qwPosts(1);

    $run = qwWork(qwQueue(new QueuedAction(callback: $respond), ['resources' => [$post->id]]));

    expect(collect($expected)->map(fn ($value, string $key) => data_get($run, $key))->all())->toBe($expected);
})->with([
    'message' => [fn () => ActionResponse::message('Done!'), ['status' => 'completed', 'level' => 'success', 'message' => 'Done!', 'result' => null]],
    'danger' => [fn () => ActionResponse::danger('Careful'), ['status' => 'completed', 'level' => 'danger', 'message' => 'Careful', 'result' => null]],
    'redirect' => [fn () => ActionResponse::redirect('/admin/entities/queued-posts'), ['status' => 'completed', 'level' => 'success', 'message' => 'Action completed.', 'result' => ['type' => 'redirect', 'url' => '/admin/entities/queued-posts']]],
    'download' => [fn () => ActionResponse::download('/admin/export.csv', 'export.csv'), ['status' => 'completed', 'level' => 'success', 'result' => ['type' => 'download', 'url' => '/admin/export.csv', 'filename' => 'export.csv']]],
    'file' => [function () {
        Storage::disk('local')->put('exports/a.csv', 'x');

        return ActionResponse::file('local', 'exports/a.csv', 'a.csv');
    }, ['status' => 'completed', 'result.type' => 'file']],
]);

it('fails without the exception text when handle() throws', function () {
    Exceptions::fake();
    $posts = qwPosts(4);

    $first = qwWork(qwQueue(new QueuedAction(callback: fn () => throw new RuntimeException('secret detail')), ['resources' => array_map(fn ($p) => $p->id, $posts)]));

    expect($first->only(['status', 'level', 'message']))->toBe(['status' => 'failed', 'level' => 'danger', 'message' => 'An error occurred while running the action.']);

    $second = qwWork(qwQueue(new QueuedAction('partial', function ($fields, $models, int $chunk) {
        if ($chunk === 2) {
            throw new RuntimeException('secret detail');
        }

        $models->each->update(['status' => 'live']);

        return null;
    }), ['resources' => array_map(fn ($p) => $p->id, $posts)]));

    expect($second->only(['status', 'message', 'ran']))->toBe(['status' => 'failed', 'message' => 'Completed for 2 of 4 records, then an error occurred.', 'ran' => 2]);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'secret detail');
});

it('fails the run when an authorization callback throws', function () {
    Exceptions::fake();
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    QueuedPost::$actions[0]->canSee(fn () => throw new LogicException('host bug'));

    expect(qwWork($run)->only(['status', 'message']))->toBe(['status' => 'failed', 'message' => 'An error occurred while running the action.']);
    Exceptions::assertReported(LogicException::class);
});

it('fails an unfinished run when the worker gives up, but never a finished one', function () {
    Notification::fake();
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    $run->forceFill(['status' => 'running', 'ran' => 3, 'total' => 10])->save();

    (new RunQueuedAction($run->id))->failed(new RuntimeException('timeout'));

    expect($run->refresh()->only(['status', 'message']))->toBe(['status' => 'failed', 'message' => 'Completed for 3 of 10 records, then an error occurred.']);

    $run->forceFill(['status' => 'completed', 'message' => 'kept'])->save();
    (new RunQueuedAction($run->id))->failed(new RuntimeException('late'));
    (new RunQueuedAction('01jc0000000000000000000000'))->failed();

    expect($run->refresh()->message)->toBe('kept');

    $fresh = qwQueue(new QueuedAction('other'), ['resources' => [$post->id]]);
    (new RunQueuedAction($fresh->id))->failed();
    expect($fresh->refresh()->message)->toBe('An error occurred while running the action.');
});

it('gives handle() date values as strings', function () {
    [$post] = qwPosts(1);

    qwWork(qwQueue(new QueuedAction(fieldList: [Date::make('Due')]), ['resources' => [$post->id], 'due' => '2026-11-20']));

    expect(QueuedAction::$calls[0]['fields'])->toBe(['due' => '2026-11-20']);
});

it('skips the notification for users without Notifiable', function () {
    config(['auth.providers.users.model' => PlainUser::class]);
    $this->actingAs($plain = PlainUser::create(['name' => 'P', 'email' => 'p@example.com', 'password' => 'x']));
    [$post] = qwPosts(1);

    $run = qwWork(qwQueue(new QueuedAction, ['resources' => [$post->id]]));

    expect($run->status)->toBe('completed')->and(DB::table('notifications')->count())->toBe(0);
});

it('keeps the run finished when the notification or the event fails', function () {
    Exceptions::fake();
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    Event::listen(ActionRunFinished::class, fn () => throw new RuntimeException('listener'));
    Event::listen(NotificationSending::class, fn () => throw new RuntimeException('sending'));

    expect(qwWork($run)->status)->toBe('completed');

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'listener');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'sending');
});

it('finishes inside the request with the sync driver and answers like a synchronous action', function () {
    config(['queue.default' => 'sync']);
    [$a, $b] = qwPosts(2);
    QueuedPost::$actions = [new QueuedAction(callback: fn () => ActionResponse::message('All published.'))];

    $this->from('/admin/entities/queued-posts')
        ->post('/admin/entities/queued-posts/actions/publish', ['resources' => [$a->id, $b->id]])
        ->assertRedirect('http://localhost/admin/entities/queued-posts')
        ->assertSessionHas('larsadmin.toast', ['message' => 'All published.', 'type' => 'success'])
        ->assertSessionMissing('larsadmin.run');

    expect(ActionRun::sole()->status)->toBe('completed')
        ->and(DB::table('notifications')->count())->toBe(1)
        ->and(Auth::user()->is($this->user))->toBeTrue();
});

it('answers sync-driver redirects, files and failures like synchronous actions', function () {
    config(['queue.default' => 'sync']);
    Storage::fake('local');
    Storage::disk('local')->put('exports/a.csv', 'x');
    [$post] = qwPosts(1);
    QueuedPost::$actions = [
        new QueuedAction('go', fn () => ActionResponse::redirect('/admin/dashboards/main')),
        new QueuedAction('file', fn () => ActionResponse::file('local', 'exports/a.csv', 'a.csv')),
        new QueuedAction('deny', fn () => ActionResponse::danger('Nope')),
    ];

    $this->post('/admin/entities/queued-posts/actions/go', ['resources' => [$post->id]])->assertRedirect('/admin/dashboards/main');

    $this->post('/admin/entities/queued-posts/actions/file', ['resources' => [$post->id]])
        ->assertSessionHas('larsadmin.download', ['url' => '/admin/runs/'.ActionRun::query()->where('action', 'file')->value('id').'/download', 'filename' => 'a.csv']);

    $this->post('/admin/entities/queued-posts/actions/deny', ['resources' => [$post->id]])
        ->assertSessionHas('larsadmin.toast', ['message' => 'Nope', 'type' => 'error']);
});

it('fails closed on a stored selection that is not a valid one', function () {
    [$post] = qwPosts(1);
    $run = qwQueue(new QueuedAction, ['resources' => [$post->id]]);
    $run->forceFill(['payload' => [...$run->payload, 'selection' => []]])->save();

    expect(qwWork($run)->only(['status', 'message']))->toBe(['status' => 'failed', 'message' => 'You are not allowed to run this action.'])
        ->and(QueuedAction::$calls)->toBe([]);
});
