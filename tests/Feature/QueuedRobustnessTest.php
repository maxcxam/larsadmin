<?php

use Illuminate\Http\Request;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\QueuedDispatcher;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/*
 * Review fixes of 1.4: single finish under redelivery (CRN-01), stale runs (CRN-02), the atomic limit
 * (SECN-01), the worker's session (SECN-02), re-authorization during a run and the worker cap (SECN-06),
 * the origin URL (SECN-09).
 */

beforeEach(function () {
    config([
        'larsadmin.notifications.enabled' => true,
        'larsadmin.locale' => 'en',
        'auth.providers.users.model' => NotifiableUser::class,
    ]);
    QueuedPost::reset();
    QueuedPostPolicy::reset();
    QueuedDispatcher::flushState();
    Gate::policy(Post::class, QueuedPostPolicy::class);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([QueuedPost::class]);

    $this->alice = NotifiableUser::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'secret']);
    $this->bob = NotifiableUser::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'secret']);
    $this->actingAs($this->alice);
});

function qrPosts(int $count): array
{
    return collect(range(1, $count))->map(fn (int $i) => Post::create(['title' => "Post {$i}", 'status' => 'draft'])->id)->all();
}

function qrQueue(QueuedAction $action, array $body, int $chunkSize = 1): ActionRun
{
    $action->chunkSize = $chunkSize;
    QueuedPost::$actions = [...array_filter(QueuedPost::$actions, fn (QueuedAction $a) => $a->uriKey() !== $action->uriKey()), $action];

    Bus::fake([RunQueuedAction::class]);
    test()->from('/admin/entities/queued-posts')->post("/admin/entities/queued-posts/actions/{$action->uriKey()}", $body)->assertSessionHas('larsadmin.run');

    return ActionRun::query()->latest('id')->firstOrFail();
}

function qrRow(object $user, array $attributes = []): ActionRun
{
    return ActionRun::query()->create([
        'entity' => 'queued-posts', 'action' => 'publish', 'action_name' => 'Publish',
        'user_type' => $user->getMorphClass(), 'user_id' => (string) $user->getKey(), 'guard' => 'web',
        'locale' => 'en', 'status' => 'running', 'total' => 10, 'payload' => ['selection' => [1]],
        ...$attributes,
    ]);
}

// --- CRN-01: redelivery and failed() never clobber a live run ------------------------------------------

it('leaves a live run alone when a redelivery gives up on it, and finishes it once', function () {
    Notification::fake();
    $ids = qrPosts(3);
    $run = qrQueue(new QueuedAction, ['resources' => $ids]);
    $redelivered = false;

    // While the first worker runs, the queue redelivers the job: the framework fails it before handle().
    QueuedPost::$actions[0]->callback = function ($fields, $models, int $chunk) use ($run, &$redelivered) {
        $models->each->update(['status' => 'live']);

        if ($chunk === 2 && ! $redelivered) {
            $redelivered = true;
            (new RunQueuedAction($run->id))->failed(new MaxAttemptsExceededException('attempted too many times'));
            expect(ActionRun::find($run->id)->status)->toBe('running');
        }

        return null;
    };

    (new RunQueuedAction($run->id))->handle();

    expect($redelivered)->toBeTrue()
        ->and($run->refresh()->only(['status', 'ran']))->toBe(['status' => 'completed', 'ran' => 3]);

    Notification::assertSentToTimes($this->alice, LarsadminNotification::class, 1);
});

it('fails a run that gave up without a heartbeat, and ignores the late worker', function () {
    Notification::fake();
    $run = qrRow($this->alice, ['ran' => 2, 'payload' => ['selection' => [1], 'timeout' => 60]]);
    $this->travel(5)->minutes();

    (new RunQueuedAction($run->id))->failed(new MaxAttemptsExceededException('attempted too many times'));

    expect($run->refresh()->only(['status', 'message', 'payload']))->toBe(['status' => 'failed', 'message' => 'Completed for 2 of 10 records, then an error occurred.', 'payload' => null]);

    // The original worker finishing later changes nothing and notifies nobody again.
    expect(RunQueuedAction::finish($run, 'completed', 'success', 'late', null, $this->alice))->toBeFalse()
        ->and($run->refresh()->status)->toBe('failed');

    Notification::assertSentToTimes($this->alice, LarsadminNotification::class, 1);
});

it('still fails a run whose own worker timed out', function () {
    $run = qrRow($this->alice);

    (new RunQueuedAction($run->id))->failed(new RuntimeException('timed out'));

    expect($run->refresh()->status)->toBe('failed');
});

it('warns once when an action may outlive the connection\'s retry_after', function () {
    Log::spy();
    config(['queue.connections.database.retry_after' => 90]);
    $ids = qrPosts(1);
    $action = (new QueuedAction)->onQueueConnection('database');
    $action->timeout = 300;

    qrQueue($action, ['resources' => $ids]);
    qrQueue($action, ['resources' => $ids]);

    // A timeout below retry_after is fine: still the one warning in total.
    QueuedDispatcher::flushState();
    $action->timeout = 60;
    qrQueue($action, ['resources' => $ids]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'retry_after of 90 s'));
});

// --- CRN-02: stale runs --------------------------------------------------------------------------------

it('neither counts nor polls stale runs, and fails them once', function () {
    Notification::fake();
    Bus::fake([RunQueuedAction::class]);
    config(['larsadmin.actions.queued_max_active' => 1, 'larsadmin.actions.stale_after' => 600]);
    $stale = qrRow($this->alice);
    $this->travel(11)->minutes();

    $this->getJson('/admin/api/notifications/status')->assertJsonPath('activeRuns', 0);
    expect($stale->refresh()->only(['status', 'payload']))->toBe(['status' => 'failed', 'payload' => null]);

    $ids = qrPosts(1);
    QueuedPost::$actions = [new QueuedAction];
    $this->post('/admin/entities/queued-posts/actions/publish', ['resources' => $ids])->assertSessionHas('larsadmin.run');

    Notification::assertSentToTimes($this->alice, LarsadminNotification::class, 1);
});

it('uses the action timeout plus a margin for running runs and queued_stale_after for queued ones', function () {
    config(['larsadmin.actions.stale_after' => 3600, 'larsadmin.actions.queued_stale_after' => 7200]);
    $timed = qrRow($this->alice, ['payload' => ['selection' => [1], 'timeout' => 120]]);
    $untimed = qrRow($this->alice);
    $queued = qrRow($this->alice, ['status' => 'queued']);
    $undecryptable = qrRow($this->alice);
    DB::table('larsadmin_action_runs')->where('id', $undecryptable->id)->update(['payload' => 'garbage']);

    $this->travel(181)->seconds();
    expect($timed->isStale())->toBeTrue()->and($untimed->isStale())->toBeFalse()
        ->and(ActionRun::find($undecryptable->id)->staleAfter())->toBe(3660);

    $this->travel(2)->hours();
    expect($untimed->isStale())->toBeTrue()->and($queued->isStale())->toBeTrue()
        ->and($timed->refresh()->status)->toBe('running');
});

it('lists stale runs as failed and lets their owner cancel them at once', function () {
    config(['larsadmin.actions.stale_after' => 600]);
    $listed = qrRow($this->alice);
    $cancelled = qrRow($this->alice, ['ran' => 4]);
    $this->travel(11)->minutes();

    $this->getJson("/admin/api/runs/{$cancelled->id}/cancel")->assertMethodNotAllowed();
    $this->postJson("/admin/api/runs/{$cancelled->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('message', 'Processed 4 of 10 records.');

    $runs = collect($this->getJson('/admin/api/runs')->json('runs'))->keyBy('id');

    expect($runs[$listed->id]['status'])->toBe('failed')->and($runs[$listed->id]['cancellable'])->toBeFalse();

    $shown = qrRow($this->alice);
    $this->travel(11)->minutes();
    $this->getJson("/admin/api/runs/{$shown->id}")->assertJsonPath('status', 'failed');
});

it('does not refresh the heartbeat when a cancel is requested', function () {
    $run = qrRow($this->alice);
    $heartbeat = $run->updated_at->toDateTimeString();
    $this->travel(1)->minute();

    $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertOk()->assertJsonPath('cancelRequested', true);

    expect($run->refresh()->updated_at->toDateTimeString())->toBe($heartbeat);
});

it('stops a run that was failed as stale while its worker was still going', function () {
    $ids = qrPosts(3);
    $run = qrQueue(new QueuedAction, ['resources' => $ids]);
    QueuedPost::$actions[0]->callback = function ($fields, $models, int $chunk) use ($run) {
        $models->each->update(['status' => 'live']);

        if ($chunk === 1) {
            ActionRun::query()->whereKey($run->id)->update(['status' => 'failed', 'message' => 'swept']);
        }

        return null;
    };

    (new RunQueuedAction($run->id))->handle();

    expect($run->refresh()->only(['status', 'message']))->toBe(['status' => 'failed', 'message' => 'swept'])
        ->and(Post::where('status', 'live')->count())->toBe(1);
});

// --- SECN-01: the active-run limit is atomic --------------------------------------------------------------

it('never exceeds the active-run limit when another request inserts between the count and the insert', function () {
    Bus::fake([RunQueuedAction::class]);
    config(['larsadmin.actions.queued_max_active' => 1]);
    $ids = qrPosts(1);
    QueuedPost::$actions = [new QueuedAction];
    $raced = false;

    // A concurrent request of the same user (not holding the lock) slips its run in right after our count.
    DB::listen(function ($query) use (&$raced) {
        if (! $raced && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'larsadmin_action_runs')) {
            $raced = true;
            qrRow($this->alice, ['status' => 'queued']);
        }
    });

    $this->post('/admin/entities/queued-posts/actions/publish', ['resources' => $ids])
        ->assertSessionHas('larsadmin.toast', ['message' => 'Too many background tasks. Wait for the previous ones to finish (at most 1).', 'type' => 'error']);

    expect($raced)->toBeTrue()
        ->and(ActionRun::count())->toBe(1);

    Bus::assertNotDispatched(RunQueuedAction::class);
});

it('serializes the dispatches of one user with a lock', function () {
    Bus::fake([RunQueuedAction::class]);
    $ids = qrPosts(1);
    QueuedPost::$actions = [new QueuedAction];
    $key = 'larsadmin:runs:'.sha1($this->alice->getMorphClass().'|'.$this->alice->id);
    $held = null;

    DB::listen(function ($query) use ($key, &$held) {
        if ($held === null && str_contains($query->sql, 'larsadmin_action_runs')) {
            // While our dispatch counts, the lock is taken.
            $held = ! Cache::lock($key, 1)->get();
        }
    });

    $this->post('/admin/entities/queued-posts/actions/publish', ['resources' => $ids])->assertSessionHas('larsadmin.run');

    expect($held)->toBeTrue()->and(Cache::lock($key, 1)->get())->toBeTrue();
});

// --- SECN-02: the global session is per job -----------------------------------------------------------------

it('gives every job its own global session and restores the worker\'s', function () {
    $ids = qrPosts(1);
    $seen = [];
    $writer = new QueuedAction('write', function () use (&$seen) {
        session()->put('team_secret', 'alice-only');
        $seen['write'] = session('team_secret');

        return null;
    });
    $reader = new QueuedAction('read', function () use (&$seen) {
        $seen['read'] = session('team_secret');
        $seen['facade'] = Session::get('team_secret');

        return null;
    });

    $aliceRun = qrQueue($writer, ['resources' => $ids]);
    $this->actingAs($this->bob);
    $bobRun = qrQueue($reader, ['resources' => $ids]);

    $worker = app('session.store');
    $worker->put('worker_value', 'kept');

    (new RunQueuedAction($aliceRun->id))->handle();
    (new RunQueuedAction($bobRun->id))->handle();

    expect($seen)->toBe(['write' => 'alice-only', 'read' => null, 'facade' => null])
        ->and(app('session.store'))->toBe($worker)
        ->and(session('worker_value'))->toBe('kept')
        ->and(session('team_secret'))->toBeNull();
});

it('keeps the request session clean under the sync driver', function () {
    config(['queue.default' => 'sync']);
    $ids = qrPosts(1);
    QueuedPost::$actions = [new QueuedAction(callback: function () {
        session()->put('team_secret', 'from-the-job');

        return null;
    })];

    $this->post('/admin/entities/queued-posts/actions/publish', ['resources' => $ids])
        ->assertSessionHas('larsadmin.toast')
        ->assertSessionMissing('team_secret');

    expect(ActionRun::sole()->status)->toBe('completed');
});

// --- SECN-06: re-authorization during the run, the worker cap ----------------------------------------------

it('stops as unauthorized when panel access or the user disappears during the run', function (Closure $revoke) {
    $ids = qrPosts(3);
    $run = qrQueue(new QueuedAction, ['resources' => $ids]);
    QueuedPost::$actions[0]->callback = function ($fields, $models) use ($revoke) {
        $models->each->update(['status' => 'live']);
        $revoke($this);

        return null;
    };

    (new RunQueuedAction($run->id))->handle();

    expect($run->refresh()->only(['status', 'message', 'ran']))->toBe(['status' => 'failed', 'message' => 'You are not allowed to run this action.', 'ran' => 1])
        ->and(Post::where('status', 'live')->count())->toBe(1);
})->with([
    'panel access revoked' => [fn () => Larsadmin::auth(fn () => false)],
    'user deleted' => [fn ($test) => DB::table('users')->where('id', $test->alice->id)->delete()],
]);

it('applies queued_max_all again in the worker', function () {
    config(['larsadmin.actions.queued_max_all' => 2]);
    $ids = qrPosts(2);
    $run = qrQueue(new QueuedAction, ['resources' => 'all']);
    qrPosts(1);

    (new RunQueuedAction($run->id))->handle();

    expect($run->refresh()->only(['status', 'message']))->toBe(['status' => 'failed', 'message' => 'Too many records selected: 3 matched, the limit is 2. Narrow the search or filters.'])
        ->and(QueuedAction::$calls)->toBe([]);
});

// --- SECN-09: the origin URL ------------------------------------------------------------------------------

it('replays the configured application URL, never the Host header', function () {
    config(['app.url' => 'https://admin.example.com']);
    $ids = qrPosts(1);
    QueuedPost::$actions = [new QueuedAction];
    Bus::fake([RunQueuedAction::class]);

    $this->withHeader('Host', 'evil.test')->post('/admin/entities/queued-posts/actions/publish', ['resources' => $ids]);

    expect(ActionRun::sole()->payload['origin_url'])->toBe('https://admin.example.com/admin/entities/queued-posts/actions/publish');

    config(['larsadmin.domain' => 'panel.example.com']);
    expect(QueuedDispatcher::originUrl(Request::create('https://x.test/admin/a')))->toBe('https://panel.example.com/admin/a');

    config(['app.url' => '']);
    expect(QueuedDispatcher::originUrl(Request::create('https://x.test/admin/a')))->toBe('https://x.test/admin/a');
});
