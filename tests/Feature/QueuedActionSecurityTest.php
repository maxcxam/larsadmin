<?php

use Illuminate\Bus\Dispatcher as RealBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Http\Controllers\RunController;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Security\ScopedQueuedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/*
 * T1 (1.4): queued actions seen from an attacker (design 1.4 §8, §9; ADR-028, ADR-029; NFR-025..NFR-028):
 * the worker re-authorizes everything as the user at run time and fails closed, leaves no identity behind,
 * keeps the payload encrypted and erases it, honours the limits, cancellation and single delivery, and
 * audits with the dispatch actor. Alice and Bob are two panel users; Bob is signed in.
 */

beforeEach(function () {
    config([
        'larsadmin.notifications.enabled' => true,
        'larsadmin.locale' => 'en',
        'app.debug' => false,
        'auth.providers.users.model' => NotifiableUser::class,
    ]);
    ScopedQueuedPost::reset();
    QueuedPostPolicy::reset();
    Gate::policy(Post::class, QueuedPostPolicy::class);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ScopedQueuedPost::class]);

    $this->alice = NotifiableUser::create(['name' => 'Alice Victim', 'email' => 'alice@tenant.test', 'password' => 'secret']);
    $this->bob = NotifiableUser::create(['name' => 'Bob Attacker', 'email' => 'bob@tenant.test', 'password' => 'secret']);
    $this->actingAs($this->bob);
});

afterEach(function () {
    ScopedQueuedPost::reset();
    QueuedPostPolicy::reset();
});

/**
 * @return list<Post>
 */
function qsecPosts(int $count, string $prefix = 'Post', string $status = 'draft'): array
{
    return collect(range(1, $count))->map(fn (int $i) => Post::create(['title' => "{$prefix} {$i}", 'status' => $status]))->all();
}

function qsecIds(array $posts): array
{
    return array_map(fn (Post $post) => $post->id, $posts);
}

/**
 * Queue a run through the panel with the job faked, as the signed-in user, and return it.
 */
function qsecQueue(QueuedAction $action, array $body = [], int $chunkSize = 2): ActionRun
{
    $action->chunkSize = $chunkSize;
    ScopedQueuedPost::$actions = [...array_filter(ScopedQueuedPost::$actions, fn (QueuedAction $a) => $a->uriKey() !== $action->uriKey()), $action];

    Bus::fake([RunQueuedAction::class]);
    test()->from('/admin/entities/scoped-posts')->post("/admin/entities/scoped-posts/actions/{$action->uriKey()}", $body)->assertSessionHas('larsadmin.run');

    return ActionRun::query()->latest('id')->firstOrFail();
}

function qsecWork(ActionRun $run): ActionRun
{
    (new RunQueuedAction($run->id))->handle();

    return $run->refresh();
}

/**
 * Put the real bus back after Bus::fake() (to run jobs on the "sync" driver).
 */
function qsecRealBus(): void
{
    Bus::swap(app(RealBus::class));
}

function qsecRawPayload(ActionRun $run): ?string
{
    return DB::table('larsadmin_action_runs')->where('id', $run->id)->value('payload');
}

// --- Worker re-authorization (fail closed) ----------------------------------------------------------

it('processes nothing when the records left the row scope before the run', function (string $selection) {
    $posts = qsecPosts(3);
    $run = qsecQueue(new QueuedAction, ['resources' => $selection === 'all' ? 'all' : qsecIds($posts)]);
    expect($run->total)->toBe(3);

    ScopedQueuedPost::$hiddenStatuses = ['draft'];
    $run = qsecWork($run);

    expect($run->only(['status', 'total', 'ran', 'payload']))->toBe(['status' => 'completed', 'total' => 0, 'ran' => 0, 'payload' => null])
        ->and(QueuedAction::$calls)->toBe([])
        ->and(Post::where('status', 'live')->count())->toBe(0);
})->with(['listed ids' => ['ids'], '"all"' => ['all']]);

it('processes only the records still in the row scope at run time', function (string $selection) {
    $posts = qsecPosts(4);
    $run = qsecQueue(new QueuedAction, ['resources' => $selection === 'all' ? 'all' : qsecIds($posts)]);

    $posts[0]->update(['title' => 'hidden now']);
    $posts[2]->update(['title' => 'hidden too']);
    $run = qsecWork($run);

    expect($run->only(['status', 'total', 'ran']))->toBe(['status' => 'completed', 'total' => 2, 'ran' => 2])
        ->and(collect(QueuedAction::$calls)->pluck('ids')->flatten()->all())->toBe([$posts[1]->id, $posts[3]->id])
        ->and(Post::where('title', 'like', 'hidden%')->where('status', 'live')->count())->toBe(0);
})->with(['listed ids' => ['ids'], '"all"' => ['all']]);

it('never processes a record outside the scope, even when the stored selection names it', function () {
    [$visible] = qsecPosts(1);
    [$hidden] = qsecPosts(1, 'hidden');
    $run = qsecQueue(new QueuedAction, ['resources' => [$visible->id]]);

    $run->forceFill(['payload' => [...$run->payload, 'selection' => [$visible->id, $hidden->id, '1 OR 1=1', -1, 99999]]])->save();
    $run = qsecWork($run);

    expect(collect(QueuedAction::$calls)->pluck('ids')->flatten()->all())->toBe([$visible->id])
        ->and($hidden->refresh()->status)->toBe('draft')
        ->and($run->total)->toBe(1);
});

it('never widens an "all" run with stored filters or search outside the scope', function () {
    qsecPosts(2);
    [$hidden] = qsecPosts(1, 'hidden');
    $run = qsecQueue(new QueuedAction, ['resources' => 'all']);

    $run->forceFill(['payload' => [...$run->payload, 'search' => "hidden' OR '1'='1", 'filters' => ['status' => ['draft', 'live'], 'title' => 'hidden 1']]])->save();
    qsecWork($run);

    expect(collect(QueuedAction::$calls)->pluck('ids')->flatten()->all())->not->toContain($hidden->id)
        ->and($hidden->refresh()->status)->toBe('draft');
});

it('re-checks every record between chunks: a right revoked mid-run skips the rest', function () {
    $posts = qsecPosts(4);
    $run = qsecQueue(new QueuedAction(callback: function ($fields, $models, int $chunk) {
        $models->each->update(['status' => 'live']);
        QueuedPostPolicy::$update = false;

        return null;
    }), ['resources' => qsecIds($posts)]);

    $run = qsecWork($run);

    expect($run->only(['status', 'ran', 'skipped', 'processed']))->toBe(['status' => 'completed', 'ran' => 2, 'skipped' => 2, 'processed' => 4])
        ->and(Post::where('status', 'live')->pluck('id')->all())->toBe([$posts[0]->id, $posts[1]->id]);
});

it('fails closed as the run\'s user, whatever the worker may do', function (Closure $revoke) {
    $posts = qsecPosts(2);
    $run = qsecQueue(new QueuedAction, ['resources' => qsecIds($posts)]);

    $revoke($this);
    $run = qsecWork($run);

    expect($run->only(['status', 'level', 'message', 'payload']))->toBe([
        'status' => 'failed', 'level' => 'danger', 'message' => 'You are not allowed to run this action.', 'payload' => null,
    ])->and(QueuedAction::$calls)->toBe([]);
})->with([
    'panel access lost by this user only' => [fn ($t) => Larsadmin::auth(fn (Request $request) => $request->user()?->is($t->alice) === true)],
    'viewAny lost' => [fn () => QueuedPostPolicy::$viewAny = false],
    'authorizedToRun lost on every record' => [fn () => ScopedQueuedPost::$actions[0]->canRun(fn () => false)],
    'policy update lost' => [fn () => QueuedPostPolicy::$update = false],
    'the action is no longer offered' => [fn () => ScopedQueuedPost::$actions = []],
    'the user now belongs to another model' => [fn ($t) => ActionRun::query()->update(['user_type' => 'App\\Models\\Admin'])],
    'the run moved to another user id' => [fn () => ActionRun::query()->update(['user_id' => '999'])],
]);

it('authorizes each run as its own user, not the user the worker last acted as', function () {
    [$post] = qsecPosts(1);
    $bobs = qsecQueue(new QueuedAction('bob-run'), ['resources' => [$post->id]]);
    $this->actingAs($this->alice);
    $alices = qsecQueue(new QueuedAction('alice-run'), ['resources' => [$post->id]]);

    // Only Bob keeps panel access; the worker runs Bob's job first, then Alice's.
    Larsadmin::auth(fn (Request $request) => $request->user()?->is($this->bob) === true);
    $this->app['auth']->forgetGuards();

    expect(qsecWork($bobs)->status)->toBe('completed')
        ->and(qsecWork($alices)->status)->toBe('failed')
        ->and(array_column(QueuedAction::$calls, 'user'))->toBe([$this->bob->id]);
});

// --- Worker state (sync driver, two jobs in sequence) -----------------------------------------------

it('leaves no borrowed user, request, session or locale to the next job, even when a job fails', function () {
    Exceptions::fake();
    [$post] = qsecPosts(1);
    $seen = [];
    $record = function (string $job) use (&$seen) {
        return function ($fields, $models) use ($job, &$seen) {
            $seen[$job] = [
                'auth' => Auth::id(),
                'guard' => Auth::guard('web')->id(),
                'request' => request()->user()?->getAuthIdentifier(),
                'session' => request()->session()->get('leak'),
                'locale' => app()->getLocale(),
            ];
            request()->session()->put('leak', $job);

            if ($job === 'first') {
                throw new RuntimeException('first job failed');
            }

            return null;
        };
    };

    $this->actingAs($this->alice);
    $first = qsecQueue(new QueuedAction('first', $record('first')), ['resources' => [$post->id]]);
    $first->forceFill(['locale' => 'uk'])->save();
    $this->actingAs($this->bob);
    $second = qsecQueue(new QueuedAction('second', $record('second')), ['resources' => [$post->id]]);

    // A worker process: no user, no request of its own, the sync driver.
    config(['queue.default' => 'sync']);
    qsecRealBus();
    $this->app['auth']->forgetGuards();
    $request = app('request');

    dispatch(new RunQueuedAction($first->id));
    dispatch(new RunQueuedAction($second->id));

    expect($first->refresh()->status)->toBe('failed')
        ->and($second->refresh()->status)->toBe('completed')
        ->and($seen['first'])->toBe(['auth' => $this->alice->id, 'guard' => $this->alice->id, 'request' => $this->alice->id, 'session' => null, 'locale' => 'uk'])
        ->and($seen['second'])->toBe(['auth' => $this->bob->id, 'guard' => $this->bob->id, 'request' => $this->bob->id, 'session' => null, 'locale' => 'en'])
        ->and(Auth::guard('web')->hasUser())->toBeFalse()
        ->and(Auth::getDefaultDriver())->toBe('web')
        ->and(app('request'))->toBe($request)
        ->and(app()->getLocale())->toBe('en');

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'first job failed');
});

it('gives the signed-in user back after a sync-driver run inside a request', function () {
    config(['queue.default' => 'sync']);
    [$post] = qsecPosts(1);
    ScopedQueuedPost::$actions = [new QueuedAction(callback: fn () => throw new RuntimeException('boom'))];
    Exceptions::fake();

    $this->post('/admin/entities/scoped-posts/actions/publish', ['resources' => [$post->id]])->assertRedirect();

    expect(ActionRun::sole()->status)->toBe('failed')
        ->and(Auth::user()?->is($this->bob))->toBeTrue()
        ->and(Auth::getDefaultDriver())->toBe('web');
});

// --- Encryption at rest -----------------------------------------------------------------------------

it('keeps the selection, filters, search, field values and actor only encrypted in the runs table', function () {
    qsecPosts(2, 'needle');
    $run = qsecQueue(new QueuedAction(fieldList: [Text::make('Note'), Password::make('Secret')]), [
        'resources' => 'all',
        'search' => 'needle',
        'filters' => ['status' => 'draft'],
        'note' => 'note-plaintext-value',
        'secret' => 'hunter2-supersecret',
    ]);

    $raw = (string) qsecRawPayload($run);

    foreach (['note-plaintext-value', 'hunter2-supersecret', 'needle', 'draft', 'selection', 'origin_url', 'Bob Attacker', 'scoped-posts'] as $plain) {
        expect($raw)->not->toContain($plain);
    }

    $decrypted = json_decode(Crypt::decryptString($raw), true);

    expect($decrypted)->toMatchArray(['selection' => 'all', 'search' => 'needle', 'filters' => ['status' => 'draft']])
        ->and($decrypted['fields']['note'])->toBe('note-plaintext-value')
        // A Password field arrives hashed: not even the encrypted payload holds the plain value.
        ->and($decrypted['fields']['secret'])->not->toBe('hunter2-supersecret')
        ->and(Hash::check('hunter2-supersecret', $decrypted['fields']['secret']))->toBeTrue()
        ->and(DB::table('larsadmin_action_runs')->where('id', $run->id)->first())->not->toHaveProperty('fields');
});

it('puts nothing but the run id on the queue', function () {
    Queue::fake();
    [$post] = qsecPosts(1);
    ScopedQueuedPost::$actions = [new QueuedAction(fieldList: [Password::make('Secret')])];

    $this->post('/admin/entities/scoped-posts/actions/publish', ['resources' => [$post->id], 'secret' => 'hunter2-supersecret'])->assertSessionHas('larsadmin.run');

    Queue::assertPushed(RunQueuedAction::class, function (RunQueuedAction $job) {
        $serialized = serialize($job);

        return $job->runId === ActionRun::sole()->id
            && ! str_contains($serialized, 'hunter2')
            && ! str_contains($serialized, 'Bob Attacker')
            && ! str_contains($serialized, 'payload');
    });
});

it('erases the payload at the end of every run', function (Closure $finish) {
    Exceptions::fake();
    $posts = qsecPosts(2);
    $run = qsecQueue(new QueuedAction(fieldList: [Password::make('Secret')]), ['resources' => qsecIds($posts), 'secret' => 'hunter2-supersecret']);
    expect(qsecRawPayload($run))->not->toBeNull();

    $finish($this, $run);

    expect($run->refresh()->isFinished())->toBeTrue()
        ->and(qsecRawPayload($run))->toBeNull();
})->with([
    'completed' => [fn ($t, $run) => qsecWork($run)],
    'handle() threw' => [function ($t, $run) {
        ScopedQueuedPost::$actions[0]->callback = fn () => throw new RuntimeException('boom');
        qsecWork($run);
    }],
    'unauthorized at run time' => [function ($t, $run) {
        QueuedPostPolicy::$viewAny = false;
        qsecWork($run);
    }],
    'nothing matched any more' => [function ($t, $run) {
        Post::query()->delete();
        qsecWork($run);
    }],
    'cancelled while queued' => [fn ($t, $run) => $t->postJson("/admin/api/runs/{$run->id}/cancel")->assertOk()],
    'cancel requested before the worker started' => [function ($t, $run) {
        $run->forceFill(['cancel_requested_at' => now()])->save();
        qsecWork($run);
    }],
    'the worker gave up' => [fn ($t, $run) => (new RunQueuedAction($run->id))->failed(new RuntimeException('timeout'))],
]);

// --- Run notifications ------------------------------------------------------------------------------

it('stores the run notification without field values, record data, disk or path', function () {
    Storage::fake('local');
    Storage::disk('local')->put('private/secret-dir/report.csv', 'x');
    [$post] = qsecPosts(1, 'Confidential record');
    $run = qsecQueue(new QueuedAction(
        callback: fn () => ActionResponse::file('local', 'private/secret-dir/report.csv', 'report.csv'),
        fieldList: [Password::make('Secret'), Text::make('Note')],
    ), ['resources' => [$post->id], 'secret' => 'hunter2-supersecret', 'note' => 'note-plaintext-value']);

    qsecWork($run);

    $raw = (string) DB::table('notifications')->value('data');
    $data = json_decode($raw, true);

    expect(array_keys($data))->toBe(['v', 'title', 'body', 'level', 'icon', 'url', 'urlLabel', 'run'])
        ->and($data)->toMatchArray(['url' => null, 'run' => $run->id, 'urlLabel' => 'Download report.csv']);

    foreach (['hunter2', 'note-plaintext-value', 'Confidential', 'secret-dir', '"disk"', '"path"', 'Bob Attacker'] as $leak) {
        expect($raw)->not->toContain($leak);
    }

    // The link is rebuilt for the owner only.
    $this->actingAs($this->alice);
    $this->getJson('/admin/api/notifications')->assertOk()->assertJsonCount(0, 'items');
});

// --- Limits -----------------------------------------------------------------------------------------

it('allows at most 3 active runs per user by default, not counting other users', function () {
    [$post] = qsecPosts(1);
    foreach (range(1, 3) as $ignored) {
        ActionRun::query()->create([
            'entity' => 'scoped-posts', 'action' => 'publish', 'action_name' => 'Publish', 'user_type' => $this->alice->getMorphClass(),
            'user_id' => (string) $this->alice->id, 'guard' => 'web', 'locale' => 'en', 'status' => 'running', 'total' => 1,
        ]);
    }

    foreach (range(1, 3) as $i) {
        qsecQueue(new QueuedAction("publish-{$i}"), ['resources' => [$post->id]]);
    }

    ScopedQueuedPost::$actions[] = $fourth = new QueuedAction('publish-4');
    $this->post('/admin/entities/scoped-posts/actions/publish-4', ['resources' => [$post->id]])
        ->assertSessionHas('larsadmin.toast', ['message' => 'Too many background tasks. Wait for the previous ones to finish (at most 3).', 'type' => 'error'])
        ->assertSessionMissing('larsadmin.run');

    expect(ActionRun::query()->ownedBy($this->bob)->count())->toBe(3);
});

it('caps an "all" selection at 10 000 records by default', function () {
    Bus::fake([RunQueuedAction::class]);
    ScopedQueuedPost::$actions = [new QueuedAction];
    $now = now()->format('Y-m-d H:i:s');

    foreach (array_chunk(range(1, 10000), 500) as $chunk) {
        DB::table('posts')->insert(array_map(fn (int $i) => ['title' => "Bulk {$i}", 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now], $chunk));
    }

    $this->post('/admin/entities/scoped-posts/actions/publish', ['resources' => 'all'])->assertSessionHasNoErrors()->assertSessionHas('larsadmin.run');
    expect(ActionRun::sole()->total)->toBe(10000);

    Post::create(['title' => 'One too many', 'status' => 'draft']);

    $this->post('/admin/entities/scoped-posts/actions/publish', ['resources' => 'all'])
        ->assertSessionHasErrors(['resources' => 'Too many records selected: 10001 matched, the limit is 10000. Narrow the search or filters.']);

    expect(ActionRun::count())->toBe(1);
    Bus::assertDispatchedTimes(RunQueuedAction::class, 1);
});

// --- Cancellation -----------------------------------------------------------------------------------

it('stops a running run after its current chunk when the owner cancels through the endpoint', function () {
    $posts = qsecPosts(6);
    $run = qsecQueue(new QueuedAction(callback: function ($fields, $models, int $chunk) use (&$run) {
        $models->each->update(['status' => 'live']);

        if ($chunk === 1) {
            $request = Request::create("/admin/api/runs/{$run->id}/cancel", 'POST');
            $request->setUserResolver(fn () => $this->bob);
            $answer = app(RunController::class)->cancel($request, $run->id)->getData(true);

            expect($answer)->toMatchArray(['status' => 'running', 'cancelRequested' => true]);
        }

        return null;
    }), ['resources' => qsecIds($posts)]);

    $run = qsecWork($run);

    expect($run->only(['status', 'ran', 'level']))->toBe(['status' => 'cancelled', 'ran' => 2, 'level' => 'warning'])
        ->and(Post::where('status', 'live')->count())->toBe(2)
        ->and(Inbox::for($this->bob)->count())->toBe(1);
});

it('never executes a run its owner cancelled while it was queued', function () {
    [$post] = qsecPosts(1);
    $run = qsecQueue(new QueuedAction, ['resources' => [$post->id]]);

    $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    $run = qsecWork($run);

    expect($run->status)->toBe('cancelled')
        ->and(QueuedAction::$calls)->toBe([])
        ->and($post->refresh()->status)->toBe('draft')
        ->and(Inbox::for($this->bob)->count())->toBe(1);
});

it('lets nobody but the owner cancel a run', function () {
    [$post] = qsecPosts(1);
    $run = qsecQueue(new QueuedAction, ['resources' => [$post->id]]);

    $this->actingAs($this->alice);
    $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertNotFound();

    expect(qsecWork($run)->status)->toBe('completed')->and(QueuedAction::$calls)->toHaveCount(1);
});

// --- Duplicate delivery -----------------------------------------------------------------------------

it('never retries a job', function () {
    $job = new RunQueuedAction('01jc0000000000000000000000');

    expect($job->tries)->toBe(1)
        ->and($job->maxExceptions)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and(array_keys(get_object_vars($job)))->not->toContain('payload');
});

it('treats a duplicate delivery as a no-op', function (?string $status) {
    config(['larsadmin.audit.enabled' => true]);
    ScopedQueuedPost::$auditable = true;
    [$post] = qsecPosts(1);
    $run = qsecQueue(new QueuedAction, ['resources' => [$post->id]]);

    if ($status === null) {
        qsecWork($run);
    } else {
        $run->forceFill(['status' => $status])->save();
    }

    $before = [QueuedAction::$calls, Inbox::for($this->bob)->count(), AuditEntry::count(), $run->refresh()->status];

    qsecWork($run);
    qsecWork($run);

    expect([QueuedAction::$calls, Inbox::for($this->bob)->count(), AuditEntry::count(), $run->refresh()->status])->toBe($before);
})->with([
    'after it completed' => [null],
    'while another worker runs it' => ['running'],
    'after it failed' => ['failed'],
    'after it was cancelled' => ['cancelled'],
]);

// --- Audit ------------------------------------------------------------------------------------------

it('audits queued changes with the dispatch actor and the run\'s batch, never the worker\'s identity', function () {
    config(['larsadmin.audit.enabled' => true]);
    ScopedQueuedPost::$auditable = true;
    Gate::define('viewLarsadminAudit', fn () => true);
    $posts = qsecPosts(3);

    $this->withHeaders(['User-Agent' => 'Bob-Browser/1.0'])->withServerVariables(['REMOTE_ADDR' => '10.9.8.7']);
    $run = qsecQueue(new QueuedAction, ['resources' => qsecIds($posts)]);

    // The worker process happens to hold another identity and request.
    $this->actingAs($this->alice);
    app('request')->server->set('REMOTE_ADDR', '192.0.2.1');
    app('request')->headers->set('User-Agent', 'Worker/9');
    qsecWork($run);

    $entries = AuditEntry::query()->orderBy('id')->get();

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([(string) $this->bob->id])
        ->and($entries->pluck('actor_label')->unique()->all())->toBe(['Bob Attacker'])
        ->and($entries->pluck('ip_address')->unique()->all())->toBe(['10.9.8.7'])
        ->and($entries->pluck('user_agent')->unique()->all())->toBe(['Bob-Browser/1.0'])
        ->and($entries->pluck('batch_id')->unique()->all())->toBe([$run->audit_batch_id])
        ->and($entries->pluck('meta')->pluck('run')->unique()->all())->toBe([$run->id])
        ->and(Auth::user()->is($this->alice))->toBeTrue();
});

it('audits sync-driver runs with the requesting user and the run\'s batch', function () {
    config(['larsadmin.audit.enabled' => true, 'queue.default' => 'sync']);
    ScopedQueuedPost::$auditable = true;
    $posts = qsecPosts(2);
    ScopedQueuedPost::$actions = [new QueuedAction];

    $this->post('/admin/entities/scoped-posts/actions/publish', ['resources' => qsecIds($posts)])->assertRedirect();

    $run = ActionRun::sole();

    expect($run->status)->toBe('completed')
        ->and(AuditEntry::query()->pluck('batch_id')->unique()->all())->toBe([$run->audit_batch_id])
        ->and(AuditEntry::query()->pluck('actor_id')->unique()->all())->toBe([(string) $this->bob->id]);
});

// --- Notifications off: 1.3 behaviour ---------------------------------------------------------------

it('runs a $queued action synchronously while disabled, answering exactly like an unqueued one', function () {
    config(['larsadmin.notifications.enabled' => false]);
    Bus::fake();
    $posts = qsecPosts(4);
    $plain = new QueuedAction('plain');
    $plain->queued = false;
    ScopedQueuedPost::$actions = [new QueuedAction('queued'), $plain];

    DB::enableQueryLog();
    $queued = $this->from('/admin/entities/scoped-posts')->post('/admin/entities/scoped-posts/actions/queued', ['resources' => qsecIds(array_slice($posts, 0, 2))]);
    $log = collect(DB::getQueryLog())->pluck('query');
    $unqueued = $this->from('/admin/entities/scoped-posts')->post('/admin/entities/scoped-posts/actions/plain', ['resources' => qsecIds(array_slice($posts, 2))]);

    expect($queued->status())->toBe($unqueued->status())
        ->and($queued->headers->get('Location'))->toBe($unqueued->headers->get('Location'))
        ->and($queued->getSession()->get('larsadmin.toast'))->toBe($unqueued->getSession()->get('larsadmin.toast'))
        ->and($queued->getSession()->get('larsadmin.run'))->toBeNull()
        ->and($log->filter(fn (string $sql) => str_contains($sql, 'larsadmin_action_runs') || str_contains($sql, 'notifications'))->all())->toBe([])
        ->and(Post::where('status', 'live')->count())->toBe(4)
        ->and(ActionRun::count())->toBe(0);

    Bus::assertNothingDispatched();
});
