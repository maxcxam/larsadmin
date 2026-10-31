<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\QueuedDispatcher;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Flag;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Status;

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

function qdPosts(int $count, string $status = 'draft'): array
{
    return collect(range(1, $count))->map(fn (int $i) => Post::create(['title' => "Post {$i}", 'status' => $status]))->all();
}

function qdRun(array $body = [], string $key = 'publish')
{
    return test()->from('/admin/entities/queued-posts')->post("/admin/entities/queued-posts/actions/{$key}", $body);
}

it('runs queued actions synchronously, exactly as before, while notifications are disabled', function () {
    config(['larsadmin.notifications.enabled' => false]);
    Bus::fake();
    [$a, $b] = qdPosts(2);
    QueuedPost::$actions = [new QueuedAction];

    expect((new QueuedAction)->runsQueued())->toBeFalse();

    qdRun(['resources' => [$a->id, $b->id]])
        ->assertRedirect('http://localhost/admin/entities/queued-posts')
        ->assertSessionHas('larsadmin.toast', ['message' => 'Action completed.', 'type' => 'success'])
        ->assertSessionMissing('larsadmin.run');

    expect(Post::pluck('status')->unique()->all())->toBe(['live'])
        ->and(ActionRun::count())->toBe(0);

    Bus::assertNothingDispatched();
});

it('queues the run, confirms at once and flashes the run id', function () {
    Bus::fake();
    [$a, , $c] = qdPosts(3);
    QueuedPost::$actions = [new QueuedAction(fieldList: [Text::make('Note')])];

    $response = qdRun(['resources' => [$a->id, $c->id], 'note' => 'hello'])
        ->assertRedirect('http://localhost/admin/entities/queued-posts')
        ->assertSessionHas('larsadmin.toast', ['message' => '“Publish” is running in the background. See notifications for progress.', 'type' => 'success']);

    $run = ActionRun::sole();

    $response->assertSessionHas('larsadmin.run', ['id' => $run->id]);

    expect($run->only(['entity', 'action', 'action_name', 'user_type', 'user_id', 'guard', 'locale', 'status', 'total', 'processed', 'audit_batch_id']))->toBe([
        'entity' => 'queued-posts',
        'action' => 'publish',
        'action_name' => 'Publish',
        'user_type' => $this->user->getMorphClass(),
        'user_id' => (string) $this->user->id,
        'guard' => 'web',
        'locale' => 'en',
        'status' => 'queued',
        'total' => 2,
        'processed' => 0,
        'audit_batch_id' => null,
    ])
        ->and($run->payload)->toMatchArray([
            'selection' => [$a->id, $c->id],
            'filters' => [],
            'search' => '',
            'fields' => ['note' => 'hello'],
            'origin_url' => 'http://localhost/admin/entities/queued-posts/actions/publish',
        ])
        ->and($run->payload['actor'])->toMatchArray(['type' => $this->user->getMorphClass(), 'id' => (string) $this->user->id, 'label' => 'Olena'])
        ->and(Post::pluck('status')->unique()->all())->toBe(['draft'])
        ->and(QueuedAction::$calls)->toBe([]);

    Bus::assertDispatched(RunQueuedAction::class, fn (RunQueuedAction $job) => $job->runId === $run->id && $job->tries === 1 && $job->timeout === null);
});

it('stores the filters and search of an "all" selection', function () {
    Bus::fake();
    Post::create(['title' => 'Alpha', 'status' => 'draft']);
    Post::create(['title' => 'Alpha live', 'status' => 'live']);
    Post::create(['title' => 'Beta', 'status' => 'draft']);
    QueuedPost::$actions = [new QueuedAction];

    qdRun(['resources' => 'all', 'search' => 'Alpha', 'filters' => ['status' => 'draft']])->assertRedirect();

    $run = ActionRun::sole();

    expect($run->total)->toBe(1)
        ->and($run->payload)->toMatchArray(['selection' => 'all', 'filters' => ['status' => 'draft'], 'search' => 'Alpha']);
});

it('pushes the job on the action\'s queue and connection, else the configured ones, with its timeout', function () {
    Queue::fake();
    [$a] = qdPosts(1);
    QueuedPost::$actions = [
        (new QueuedAction('own'))->onQueue('reports')->onQueueConnection('database'),
        new QueuedAction('configured'),
    ];
    QueuedPost::$actions[0]->timeout = 600;
    config(['larsadmin.actions.queue' => 'larsadmin', 'larsadmin.actions.queue_connection' => 'redis']);

    qdRun(['resources' => [$a->id]], 'own')->assertRedirect();
    qdRun(['resources' => [$a->id]], 'configured')->assertRedirect();

    Queue::assertPushedOn('reports', RunQueuedAction::class, fn (RunQueuedAction $job) => $job->connection === 'database' && $job->timeout === 600);
    Queue::assertPushedOn('larsadmin', RunQueuedAction::class, fn (RunQueuedAction $job) => $job->connection === 'redis' && $job->timeout === null);
});

it('limits the active runs per user', function () {
    Bus::fake();
    config(['larsadmin.actions.queued_max_active' => 2]);
    [$a] = qdPosts(1);
    QueuedPost::$actions = [new QueuedAction];

    qdRun(['resources' => [$a->id]])->assertSessionHas('larsadmin.toast.type', 'success');
    qdRun(['resources' => [$a->id]])->assertSessionHas('larsadmin.toast.type', 'success');
    qdRun(['resources' => [$a->id]])->assertSessionHas('larsadmin.toast', [
        'message' => 'Too many background tasks. Wait for the previous ones to finish (at most 2).',
        'type' => 'error',
    ]);

    expect(ActionRun::count())->toBe(2);

    // Finished runs and runs of other users do not count.
    ActionRun::query()->first()->forceFill(['status' => 'completed'])->save();
    qdRun(['resources' => [$a->id]])->assertSessionHas('larsadmin.toast.type', 'success');
    expect(ActionRun::count())->toBe(3);
});

it('caps "all" at queued_max_all instead of max_all', function () {
    Bus::fake();
    config(['larsadmin.actions.max_all' => 1, 'larsadmin.actions.queued_max_all' => 2]);
    qdPosts(2);
    QueuedPost::$actions = [new QueuedAction];

    qdRun(['resources' => 'all'])->assertSessionHasNoErrors();
    expect(ActionRun::count())->toBe(1);

    qdPosts(1);
    qdRun(['resources' => 'all'])->assertSessionHasErrors(['resources' => 'Too many records selected: 3 matched, the limit is 2. Narrow the search or filters.']);
    expect(ActionRun::count())->toBe(1);
});

it('answers nothing matched without queueing', function () {
    Bus::fake();
    QueuedPost::$actions = [new QueuedAction];

    qdRun(['resources' => [999]])->assertSessionHas('larsadmin.toast', ['message' => 'No records matched.', 'type' => 'error']);

    expect(ActionRun::count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('queues standalone actions without a total', function () {
    Bus::fake();
    QueuedPost::$actions = [(new QueuedAction('export'))->standalone()];

    qdRun([], 'export')->assertSessionHas('larsadmin.toast.type', 'success');

    expect(ActionRun::sole())->total->toBeNull()
        ->and(ActionRun::sole()->payload['selection'])->toBe([]);
});

it('keeps the checks of a synchronous run before queueing', function () {
    Bus::fake();
    [$a] = qdPosts(1);
    QueuedPost::$actions = [(new QueuedAction('hidden'))->canSee(fn () => false), (new QueuedAction('export'))->standalone(), new QueuedAction('needs-note', fieldList: [Text::make('Note')->rules('required')])];

    qdRun(['resources' => [$a->id]], 'hidden')->assertNotFound();
    qdRun(['resources' => [$a->id]], 'needs-note')->assertSessionHasErrors('note');

    QueuedPostPolicy::$create = false;
    qdRun([], 'export')->assertForbidden();

    QueuedPostPolicy::$viewAny = false;
    qdRun(['resources' => [$a->id]])->assertForbidden();

    expect(ActionRun::count())->toBe(0);
});

it('encrypts the payload: field values never sit in the table in plain text', function () {
    Bus::fake();
    [$a] = qdPosts(1);
    QueuedPost::$actions = [new QueuedAction(fieldList: [Password::make('Secret'), Text::make('Note')])];

    qdRun(['resources' => [$a->id], 'secret' => 'hunter2-supersecret', 'note' => 'visible-note-value']);

    $raw = (string) DB::table('larsadmin_action_runs')->value('payload');

    expect($raw)->not->toContain('hunter2-supersecret')
        ->and($raw)->not->toContain('visible-note-value')
        ->and(ActionRun::sole()->payload['fields']['note'])->toBe('visible-note-value')
        ->and(ActionRun::sole()->toArray())->not->toHaveKey('payload');
});

it('stores date field values as strings', function () {
    Bus::fake();
    [$a] = qdPosts(1);
    QueuedPost::$actions = [new QueuedAction(fieldList: [Date::make('Due')])];

    qdRun(['resources' => [$a->id], 'due' => '2026-11-20']);

    expect(ActionRun::sole()->payload['fields']['due'])->toBe('2026-11-20');
});

it('normalizes values for the queue and refuses other objects', function () {
    expect(QueuedDispatcher::normalize([
        'date' => new DateTimeImmutable('2026-11-20 10:00:00', new DateTimeZone('UTC')),
        'enum' => Status::Live,
        'unit' => Flag::Live,
        'nan' => NAN,
        'inf' => INF,
        'float' => 1.5,
        'list' => [1, 'a', null, true],
    ]))->toBe([
        'date' => '2026-11-20T10:00:00+00:00',
        'enum' => 'live',
        'unit' => 'Live',
        'nan' => null,
        'inf' => null,
        'float' => 1.5,
        'list' => [1, 'a', null, true],
    ])
        ->and(fn () => QueuedDispatcher::normalize(['x' => new stdClass]))->toThrow(LogicException::class, 'stdClass');
});

it('fails the run and tells the user when the queue is unavailable', function () {
    Exceptions::fake();
    [$a] = qdPosts(1);
    QueuedPost::$actions = [(new QueuedAction)->onQueueConnection('missing-connection')];

    qdRun(['resources' => [$a->id]])->assertSessionHas('larsadmin.toast', ['message' => 'Could not queue the action.', 'type' => 'error']);

    $run = ActionRun::sole();

    expect($run->status)->toBe('failed')
        ->and($run->message)->toBe('An error occurred while running the action.')
        ->and($run->payload)->toBeNull()
        ->and($run->finished_at)->not->toBeNull();

    Exceptions::assertReported(InvalidArgumentException::class);
});

it('marks queued actions in the actions prop only while the feature is on', function () {
    QueuedPost::$actions = [new QueuedAction('queued'), (new QueuedAction('sync'))->queued(false)];

    $props = json_decode(json_encode(ActionRegistry::serialize(QueuedPost::class, request())), true);

    expect(array_column($props, 'queued', 'uriKey'))->toBe(['queued' => true, 'sync' => false]);

    config(['larsadmin.notifications.enabled' => false]);
    $props = json_decode(json_encode(ActionRegistry::serialize(QueuedPost::class, request())), true);

    expect($props[0])->not->toHaveKey('queued');
});

it('shares the queued flag on the index page', function () {
    QueuedPost::$actions = [new QueuedAction];

    $this->get('/admin/entities/queued-posts')->assertInertia(fn (Assert $page) => $page->where('actions.0.queued', true));
});

it('fails with a hint when the runs table is missing', function () {
    Schema::drop('larsadmin_action_runs');
    $this->withoutExceptionHandling();
    [$a] = qdPosts(1);
    QueuedPost::$actions = [new QueuedAction];

    expect(fn () => qdRun(['resources' => [$a->id]]))->toThrow(RuntimeException::class, 'larsadmin-migrations');
});
