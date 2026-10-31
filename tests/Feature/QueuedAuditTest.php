<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Audit\AuditEntry;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

beforeEach(function () {
    config([
        'larsadmin.notifications.enabled' => true,
        'larsadmin.audit.enabled' => true,
        'larsadmin.locale' => 'en',
        'auth.providers.users.model' => NotifiableUser::class,
    ]);
    QueuedPost::reset();
    QueuedPost::$auditable = true;
    QueuedPostPolicy::reset();
    Gate::policy(Post::class, QueuedPostPolicy::class);
    Gate::define('viewLarsadminAudit', fn () => true);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([QueuedPost::class]);

    $this->user = NotifiableUser::create(['name' => 'Olena', 'email' => 'olena@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);
});

afterEach(fn () => QueuedPost::reset());

function qaQueue(array $body, array $fields = []): ActionRun
{
    $action = new QueuedAction(fieldList: $fields);
    $action->chunkSize = 2;
    QueuedPost::$actions = [$action];

    Bus::fake([RunQueuedAction::class]);
    test()->withHeaders(['User-Agent' => 'Dispatcher/1.0'])
        ->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->from('/admin/entities/queued-posts')
        ->post('/admin/entities/queued-posts/actions/publish', $body)
        ->assertSessionHas('larsadmin.run');

    return ActionRun::query()->latest('id')->firstOrFail();
}

it('audits a queued run like a synchronous one: the dispatch actor, one batch, meta.run', function () {
    $posts = collect(range(1, 3))->map(fn ($i) => Post::create(['title' => "Post {$i}", 'status' => 'draft']));
    $run = qaQueue(['resources' => $posts->pluck('id')->all(), 'note' => 'n1', 'secret' => 'hunter2'], [Text::make('Note'), Password::make('Secret')]);

    expect($run->audit_batch_id)->not->toBeNull()->and(AuditEntry::count())->toBe(0);

    // The worker runs elsewhere: another IP, no user agent, no authenticated user.
    $this->app['auth']->forgetGuards();
    (new RunQueuedAction($run->id))->handle();

    $entries = AuditEntry::query()->orderBy('id')->get();

    expect($entries)->toHaveCount(3)
        ->and($entries->pluck('batch_id')->unique()->all())->toBe([$run->audit_batch_id])
        ->and($entries->pluck('event')->unique()->all())->toBe(['action'])
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([(string) $this->user->id])
        ->and($entries->pluck('actor_label')->unique()->all())->toBe(['Olena'])
        ->and($entries->pluck('ip_address')->unique()->all())->toBe(['10.1.2.3'])
        ->and($entries->pluck('user_agent')->unique()->all())->toBe(['Dispatcher/1.0'])
        ->and($entries[0]->meta)->toMatchArray([
            'action' => 'publish',
            'action_name' => 'Publish',
            'fields' => ['note' => 'n1', 'secret' => ['masked' => true]],
            'run' => $run->id,
        ])
        ->and($entries[0]->changes)->toHaveKey('status');
});

it('records nothing when the audit log was disabled before the run', function () {
    $post = Post::create(['title' => 'A', 'status' => 'draft']);
    $run = qaQueue(['resources' => [$post->id]]);

    config(['larsadmin.audit.enabled' => false]);
    (new RunQueuedAction($run->id))->handle();

    expect($run->refresh()->status)->toBe('completed')->and(AuditEntry::count())->toBe(0);
});

it('shows queued entries as run in the background', function () {
    $post = Post::create(['title' => 'A', 'status' => 'draft']);
    (new RunQueuedAction(qaQueue(['resources' => [$post->id]])->id))->handle();

    $this->getJson("/admin/api/entities/queued-posts/{$post->id}/audit")
        ->assertOk()
        ->assertJsonPath('entries.0.action', ['name' => 'Publish', 'fields' => [], 'queued' => true]);
});
