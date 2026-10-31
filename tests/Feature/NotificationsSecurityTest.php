<?php

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\Inbox;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Maxcxam\Larsadmin\Realtime\Realtime;
use Maxcxam\Larsadmin\Support\PanelUrl;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Realtime\FakePusherBroadcaster;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Security\InboxAdmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;

/*
 * T1 (1.4): the bell, the run endpoints, the download tokens, the realtime channel and the prune command
 * seen from an attacker, end to end through HTTP (design 1.4 §7, §9, §10, §11; ADR-026, ADR-027, ADR-029;
 * NFR-025..NFR-028). Alice is the victim, Bob the signed-in attacker. Fail closed everywhere.
 * The queued worker side lives in QueuedActionSecurityTest.
 */

beforeEach(function () {
    config([
        'larsadmin.notifications.enabled' => true,
        'larsadmin.locale' => 'en',
        'larsadmin.timezone' => 'UTC',
        'app.debug' => false,
        'auth.providers.users.model' => NotifiableUser::class,
    ]);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([PostEntity::class]);
    Storage::fake('local');

    $this->alice = NotifiableUser::create(['name' => 'Alice Victim', 'email' => 'alice@tenant.test', 'password' => 'secret']);
    $this->bob = NotifiableUser::create(['name' => 'Bob Attacker', 'email' => 'bob@tenant.test', 'password' => 'secret']);
    $this->actingAs($this->bob);
});

/**
 * Insert a notification row directly (stored data as given, bypassing the builder's checks).
 */
function nsecRow(object $user, array $data = ['title' => 'Stored'], string $type = 'larsadmin', ?string $createdAt = null, ?string $morph = null): string
{
    $id = (string) Str::uuid();
    $at = $createdAt ?? now()->format('Y-m-d H:i:s');

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => $type,
        'notifiable_type' => $morph ?? $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode($data),
        'read_at' => null,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    return $id;
}

function nsecRun(object $user, array $attributes = [], ?string $morph = null): ActionRun
{
    return ActionRun::query()->create([
        'entity' => 'posts',
        'action' => 'export',
        'action_name' => 'Export',
        'user_type' => $morph ?? $user->getMorphClass(),
        'user_id' => (string) $user->getKey(),
        'guard' => 'web',
        'locale' => 'en',
        'status' => 'queued',
        'total' => 10,
        'payload' => ['selection' => [1, 2], 'fields' => ['secret' => 'hunter2-payload'], 'filters' => [], 'search' => ''],
        ...$attributes,
    ]);
}

/**
 * A completed run whose file() result sits on the fake "local" disk.
 */
function nsecFileRun(object $user, string $path = 'private/secret-dir/report.csv', ?string $morph = null): ActionRun
{
    Storage::disk('local')->put($path, 'TOP-SECRET-CONTENT');

    return nsecRun($user, [
        'status' => 'completed',
        'level' => 'success',
        'message' => 'Action completed.',
        'payload' => null,
        'finished_at' => now(),
        'result' => ['type' => 'file', 'disk' => 'local', 'path' => $path, 'filename' => 'report.csv'],
    ], $morph);
}

/**
 * A download token as DownloadController::url() builds it, from arbitrary data and an optional other key.
 */
function nsecToken(array|string $data, ?Encrypter $encrypter = null): string
{
    $plain = is_string($data) ? $data : (string) json_encode($data);
    $cipher = $encrypter !== null ? $encrypter->encryptString($plain) : Crypt::encryptString($plain);

    return rtrim(strtr(base64_encode($cipher), '+/', '-_'), '=');
}

function nsecTokenData(object $user, array $overrides = []): array
{
    return [
        'disk' => 'local',
        'path' => 'private/secret-dir/report.csv',
        'filename' => 'report.csv',
        'uid' => $user->getMorphClass().'|'.$user->getKey(),
        'exp' => now()->addMinutes(10)->getTimestamp(),
        ...$overrides,
    ];
}

/**
 * Realtime on through the SDK-less Pusher fake (the signature is computed locally, no server needed).
 */
function nsecRealtime(array $config = []): void
{
    FakePusherBroadcaster::reset();
    Realtime::flushState();

    app()->forgetInstance(BroadcastManager::class);
    Broadcast::clearResolvedInstances();
    app()->resolving(BroadcastManager::class, fn (BroadcastManager $manager) => $manager->extend('pusher', fn ($app, array $config) => new FakePusherBroadcaster($config)));

    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher' => [
            'driver' => 'pusher',
            'key' => 'app-key',
            'secret' => 'app-secret',
            'app_id' => '12345',
            'options' => ['cluster' => 'eu', 'useTLS' => true],
        ],
        ...$config,
    ]);
}

function nsecAuth(mixed $channel, mixed $socket = '1234.5678'): TestResponse
{
    return test()->postJson('/admin/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => $socket]);
}

/**
 * Bob's own records behind every new route, and the URI of a route with its placeholders filled in.
 */
function nsecTargets(object $test): array
{
    return [
        '{notification}' => nsecRow($test->bob, ['title' => 'Bob own']),
        '{run}' => nsecRun($test->bob)->id,
        '{token}' => nsecToken(nsecTokenData($test->bob)),
    ];
}

function nsecSend(string $method, string $uri, array $targets, bool $json = true): TestResponse
{
    $uri = strtr($uri, $targets);
    $body = str_contains($uri, 'broadcasting') ? ['channel_name' => 'private-larsa_inbox.x', 'socket_id' => '1.2'] : [];

    return $json ? test()->json($method, $uri, $body) : test()->call($method, $uri, $body);
}

dataset('nsec feature routes', [
    'notifications list' => ['GET', '/admin/api/notifications'],
    'notifications status' => ['GET', '/admin/api/notifications/status'],
    'read all' => ['POST', '/admin/api/notifications/read'],
    'read one' => ['POST', '/admin/api/notifications/{notification}/read'],
    'delete one' => ['DELETE', '/admin/api/notifications/{notification}'],
    'runs list' => ['GET', '/admin/api/runs'],
    'one run' => ['GET', '/admin/api/runs/{run}'],
    'cancel run' => ['POST', '/admin/api/runs/{run}/cancel'],
    'run download' => ['GET', '/admin/runs/{run}/download'],
    'broadcasting auth' => ['POST', '/admin/broadcasting/auth'],
]);

dataset('nsec all new routes', [
    'notifications list' => ['GET', '/admin/api/notifications'],
    'notifications status' => ['GET', '/admin/api/notifications/status'],
    'read all' => ['POST', '/admin/api/notifications/read'],
    'read one' => ['POST', '/admin/api/notifications/{notification}/read'],
    'delete one' => ['DELETE', '/admin/api/notifications/{notification}'],
    'runs list' => ['GET', '/admin/api/runs'],
    'one run' => ['GET', '/admin/api/runs/{run}'],
    'cancel run' => ['POST', '/admin/api/runs/{run}/cancel'],
    'run download' => ['GET', '/admin/runs/{run}/download'],
    'broadcasting auth' => ['POST', '/admin/broadcasting/auth'],
    'token download' => ['GET', '/admin/downloads/{token}'],
]);

// --- IDOR (NFR-025): notifications ------------------------------------------------------------------

it('IDOR: answers 404 to reading or deleting a notification of another user, and changes nothing', function (string $method, string $suffix) {
    $victim = nsecRow($this->alice, ['title' => 'Alice private']);

    $this->json($method, "/admin/api/notifications/{$victim}{$suffix}")->assertNotFound();

    $row = DatabaseNotification::find($victim);
    expect($row)->not->toBeNull()->and($row->read_at)->toBeNull();
})->with([
    'mark as read' => ['POST', '/read'],
    'delete' => ['DELETE', ''],
]);

it('IDOR: never lists, counts or marks the notifications of another user', function () {
    nsecRow($this->alice, ['title' => 'Alice private']);
    nsecRow($this->alice, ['title' => 'Alice private 2']);
    nsecRow($this->bob, ['title' => 'Bob own']);

    $this->getJson('/admin/api/notifications')->assertOk()->assertJsonPath('unread', 1)->assertJsonCount(1, 'items')->assertJsonPath('items.0.title', 'Bob own');
    $this->getJson('/admin/api/notifications/status')->assertOk()->assertJsonPath('unread', 1);
    $this->postJson('/admin/api/notifications/read')->assertOk();

    expect(DatabaseNotification::query()->whereNull('read_at')->count())->toBe(2);
});

it('IDOR: answers a malformed notification id with a plain 404', function (string $id) {
    nsecRow($this->bob);

    $this->postJson('/admin/api/notifications/'.rawurlencode($id).'/read')->assertNotFound();
    $this->deleteJson('/admin/api/notifications/'.rawurlencode($id))->assertNotFound();

    expect(DatabaseNotification::query()->whereNull('read_at')->count())->toBe(1);
})->with([
    'not a uuid' => ['abc'],
    'SQL' => ["1' OR '1'='1"],
    'wildcard' => ['%'],
    'uuid with a suffix' => [Str::uuid().'x'],
    'uuid with a null byte' => [Str::uuid()."\0"],
    'overlong' => [str_repeat('a', 300)],
]);

// --- IDOR: runs and their downloads -----------------------------------------------------------------

it('IDOR: answers 404 to every run route for a run of another user, and changes nothing', function (string $method, string $suffix) {
    $run = nsecRun($this->alice, ['status' => 'running']);
    $file = nsecFileRun($this->alice);
    $id = $suffix === '/download' ? $file->id : $run->id;
    $prefix = $suffix === '/download' ? '/admin/runs/' : '/admin/api/runs/';

    $response = $this->json($method, $prefix.$id.$suffix)->assertNotFound();

    expect($response->getContent())->not->toContain('TOP-SECRET-CONTENT')
        ->and($run->refresh()->cancel_requested_at)->toBeNull()
        ->and($run->status)->toBe('running');
})->with([
    'show' => ['GET', ''],
    'cancel' => ['POST', '/cancel'],
    'download' => ['GET', '/download'],
]);

it('IDOR: never lists or counts the runs of another user', function () {
    nsecRun($this->alice);
    nsecRun($this->alice, ['status' => 'running']);
    $own = nsecRun($this->bob);

    $this->getJson('/admin/api/runs')->assertOk()->assertJsonCount(1, 'runs')->assertJsonPath('runs.0.id', $own->id);
    $this->getJson('/admin/api/notifications/status')->assertOk()->assertJsonPath('activeRuns', 1);
});

it('IDOR: answers a malformed run id with a plain 404', function (string $id) {
    $this->getJson('/admin/api/runs/'.rawurlencode($id))->assertNotFound();
    $this->postJson('/admin/api/runs/'.rawurlencode($id).'/cancel')->assertNotFound();
    $this->get('/admin/runs/'.rawurlencode($id).'/download')->assertNotFound();
})->with([
    'not a ulid' => ['abc'],
    'uuid' => [fn () => (string) Str::uuid()],
    'SQL' => ["01JC' OR '1'='1"],
    'ulid with a suffix' => [fn () => strtolower((string) Str::ulid()).'0'],
    'unknown ulid' => [fn () => (string) Str::ulid()],
]);

it('IDOR: keeps users of another guard with the same id apart (another morph type)', function () {
    config([
        'auth.guards.admin' => ['driver' => 'session', 'provider' => 'admins'],
        'auth.providers.admins' => ['driver' => 'eloquent', 'model' => InboxAdmin::class],
        'larsadmin.guard' => 'admin',
    ]);
    // The same "users" row as Alice, seen as another model through another guard.
    $admin = InboxAdmin::query()->findOrFail($this->alice->id);
    expect($admin->getKey())->toBe($this->alice->getKey())->and($admin->getMorphClass())->not->toBe($this->alice->getMorphClass());

    $notification = nsecRow($this->alice, ['title' => 'Alice private']);
    $run = nsecRun($this->alice, ['status' => 'running']);
    $file = nsecFileRun($this->alice);
    $token = nsecToken(nsecTokenData($this->alice));
    $this->app['auth']->forgetGuards();
    $this->actingAs($admin, 'admin');

    $this->getJson('/admin/api/notifications')->assertOk()->assertJsonCount(0, 'items')->assertJsonPath('unread', 0);
    $this->getJson('/admin/api/runs')->assertOk()->assertJsonCount(0, 'runs');
    $this->postJson("/admin/api/notifications/{$notification}/read")->assertNotFound();
    $this->deleteJson("/admin/api/notifications/{$notification}")->assertNotFound();
    $this->getJson("/admin/api/runs/{$run->id}")->assertNotFound();
    $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertNotFound();
    $this->get("/admin/runs/{$file->id}/download")->assertNotFound();
    $this->get("/admin/downloads/{$token}")->assertNotFound();

    expect(Inbox::channelToken($admin))->not->toBe(Inbox::channelToken($this->alice))
        ->and(DatabaseNotification::find($notification)->read_at)->toBeNull()
        ->and($run->refresh()->cancel_requested_at)->toBeNull();

    // Positive control: the admin's own rows are found.
    $own = nsecRow($admin, ['title' => 'Admin own']);
    $this->postJson("/admin/api/notifications/{$own}/read")->assertOk();
});

// --- Download tokens (ADR-029) ----------------------------------------------------------------------

it('serves a well-formed token of the signed-in user (positive control)', function () {
    Storage::disk('local')->put('private/secret-dir/report.csv', 'TOP-SECRET-CONTENT');

    $this->get('/admin/downloads/'.nsecToken(nsecTokenData($this->bob)))->assertOk();
});

it('rejects expired, tampered, foreign or malformed download tokens with 404', function (Closure $token) {
    Storage::disk('local')->put('private/secret-dir/report.csv', 'TOP-SECRET-CONTENT');
    Storage::disk('local')->put('keep.txt', 'TOP-SECRET-CONTENT');

    $response = $this->get('/admin/downloads/'.$token($this))->assertNotFound();

    expect($response->getContent())->not->toContain('TOP-SECRET-CONTENT');
})->with([
    'expired' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['exp' => now()->subSecond()->getTimestamp()]))],
    'bound to another user' => [fn ($t) => nsecToken(nsecTokenData($t->alice))],
    'bound to the same id of another model' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['uid' => InboxAdmin::class.'|'.$t->bob->id]))],
    'bound to a bare id' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['uid' => (string) $t->bob->id]))],
    'uid as a number' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['uid' => $t->bob->id]))],
    'exp as a string' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['exp' => (string) now()->addHour()->getTimestamp()]))],
    'without exp' => [fn ($t) => nsecToken(array_diff_key(nsecTokenData($t->bob), ['exp' => true]))],
    'without filename' => [fn ($t) => nsecToken(array_diff_key(nsecTokenData($t->bob), ['filename' => true]))],
    'absolute path' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['path' => '/etc/passwd']))],
    'dot-dot path' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['path' => 'private/../keep.txt']))],
    'backslash path' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['path' => 'private\\..\\keep.txt']))],
    'drive path' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['path' => 'C:/keep.txt']))],
    'unknown disk' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['disk' => 'nope']))],
    'disk config key' => [fn ($t) => nsecToken(nsecTokenData($t->bob, ['disk' => 'local.root']))],
    'another app key' => [fn ($t) => nsecToken(nsecTokenData($t->bob), new Encrypter(str_repeat('b', 32), 'aes-256-cbc'))],
    'plain json' => [fn ($t) => rtrim(strtr(base64_encode((string) json_encode(nsecTokenData($t->bob))), '+/', '-_'), '=')],
    'encrypted non-json' => [fn ($t) => nsecToken('not json')],
    'encrypted json scalar' => [fn ($t) => nsecToken('"local"')],
    'tampered ciphertext' => [function ($t) {
        $token = nsecToken(nsecTokenData($t->bob));
        $middle = intdiv(strlen($token), 2);

        return substr($token, 0, $middle).($token[$middle] === 'A' ? 'B' : 'A').substr($token, $middle + 1);
    }],
    'truncated' => [fn ($t) => substr(nsecToken(nsecTokenData($t->bob)), 0, -8)],
    'padding only' => [fn () => '===='],
]);

// --- Notifications off (the default) ----------------------------------------------------------------

it('answers 404 on every new route while disabled, without touching the notification or run tables', function (string $method, string $uri) {
    nsecRealtime();
    $targets = nsecTargets($this);
    config(['larsadmin.notifications.enabled' => false]);
    DB::enableQueryLog();

    nsecSend($method, $uri, $targets)->assertNotFound();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'notifications') || str_contains($sql, 'larsadmin_action_runs'))->all())->toBe([]);
})->with('nsec feature routes');

it('is off by default', function () {
    expect(require __DIR__.'/../../config/larsadmin.php')->toHaveKey('notifications.enabled', false)
        ->and(Larsadmin::notificationsEnabled())->toBeTrue();

    config(['larsadmin.notifications.enabled' => null]);
    expect(Larsadmin::notificationsEnabled())->toBeFalse();

    config(['larsadmin.notifications.enabled' => 'false']);
    expect(Larsadmin::notificationsEnabled())->toBeFalse();
});

// --- Panel gate (NFR-026) ---------------------------------------------------------------------------

it('rejects users without panel access on every new route, and changes nothing', function (string $method, string $uri) {
    nsecRealtime();
    $targets = nsecTargets($this);
    Storage::disk('local')->put('private/secret-dir/report.csv', 'TOP-SECRET-CONTENT');
    Larsadmin::auth(fn () => false);

    $response = nsecSend($method, $uri, $targets)->assertForbidden();

    expect($response->getContent())->not->toContain('TOP-SECRET-CONTENT')->not->toContain('app-key')
        ->and(DatabaseNotification::find($targets['{notification}']))->not->toBeNull()
        ->and(DatabaseNotification::find($targets['{notification}'])->read_at)->toBeNull()
        ->and(ActionRun::find($targets['{run}'])->only(['status', 'cancel_requested_at']))->toBe(['status' => 'queued', 'cancel_requested_at' => null]);
})->with('nsec all new routes');

it('answers guests 401 on every new route (JSON)', function (string $method, string $uri) {
    nsecRealtime();
    $targets = nsecTargets($this);
    $this->app['auth']->forgetGuards();

    nsecSend($method, $uri, $targets)->assertUnauthorized();

    expect(DatabaseNotification::find($targets['{notification}'])->read_at)->toBeNull()
        ->and(ActionRun::find($targets['{run}'])->status)->toBe('queued');
})->with('nsec all new routes');

it('redirects guests to the panel login on the download pages', function (string $uri) {
    $targets = nsecTargets($this);
    $this->app['auth']->forgetGuards();

    $this->get(strtr($uri, $targets))->assertRedirect('/admin/login');
})->with([
    'run download' => ['/admin/runs/{run}/download'],
    'token download' => ['/admin/downloads/{token}'],
]);

// --- Realtime channel authorization (ADR-027) -------------------------------------------------------

it('authorizes only the owner\'s inbox channel (positive control)', function () {
    nsecRealtime();
    $channel = 'private-larsa_inbox.'.Inbox::channelToken($this->bob);

    nsecAuth($channel)->assertOk()->assertExactJson(['auth' => 'app-key:'.hash_hmac('sha256', '1234.5678:'.$channel, 'app-secret')]);
});

it('refuses every other channel on the panel endpoint', function (Closure $channel) {
    nsecRealtime();

    $response = nsecAuth($channel($this))->assertForbidden();

    expect($response->getContent())->not->toContain('app-key:');
})->with([
    'the victim\'s inbox' => [fn ($t) => 'private-larsa_inbox.'.Inbox::channelToken($t->alice)],
    'the same id of another model' => [fn ($t) => 'private-larsa_inbox.'.Inbox::channelToken(InboxAdmin::query()->find($t->bob->id))],
    'an unkeyed hash of the user' => [fn ($t) => 'private-larsa_inbox.'.substr(hash('sha256', $t->bob->getMorphClass().'|'.$t->bob->id), 0, 40)],
    'the user id as token' => [fn ($t) => 'private-larsa_inbox.'.$t->bob->id],
    'own token with a suffix' => [fn ($t) => 'private-larsa_inbox.'.Inbox::channelToken($t->bob).'0'],
    'own token truncated' => [fn ($t) => 'private-larsa_inbox.'.substr(Inbox::channelToken($t->bob), 0, 39)],
    'own token upper-cased' => [fn ($t) => 'private-larsa_inbox.'.strtoupper(Inbox::channelToken($t->bob))],
    'own token, extra segment' => [fn ($t) => 'private-larsa_inbox.'.Inbox::channelToken($t->bob).'.x'],
    'own token, presence' => [fn ($t) => 'presence-larsa_inbox.'.Inbox::channelToken($t->bob)],
    'own token, public' => [fn ($t) => 'larsa_inbox.'.Inbox::channelToken($t->bob)],
    'own token, encrypted' => [fn ($t) => 'private-encrypted-larsa_inbox.'.Inbox::channelToken($t->bob)],
    'own token, upper-case prefix' => [fn ($t) => 'PRIVATE-larsa_inbox.'.Inbox::channelToken($t->bob)],
    'another larsa_ name' => [fn ($t) => 'private-larsa_admin.'.Inbox::channelToken($t->bob)],
    'the bare prefix' => [fn () => 'private-larsa_'],
    'a host user channel' => [fn ($t) => 'private-App.Models.User.'.$t->bob->id],
    'a host presence channel' => [fn () => 'presence-chat.1'],
    'a public channel' => [fn () => 'news'],
    'overlong' => [fn ($t) => 'private-larsa_inbox.'.str_repeat('a', 200)],
    'an array' => [fn ($t) => ['private-larsa_inbox.'.Inbox::channelToken($t->bob)]],
    'null' => [fn () => null],
]);

it('refuses a malformed socket id', function (mixed $socket) {
    nsecRealtime();

    nsecAuth('private-larsa_inbox.'.Inbox::channelToken($this->bob), $socket)->assertForbidden();
})->with([
    'missing' => [null],
    'empty' => [''],
    'no dot' => ['12345678'],
    'letters' => ['abc.def'],
    'three parts' => ['1.2.3'],
    'colon injection' => ['1.2:private-App.Models.User.1'],
    'JSON injection' => ['1.2"}'],
    'overlong' => [str_repeat('1', 21).'.1'],
    'an array' => [['1.2']],
]);

it('never wraps the answer in a JSONP callback', function (string $where) {
    nsecRealtime();
    $channel = 'private-larsa_inbox.'.Inbox::channelToken($this->bob);
    $uri = $where === 'query' ? '/admin/broadcasting/auth?callback=evilFn' : '/admin/broadcasting/auth';
    $body = ['channel_name' => $channel, 'socket_id' => '1.2', ...($where === 'body' ? ['callback' => 'evilFn'] : [])];

    $response = $this->postJson($uri, $body)->assertOk();

    expect($response->getContent())->toStartWith('{')->not->toContain('evilFn')
        ->and($response->headers->get('Content-Type'))->toStartWith('application/json');
})->with(['query' => ['query'], 'body' => ['body']]);

it('does not let the host\'s /broadcasting/auth authorize larsa_ channels, even for their owner', function () {
    nsecRealtime();
    Broadcast::routes();
    Route::getRoutes()->refreshNameLookups();
    Realtime::registerChannel();

    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-larsa_inbox.'.Inbox::channelToken($this->bob), 'socket_id' => '1.2'])->assertForbidden();

    config(['larsadmin.notifications.channel_prefix' => 'acme_']);
    Realtime::registerChannel();

    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-acme_inbox.'.Inbox::channelToken($this->bob), 'socket_id' => '1.2'])->assertForbidden();
});

it('names the channel with a keyed token that reveals neither the id nor the model', function () {
    nsecRealtime();
    $token = Inbox::channelToken($this->bob);
    $channel = Realtime::channelFor($this->bob);

    expect($token)->toMatch('/\A[0-9a-f]{40}\z/')
        ->and($channel)->toBe('larsa_inbox.'.$token)
        ->and($channel)->not->toContain('Notifiable')->not->toContain('User')->not->toContain('|')
        ->and($token)->not->toBe(substr(hash('sha256', $this->bob->getMorphClass().'|'.$this->bob->id), 0, 40))
        ->and($token)->not->toBe(Inbox::channelToken($this->alice))
        ->and($token)->not->toBe(Inbox::channelToken(InboxAdmin::query()->find($this->bob->id)));

    // Another application key, another token: it cannot be guessed from the id.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);
    expect(Inbox::channelToken($this->bob))->not->toBe($token);
});

it('validates the channel prefix', function (mixed $prefix) {
    config(['larsadmin.notifications.channel_prefix' => $prefix]);

    expect(fn () => Realtime::prefix())->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'no trailing underscore' => ['larsa'],
    'upper case' => ['Larsa_'],
    'underscore only' => ['_'],
    'leading digit' => ['1larsa_'],
    'dot' => ['app.'],
    'host class prefix' => ['App.Models.'],
    'private-' => ['private-'],
    'dash' => ['lars-a_'],
    'too long' => [str_repeat('a', 32).'_'],
    'wildcard' => ['*_'],
    'array' => [['larsa_']],
    'null' => [null],
]);

it('accepts well-formed channel prefixes and authorizes only under the configured one', function () {
    nsecRealtime(['larsadmin.notifications.channel_prefix' => 'acme_app_']);

    expect(Realtime::prefix())->toBe('acme_app_');

    nsecAuth('private-acme_app_inbox.'.Inbox::channelToken($this->bob))->assertOk();
    nsecAuth('private-larsa_inbox.'.Inbox::channelToken($this->bob))->assertForbidden();
});

it('answers 500 without details when the configured prefix is invalid', function () {
    nsecRealtime(['larsadmin.notifications.channel_prefix' => 'App.']);

    $response = nsecAuth('private-App.inbox.'.Inbox::channelToken($this->bob))->assertServerError();

    expect($response->json())->toBe(['message' => 'Server Error']);
});

// --- Notification payloads (NFR-027) ----------------------------------------------------------------

it('rejects unsafe notification URLs when sending and neutralizes them when stored directly', function (string $url) {
    expect(PanelUrl::isSafe($url, PanelUrl::applicationHosts()))->toBeFalse()
        ->and(fn () => LarsadminNotification::make('Title')->url($url, 'Open'))->toThrow(InvalidArgumentException::class);

    nsecRow($this->bob, ['title' => 'Stored', 'url' => $url, 'urlLabel' => 'Open']);

    // Stored text is trimmed when read: a URL that is only unsafe for its surrounding whitespace becomes the
    // plain path; anything else loses its link.
    $item = $this->getJson('/admin/api/notifications')->assertOk()->json('items.0');

    expect($item['url'])->toBeIn([null, trim($url)]);

    if ($item['url'] === null) {
        expect($item['urlLabel'])->toBeNull();
    } else {
        expect(PanelUrl::isSafe($item['url'], PanelUrl::applicationHosts()))->toBeTrue()->and($item['url'])->toBe('/admin');
    }
})->with([
    'javascript' => ['javascript:alert(1)'],
    'javascript, upper case' => ['JaVaScRiPt:alert(1)'],
    'javascript, leading space' => [' javascript:alert(1)'],
    'javascript, tab inside' => ["java\tscript:alert(1)"],
    'javascript, newline inside' => ["javascript:\nalert(1)"],
    'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
    'vbscript' => ['vbscript:msgbox(1)'],
    'protocol relative' => ['//evil.example/admin'],
    'slash backslash' => ['/\\evil.example'],
    'double backslash' => ['\\\\evil.example'],
    'slash tab slash' => ["/\t/evil.example"],
    'triple slash' => ['///evil.example'],
    'leading space path' => [' /admin'],
    'leading nbsp path' => ["\u{00A0}/admin"],
    'carriage return' => ["/admin\r\nSet-Cookie: x=1"],
    'null byte' => ["/admin\0"],
    'other origin' => ['https://evil.example/admin'],
    'credentials to another host' => ['https://localhost@evil.example/admin'],
    'credentials on the host' => ['https://user:pass@localhost/admin'],
    'subdomain lookalike' => ['https://localhost.evil.example/admin'],
    'suffix lookalike' => ['https://evillocalhost/admin'],
    'fragment trick' => ['https://evil.example#@localhost'],
    'backslash authority trick' => ['https://localhost\\@evil.example'],
    'scheme without slashes' => ['https:evil.example'],
    'ftp' => ['ftp://localhost/file'],
    'empty' => [''],
]);

it('keeps safe notification URLs (positive control)', function (string $url) {
    expect(PanelUrl::isSafe($url, PanelUrl::applicationHosts()))->toBeTrue();

    $this->bob->notify(LarsadminNotification::make('Title')->url($url, 'Open'));

    $this->getJson('/admin/api/notifications')->assertOk()->assertJsonPath('items.0.url', $url);
})->with([
    'panel path' => ['/admin/entities/posts?filters[status]=draft#top'],
    'percent-encoded backslash' => ['/admin/%5C%2F%2Fevil.example'],
    'absolute on the host' => ['http://localhost/admin'],
    'host in upper case' => ['https://LOCALHOST/admin'],
]);

it('returns titles and bodies as plain data, never rendered or decoded on the server', function (string $text) {
    $this->bob->notify(LarsadminNotification::make($text)->body($text));
    nsecRow($this->bob, ['title' => $text, 'body' => $text]);

    $response = $this->getJson('/admin/api/notifications')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/json')
        ->and($response->json('items.*.title'))->toBe([$text, $text])
        ->and($response->json('items.*.body'))->toBe([$text, $text]);
})->with([
    'script tag' => ['<script>alert(1)</script>'],
    'event handler' => ['<img src=x onerror=alert(1)>'],
    'attribute breakout' => ['"><svg/onload=alert(1)>'],
    'template injection' => ['{{ constructor.constructor("alert(1)")() }}'],
    'already escaped' => ['&lt;b&gt;bold&lt;/b&gt;'],
    'markdown link' => ['[click](javascript:alert(1))'],
]);

it('returns only the documented keys, with the level and icon from allow-lists', function () {
    nsecRow($this->bob, [
        'v' => 99,
        'title' => 'Hostile',
        'level' => '<b>danger</b>',
        'icon' => 'javascript:alert(1)',
        'html' => '<script>alert(1)</script>',
        'user_id' => $this->alice->id,
        'download' => true,
        'url' => 'https://evil.example',
    ]);

    $item = $this->getJson('/admin/api/notifications')->assertOk()->json('items.0');

    expect(array_keys($item))->toBe(['id', 'title', 'body', 'level', 'icon', 'url', 'urlLabel', 'download', 'read', 'createdAt', 'run'])
        ->and($item)->toMatchArray(['level' => 'info', 'icon' => null, 'url' => null, 'download' => false]);
});

it('survives stored data of the wrong shape', function (string $raw) {
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(), 'type' => 'larsadmin', 'notifiable_type' => $this->bob->getMorphClass(), 'notifiable_id' => $this->bob->id,
        'data' => $raw, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->getJson('/admin/api/notifications')->assertOk()->assertJsonPath('items.0.title', '')->assertJsonPath('items.0.url', null);
})->with([
    'not json' => ['garbage'],
    'json scalar' => ['"title"'],
    'json list' => ['["a","b"]'],
    'title as an array' => ['{"title":["x"],"url":["/admin"]}'],
    'title as a number' => ['{"title":12}'],
]);

it('never links a notification to a run of another user', function () {
    $foreign = nsecFileRun($this->alice);
    nsecRow($this->bob, ['title' => 'Phished', 'run' => $foreign->id, 'urlLabel' => 'Download']);
    nsecRow($this->bob, ['title' => 'Unknown', 'run' => (string) Str::ulid(), 'urlLabel' => 'Download']);

    $items = $this->getJson('/admin/api/notifications')->assertOk()->json('items');

    foreach ($items as $item) {
        expect($item)->toMatchArray(['url' => null, 'urlLabel' => null, 'download' => false]);
    }
});

it('never sends the payload, the disk, the path or the owner of a run', function () {
    $run = nsecFileRun($this->bob);
    $queued = nsecRun($this->bob, ['audit_batch_id' => (string) Str::uuid()]);

    $show = $this->getJson("/admin/api/runs/{$run->id}")->assertOk();
    $list = $this->getJson('/admin/api/runs')->assertOk();

    expect(array_keys($show->json()))->toBe(['id', 'action', 'entity', 'status', 'total', 'processed', 'skipped', 'level', 'message', 'url', 'urlLabel', 'download', 'cancellable', 'cancelRequested', 'createdAt', 'startedAt', 'finishedAt'])
        ->and($show->json('url'))->toBe("/admin/runs/{$run->id}/download");

    foreach ([$show, $list] as $response) {
        expect($response->getContent())
            ->not->toContain('secret-dir')->not->toContain('hunter2-payload')->not->toContain('"local"')
            ->not->toContain('payload')->not->toContain('user_id')->not->toContain('audit_batch_id')
            ->not->toContain((string) $queued->audit_batch_id);
    }
});

// --- Limits and pagination (NFR-028) ----------------------------------------------------------------

it('ignores a per_page query parameter and clamps the configured page size', function (mixed $configured, string $query, int $expected) {
    config(['larsadmin.notifications.per_page' => $configured]);
    foreach (range(1, 60) as $i) {
        nsecRow($this->bob, ['title' => "N{$i}"], createdAt: now()->subSeconds($i)->format('Y-m-d H:i:s'));
    }

    $this->getJson('/admin/api/notifications'.$query)->assertOk()->assertJsonCount($expected, 'items');
})->with([
    'query asks for 1000' => [15, '?per_page=1000', 15],
    'query asks for -1' => [15, '?per_page=-1', 15],
    'configured 1000' => [1000, '', 50],
    'configured 0' => [0, '', 5],
    'configured garbage' => ['many', '', 5],
]);

it('rejects hostile cursors with 422', function (Closure $query) {
    $this->getJson('/admin/api/notifications?'.$query())->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with([
    'array parameter' => [fn () => 'cursor[]=x'],
    'extra column' => [fn () => 'cursor='.urlencode((new Cursor(['created_at' => '2026-01-01 00:00:00', 'id' => 'x', 'notifiable_id' => 1]))->encode())],
    'missing id' => [fn () => 'cursor='.urlencode((new Cursor(['created_at' => '2026-01-01 00:00:00']))->encode())],
    'swapped order' => [fn () => 'cursor='.urlencode((new Cursor(['id' => 'x', 'created_at' => '2026-01-01 00:00:00']))->encode())],
    'boolean value' => [fn () => 'cursor='.urlencode((new Cursor(['created_at' => true, 'id' => 'x']))->encode())],
    'float value' => [fn () => 'cursor='.urlencode((new Cursor(['created_at' => 1.5, 'id' => 'x']))->encode())],
    'null value' => [fn () => 'cursor='.urlencode((new Cursor(['created_at' => null, 'id' => 'x']))->encode())],
    'not base64' => [fn () => 'cursor=%00%01'],
]);

it('treats cursor values as bound literals and never pages into another user\'s rows', function (Closure $cursor) {
    $victim = nsecRow($this->alice, ['title' => 'Alice private'], createdAt: now()->addHour()->format('Y-m-d H:i:s'));
    nsecRow($this->bob, ['title' => 'Bob own']);

    $titles = $this->getJson('/admin/api/notifications?cursor='.urlencode($cursor($victim)))->assertOk()->json('items.*.title');

    expect($titles)->not->toContain('Alice private');
})->with([
    'pointing at the victim\'s row' => [fn (string $victim) => (new Cursor(['created_at' => now()->addHour()->format('Y-m-d H:i:s'), 'id' => $victim]))->encode()],
]);

it('rejects cursor values that are not a date-time and a UUID with 422 (CRN-09)', function (array $parameters) {
    $this->getJson('/admin/api/notifications?cursor='.urlencode((new Cursor($parameters))->encode()))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
})->with([
    'SQL in the values' => [['created_at' => "2999-01-01' OR '1'='1", 'id' => "x') OR 1=1 --"]],
    'garbage date' => [['created_at' => 'x', 'id' => '9b2c0e4a-1111-4222-8333-444455556666']],
    'trailing newline date' => [['created_at' => "2026-11-02 14:05:11\n", 'id' => '9b2c0e4a-1111-4222-8333-444455556666']],
    'garbage id' => [['created_at' => '2026-11-02 14:05:11', 'id' => 'y']],
    'integer id' => [['created_at' => '2026-11-02 14:05:11', 'id' => 5]],
]);

it('throttles every bell, run and channel endpoint per user with 429', function (string $method, string $uri) {
    nsecRealtime();
    config(['larsadmin.notifications.throttle' => 1, 'larsadmin.files.throttle' => 1]);
    $targets = nsecTargets($this);

    expect(nsecSend($method, $uri, $targets)->status())->not->toBe(429);
    nsecSend($method, $uri, $targets)->assertTooManyRequests();
})->with('nsec all new routes');

it('throttles per user, with one budget for all bell endpoints', function () {
    config(['larsadmin.notifications.throttle' => 1]);

    $this->getJson('/admin/api/notifications/status')->assertOk();
    $this->getJson('/admin/api/runs')->assertTooManyRequests();

    $this->actingAs($this->alice);
    $this->getJson('/admin/api/runs')->assertOk();
});

// --- Cancellation -----------------------------------------------------------------------------------

it('cancels idempotently: a second cancel of a queued run is 409 and notifies once', function () {
    $run = nsecRun($this->bob);

    $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertStatus(409);

    expect($run->refresh()->status)->toBe('cancelled')
        ->and($run->payload)->toBeNull()
        ->and(Inbox::for($this->bob)->count())->toBe(1);
});

it('keeps a running run running until its chunk ends, however often it is cancelled', function () {
    $run = nsecRun($this->bob, ['status' => 'running', 'started_at' => now()]);

    foreach (range(1, 3) as $ignored) {
        $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertOk()
            ->assertJsonPath('status', 'running')
            ->assertJsonPath('cancelRequested', true)
            ->assertJsonPath('cancellable', false);
    }

    expect($run->refresh()->status)->toBe('running')->and(Inbox::for($this->bob)->count())->toBe(0);
});

it('refuses to cancel a finished run with 409, changing nothing', function (string $status) {
    $run = nsecRun($this->bob, ['status' => $status, 'payload' => null, 'message' => 'kept', 'finished_at' => now()]);

    $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertStatus(409);

    expect($run->refresh()->only(['status', 'message', 'cancel_requested_at']))->toBe(['status' => $status, 'message' => 'kept', 'cancel_requested_at' => null])
        ->and(Inbox::for($this->bob)->count())->toBe(0);
})->with(['completed' => ['completed'], 'failed' => ['failed'], 'cancelled' => ['cancelled']]);

// --- Responses --------------------------------------------------------------------------------------

it('marks every JSON answer no-store', function (string $method, string $uri) {
    $targets = nsecTargets($this);

    nsecSend($method, $uri, $targets)->assertSuccessful()->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'notifications list' => ['GET', '/admin/api/notifications'],
    'notifications status' => ['GET', '/admin/api/notifications/status'],
    'read all' => ['POST', '/admin/api/notifications/read'],
    'read one' => ['POST', '/admin/api/notifications/{notification}/read'],
    'delete one' => ['DELETE', '/admin/api/notifications/{notification}'],
    'runs list' => ['GET', '/admin/api/runs'],
    'one run' => ['GET', '/admin/api/runs/{run}'],
    'cancel run' => ['POST', '/admin/api/runs/{run}/cancel'],
]);

it('marks the channel authorization no-store', function () {
    nsecRealtime();

    nsecAuth('private-larsa_inbox.'.Inbox::channelToken($this->bob))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('serves run files as attachments that cannot be sniffed', function () {
    $run = nsecFileRun($this->bob);

    $response = $this->get("/admin/runs/{$run->id}/download")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('never sends exception messages, class names or SQL in error JSON', function (Closure $request, int $status) {
    $response = $request($this)->assertStatus($status);

    expect($response->getContent())
        ->not->toContain('No query results')->not->toContain('Maxcxam')->not->toContain('Illuminate')
        ->not->toContain('Exception')->not->toContain('SQLSTATE')->not->toContain('no such table')
        ->not->toContain('larsadmin_action_runs')->not->toContain('make:notifications-table');
})->with([
    'conflict on cancel' => [fn ($t) => $t->postJson('/admin/api/runs/'.nsecRun($t->bob, ['status' => 'completed'])->id.'/cancel'), 409],
    'missing runs table' => [function ($t) {
        Schema::drop('larsadmin_action_runs');

        return $t->getJson('/admin/api/runs');
    }, 500],
    'missing notifications table' => [function ($t) {
        Schema::drop('notifications');

        return $t->getJson('/admin/api/notifications');
    }, 500],
]);

it('never sends the model class of a 404 for a foreign notification or run', function (Closure $request) {
    $response = $request($this)->assertNotFound();

    expect($response->getContent())->not->toContain('No query results')->not->toContain('Maxcxam')->not->toContain('Illuminate');
})->with([
    'read a foreign notification' => [fn ($t) => $t->postJson('/admin/api/notifications/'.nsecRow($t->alice).'/read')],
    'delete a foreign notification' => [fn ($t) => $t->deleteJson('/admin/api/notifications/'.nsecRow($t->alice))],
    'show a foreign run' => [fn ($t) => $t->getJson('/admin/api/runs/'.nsecRun($t->alice)->id)],
    'cancel a foreign run' => [fn ($t) => $t->postJson('/admin/api/runs/'.nsecRun($t->alice)->id.'/cancel')],
    'download a foreign run' => [fn ($t) => $t->getJson('/admin/runs/'.nsecFileRun($t->alice)->id.'/download')],
]);  // SECN-04 / CRN-03

it('anchors the URL and channel prefix rules at the very end of the string', function () {
    // PCRE "$" also matches before a final newline: "/admin\n" and "larsa_\n" pass the rules.
    expect(PanelUrl::isSafe("/admin\n", PanelUrl::applicationHosts()))->toBeFalse()
        ->and(fn () => LarsadminNotification::make('Title')->url("/admin\n"))->toThrow(InvalidArgumentException::class);

    config(['larsadmin.notifications.channel_prefix' => "larsa_\n"]);
    expect(fn () => Realtime::prefix())->toThrow(InvalidArgumentException::class);
});  // SECN-05 / CRN-04

// --- Prune (ADR-029) --------------------------------------------------------------------------------

it('prunes only panel data older than the retention window, and only the files it handed out', function () {
    Carbon::setTestNow('2026-11-30 12:00:00');
    $old = now()->subDays(30)->subSecond()->format('Y-m-d H:i:s');
    $edge = now()->subDays(30)->format('Y-m-d H:i:s');
    $young = now()->subDays(29)->format('Y-m-d H:i:s');

    $oldPanel = nsecRow($this->bob, createdAt: $old);
    $edgePanel = nsecRow($this->bob, createdAt: $edge);
    $youngPanel = nsecRow($this->bob, createdAt: $young);
    $hostOld = nsecRow($this->bob, type: 'App\\Notifications\\InvoicePaid', createdAt: now()->subYears(2)->format('Y-m-d H:i:s'));

    // The panel owns (and prunes) only its per-run copies under larsadmin/runs/{run}/ (SECN-03).
    $oldRun = nsecFileRun($this->bob, 'exports/old.csv');
    $oldOwned = ActionRun::filePrefix($oldRun->id).'old.csv';
    Storage::disk('local')->put($oldOwned, 'copy');
    $oldRun->forceFill(['created_at' => $old, 'result' => [...$oldRun->result, 'path' => $oldOwned]])->save();
    $youngRun = nsecFileRun($this->bob, 'exports/young.csv');
    $youngOwned = ActionRun::filePrefix($youngRun->id).'young.csv';
    Storage::disk('local')->put($youngOwned, 'copy');
    $youngRun->forceFill(['created_at' => $young, 'result' => [...$youngRun->result, 'path' => $youngOwned]])->save();
    $hostRun = nsecFileRun($this->bob, 'invoices/inv-1.pdf');
    $hostRun->forceFill(['created_at' => $old])->save();
    Storage::disk('local')->put('keep.txt', 'host file');
    $traversal = nsecRun($this->bob, ['status' => 'completed', 'result' => ['type' => 'file', 'disk' => 'local', 'path' => 'exports/../keep.txt', 'filename' => 'x']]);
    $traversal->forceFill(['created_at' => $old])->save();
    $absolute = nsecRun($this->bob, ['status' => 'completed', 'result' => ['type' => 'file', 'disk' => 'local', 'path' => '/keep.txt', 'filename' => 'x']]);
    $absolute->forceFill(['created_at' => $old])->save();

    $this->artisan('larsadmin:prune-notifications')->assertSuccessful();

    expect(DatabaseNotification::query()->pluck('id')->sort()->values()->all())->toBe(collect([$edgePanel, $youngPanel, $hostOld])->sort()->values()->all())
        ->and(DatabaseNotification::find($oldPanel))->toBeNull()
        ->and(ActionRun::query()->pluck('id')->all())->toBe([$youngRun->id])
        ->and(Storage::disk('local')->exists($oldOwned))->toBeFalse()
        ->and(Storage::disk('local')->exists($youngOwned))->toBeTrue()
        // Files the host handed out (not the panel's copies) are never deleted.
        ->and(Storage::disk('local')->exists('exports/old.csv'))->toBeTrue()
        ->and(Storage::disk('local')->exists('invoices/inv-1.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->exists('keep.txt'))->toBeTrue();
});

it('rejects a malformed --days and deletes nothing', function (string $days) {
    $row = nsecRow($this->bob, createdAt: now()->subYears(2)->format('Y-m-d H:i:s'));

    $this->artisan('larsadmin:prune-notifications', ['--days' => $days])->assertFailed();

    expect(DatabaseNotification::find($row))->not->toBeNull();
})->with([
    'empty' => [''],
    'decimal' => ['1.5'],
    'exponent' => ['1e3'],
    'leading space' => [' 7'],
    'plus sign' => ['+7'],
    'hex' => ['0x1A'],
    'unicode digits' => ['٧'],
    'SQL' => ['7; DROP TABLE notifications'],
]);
