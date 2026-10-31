<?php

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Maxcxam\Larsadmin\Realtime\PanelSignal;
use Maxcxam\Larsadmin\Realtime\Realtime;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Realtime\FakePusherBroadcaster;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

beforeEach(function () {
    FakePusherBroadcaster::reset();
    Realtime::flushState();

    // A fresh manager whose "pusher" driver is the SDK-less fake (registered before the package's callback runs).
    $this->app->forgetInstance(BroadcastManager::class);
    Broadcast::clearResolvedInstances();
    $this->app->resolving(BroadcastManager::class, fn (BroadcastManager $manager) => $manager->extend('pusher', fn ($app, array $config) => new FakePusherBroadcaster($config)));

    config([
        'larsadmin.notifications.enabled' => true,
        'larsadmin.locale' => 'en',
        'auth.providers.users.model' => NotifiableUser::class,
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher' => [
            'driver' => 'pusher',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'app_id' => '12345',
            'options' => ['cluster' => 'eu', 'useTLS' => true, 'host' => 'api-eu.pusher.com'],
        ],
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'reverb-key',
            'secret' => 'reverb-secret',
            'app_id' => '1',
            'options' => ['host' => 'reverb.internal', 'port' => 8080, 'scheme' => 'http'],
        ],
    ]);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([PostEntity::class]);

    $this->user = NotifiableUser::create(['name' => 'Olena', 'email' => 'olena@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);
    $this->channel = 'larsa_inbox.'.Inbox::channelToken($this->user);
});

function rtAuth(string $channel, string $socket = '1234.5678')
{
    return test()->postJson('/admin/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => $socket]);
}

it('detects realtime from the settings and the default broadcaster', function (array $config, bool $enabled) {
    config($config);

    expect(Realtime::enabled())->toBe($enabled);
})->with([
    'auto + pusher' => [[], true],
    'auto + reverb' => [['broadcasting.default' => 'reverb'], true],
    'auto + log' => [['broadcasting.default' => 'log'], false],
    'auto + null' => [['broadcasting.default' => 'null'], false],
    'auto + unknown connection' => [['broadcasting.default' => 'missing'], false],
    'auto + no key' => [['broadcasting.connections.pusher.key' => ''], false],
    'null setting is auto' => [['larsadmin.notifications.realtime' => null], true],
    'false' => [['larsadmin.notifications.realtime' => false], false],
    '"false" from env' => [['larsadmin.notifications.realtime' => 'false'], false],
    '"0" from env' => [['larsadmin.notifications.realtime' => '0'], false],
    '"true" from env' => [['larsadmin.notifications.realtime' => 'true'], true],
    'garbage' => [['larsadmin.notifications.realtime' => 'maybe'], false],
    'notifications off' => [['larsadmin.notifications.enabled' => false], false],
]);

it('warns once when forced on with another broadcaster', function () {
    Log::spy();
    config(['larsadmin.notifications.realtime' => true, 'broadcasting.default' => 'log']);

    expect(Realtime::enabled())->toBeFalse()->and(Realtime::enabled())->toBeFalse();

    Log::shouldHaveReceived('warning')->once();
});

it('shares the public client config only: never the secret or the app id', function () {
    $this->get('/admin/entities/posts')->assertInertia(fn (Assert $page) => $page->where('larsadmin.notifications.realtime', [
        'broadcaster' => 'pusher',
        'key' => 'app-key',
        'cluster' => 'eu',
        'wsHost' => null,
        'wsPort' => null,
        'wssPort' => null,
        'forceTLS' => true,
        'authEndpoint' => '/admin/broadcasting/auth',
        'channel' => $this->channel,
        'basePath' => '/admin',
    ]));

    $html = $this->get('/admin/entities/posts')->getContent();

    expect($html)->not->toContain('app-secret')->not->toContain('12345')->not->toContain('api-eu.pusher.com');
});

it('configures the Reverb client from its options or the realtime_client overrides', function () {
    config(['broadcasting.default' => 'reverb']);
    $request = request();

    expect(Realtime::clientConfig($request, $this->user))->toMatchArray([
        'broadcaster' => 'reverb', 'key' => 'reverb-key', 'cluster' => null,
        // An internal server host is never disclosed (SECN-08).
        'wsHost' => null, 'wsPort' => 8080, 'wssPort' => 8080, 'forceTLS' => false,
    ]);

    foreach (['ws.example.com' => 'ws.example.com', 'localhost' => 'localhost', '127.0.0.1' => null, '10.0.0.5' => null, '8.8.8.8' => '8.8.8.8', 'reverb' => null, 'reverb.local' => null] as $host => $expected) {
        config(['broadcasting.connections.reverb.options.host' => $host]);
        expect(Realtime::clientConfig($request, $this->user)['wsHost'])->toBe($expected);
    }

    config(['larsadmin.notifications.realtime_client' => ['host' => 'ws.example.com', 'port' => '443', 'scheme' => 'https']]);

    expect(Realtime::clientConfig($request, $this->user))->toMatchArray(['wsHost' => 'ws.example.com', 'wsPort' => 443, 'forceTLS' => true]);

    config(['broadcasting.connections.reverb.options' => [], 'larsadmin.notifications.realtime_client' => ['port' => 'x']]);

    expect(Realtime::clientConfig($request, $this->user))->toMatchArray(['wsHost' => null, 'wsPort' => 443, 'forceTLS' => true]);

    config(['broadcasting.default' => 'pusher', 'larsadmin.notifications.realtime_client' => ['host' => 'soketi.local', 'port' => 6001, 'scheme' => 'http']]);

    expect(Realtime::clientConfig($request, $this->user))->toMatchArray(['wsHost' => 'soketi.local', 'wsPort' => 6001, 'forceTLS' => false]);

    config(['larsadmin.notifications.realtime' => false]);

    expect(Realtime::clientConfig($request, $this->user))->toBeNull();
});

it('authorizes the user\'s own inbox channel with a locally computed Pusher signature', function () {
    rtAuth('private-'.$this->channel)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['auth' => 'app-key:'.hash_hmac('sha256', '1234.5678:private-'.$this->channel, 'app-secret')]);
});

it('refuses other channels on the panel endpoint', function (string $channel, string $socket = '1.2') {
    rtAuth(str_replace('{other}', Inbox::channelToken(new NotifiableUser(['id' => 999])), $channel), $socket)->assertForbidden();
})->with([
    'another user\'s token' => ['private-larsa_inbox.{other}'],
    'a made-up token' => ['private-larsa_inbox.0000000000000000000000000000000000000000'],
    'without the prefix' => ['private-inbox.abc'],
    'a host channel' => ['private-App.Models.User.1'],
    'presence' => ['presence-larsa_inbox.abc'],
    'public' => ['larsa_inbox.abc'],
    'bad socket id' => ['private-larsa_inbox.abc', '1.2;evil'],
    'empty' => [''],
]);

it('never answers JSONP', function () {
    test()->postJson('/admin/broadcasting/auth?callback=evil', ['channel_name' => 'private-'.$this->channel, 'socket_id' => '1.2', 'callback' => 'evil'])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json');
});

it('requires a panel session and realtime', function () {
    Larsadmin::auth(fn () => false);
    rtAuth('private-'.$this->channel)->assertForbidden();

    Larsadmin::auth(fn () => true);
    config(['larsadmin.notifications.realtime' => false]);
    rtAuth('private-'.$this->channel)->assertNotFound();

    $this->app['auth']->forgetGuards();
    rtAuth('private-'.$this->channel)->assertUnauthorized();
});

it('does not let the host\'s /broadcasting/auth authorize larsa_ channels', function () {
    Broadcast::routes();
    Route::getRoutes()->refreshNameLookups();
    Realtime::registerChannel();

    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-'.$this->channel, 'socket_id' => '1.2'])->assertForbidden();
});

it('registers the inbox channel when the broadcaster is resolved with realtime on, and not otherwise', function () {
    $channels = fn () => array_keys(app(BroadcastManager::class)->driver()->getChannels()->all());

    expect($channels())->toBe(['larsa_inbox.{token}']);

    $this->app->forgetInstance(BroadcastManager::class);
    Broadcast::clearResolvedInstances();
    config(['larsadmin.notifications.realtime' => false]);

    expect($channels())->toBe([]);
});

it('uses a configured channel prefix and rejects an invalid one', function () {
    config(['larsadmin.notifications.channel_prefix' => 'acme_']);
    $channel = 'acme_inbox.'.Inbox::channelToken($this->user);

    expect(Realtime::channelFor($this->user))->toBe($channel);
    rtAuth('private-'.$channel)->assertOk();
    rtAuth('private-'.$this->channel)->assertForbidden();

    foreach (['', 'larsa', 'Larsa_', '_x_', 'a-b_', str_repeat('a', 40).'_', 5] as $invalid) {
        config(['larsadmin.notifications.channel_prefix' => $invalid]);
        expect(fn () => Realtime::prefix())->toThrow(InvalidArgumentException::class);
    }
});

it('signals new panel notifications with their id only', function () {
    $this->user->notify(LarsadminNotification::make('Secret title')->body('Secret body')->url('/admin/x'));

    $id = DB::table('notifications')->value('id');

    expect(FakePusherBroadcaster::$sent)->toHaveCount(1)
        ->and(FakePusherBroadcaster::$sent[0]['channels'])->toBe(['private-'.$this->channel])
        ->and(FakePusherBroadcaster::$sent[0]['event'])->toBe('larsadmin.signal')
        ->and(Arr::except(FakePusherBroadcaster::$sent[0]['payload'], 'socket'))->toBe(['kind' => 'notification', 'id' => $id])
        ->and(json_encode(FakePusherBroadcaster::$sent))->not->toContain('Secret');
});

it('signals nothing for other notifications or channels, or without realtime', function () {
    $this->user->notify(new class extends BaseNotification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        public function toArray(object $notifiable): array
        {
            return ['title' => 'host'];
        }
    });

    config(['larsadmin.notifications.realtime' => false]);
    $this->user->notify(LarsadminNotification::make('x'));

    expect(FakePusherBroadcaster::$sent)->toBe([])->and(DB::table('notifications')->count())->toBe(2);
});

it('keeps the notification when the broadcaster fails', function () {
    Exceptions::fake();
    FakePusherBroadcaster::$fail = true;

    $this->user->notify(LarsadminNotification::make('x'));

    expect(DB::table('notifications')->count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('builds the signal events with the user channel and ids only', function () {
    $run = new ActionRun(['user_type' => 'App\\Models\\User', 'user_id' => '5', 'status' => 'running', 'processed' => 4, 'total' => 10]);
    $run->id = '01jc0000000000000000000000';

    $signal = PanelSignal::run($run);

    expect($signal->broadcastOn()->name)->toBe('private-larsa_inbox.'.Inbox::tokenFor('App\\Models\\User', '5'))
        ->and($signal->broadcastAs())->toBe('larsadmin.signal')
        ->and($signal->broadcastWith())->toBe(['kind' => 'run', 'id' => '01jc0000000000000000000000', 'status' => 'running', 'processed' => 4, 'total' => 10])
        ->and(PanelSignal::notification('App\\Models\\User', '5', 'abc')->broadcastWith())->toBe(['kind' => 'notification', 'id' => 'abc']);
});

it('signals run progress and the final status from the worker', function () {
    Event::fake([PanelSignal::class]);
    QueuedPost::reset();
    QueuedPostPolicy::reset();
    Gate::policy(Post::class, QueuedPostPolicy::class);
    Larsadmin::entities([QueuedPost::class]);
    $posts = collect(range(1, 3))->map(fn ($i) => Post::create(['title' => "P{$i}", 'status' => 'draft']));
    $action = new QueuedAction;
    $action->chunkSize = 1;
    QueuedPost::$actions = [$action];

    Bus::fake([RunQueuedAction::class]);
    $this->post('/admin/entities/queued-posts/actions/publish', ['resources' => $posts->pluck('id')->all()]);
    $run = ActionRun::sole();
    (new RunQueuedAction($run->id))->handle();

    $runSignals = collect(Event::dispatched(PanelSignal::class))->map(fn (array $args) => $args[0])->where('kind', 'run');

    // At most one progress signal per second, plus the final one.
    expect($runSignals->count())->toBe(2)
        ->and($runSignals->last()->broadcastWith())->toBe(['kind' => 'run', 'id' => $run->id, 'status' => 'completed', 'processed' => 3, 'total' => 3])
        ->and($runSignals->first()->broadcastOn()->name)->toBe('private-'.$this->channel);
});

it('does not signal while realtime is off', function () {
    Event::fake([PanelSignal::class]);
    config(['larsadmin.notifications.realtime' => false]);

    Realtime::signal(PanelSignal::notification('x', '1', 'a'));

    Event::assertNotDispatched(PanelSignal::class);
});

it('ignores other channels and non-model notifiables, and reports its own failures', function () {
    Exceptions::fake();

    event(new NotificationSent($this->user, LarsadminNotification::make('x'), 'mail'));
    event(new NotificationSent(new AnonymousNotifiable, LarsadminNotification::make('x'), 'database'));

    expect(FakePusherBroadcaster::$sent)->toBe([]);

    config(['larsadmin.notifications.channel_prefix' => 'INVALID']);
    $this->user->notify(LarsadminNotification::make('x'));

    expect(DB::table('notifications')->count())->toBe(1);
    Exceptions::assertReported(InvalidArgumentException::class);
});
