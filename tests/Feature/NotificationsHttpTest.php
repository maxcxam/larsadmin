<?php

use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\PlainUser;

beforeEach(function () {
    config([
        'larsadmin.notifications.enabled' => true,
        'larsadmin.locale' => 'en',
        'larsadmin.timezone' => 'Europe/Kyiv',
        'auth.providers.users.model' => NotifiableUser::class,
    ]);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([PostEntity::class]);

    $this->user = NotifiableUser::create(['name' => 'Olena', 'email' => 'olena@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);
});

/**
 * Insert a notification row directly (the stored data as given).
 */
function nhRow(object $user, array $data, string $type = 'larsadmin', ?string $createdAt = null, ?string $readAt = null): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => $type,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode($data),
        'read_at' => $readAt,
        'created_at' => $createdAt ?? now()->format('Y-m-d H:i:s'),
        'updated_at' => $createdAt ?? now()->format('Y-m-d H:i:s'),
    ]);

    return $id;
}

it('lists the user\'s panel notifications newest first with the unread count', function () {
    $this->user->notify(LarsadminNotification::make('Older')->body('Body')->success()->icon('check')->url('/admin/entities/posts', 'Open'));
    $this->travel(1)->minutes();
    $this->user->notify(LarsadminNotification::make('Newer')->danger());

    $response = $this->getJson('/admin/api/notifications')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->json('unread'))->toBe(2)
        ->and($response->json('timezone'))->toBe('Europe/Kyiv')
        ->and($response->json('nextCursor'))->toBeNull()
        ->and(array_column($response->json('items'), 'title'))->toBe(['Newer', 'Older'])
        ->and($response->json('items.1'))->toMatchArray([
            'title' => 'Older', 'body' => 'Body', 'level' => 'success', 'icon' => 'check',
            'url' => '/admin/entities/posts', 'urlLabel' => 'Open', 'download' => false, 'read' => false, 'run' => null,
        ])
        ->and($response->json('items.0.createdAt'))->toEndWith('+02:00');
});

it('paginates with a cursor', function () {
    config(['larsadmin.notifications.per_page' => 5]);

    foreach (range(1, 7) as $i) {
        nhRow($this->user, ['title' => "N{$i}"], createdAt: now()->subMinutes(10 - $i)->format('Y-m-d H:i:s'));
    }

    $first = $this->getJson('/admin/api/notifications')->assertOk();
    $second = $this->getJson('/admin/api/notifications?cursor='.$first->json('nextCursor'))->assertOk();

    expect(array_column($first->json('items'), 'title'))->toBe(['N7', 'N6', 'N5', 'N4', 'N3'])
        ->and(array_column($second->json('items'), 'title'))->toBe(['N2', 'N1'])
        ->and($second->json('nextCursor'))->toBeNull();
});

it('rejects malformed cursors with 422', function (string $cursor) {
    $this->getJson('/admin/api/notifications?cursor='.urlencode($cursor))->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with([
    'garbage' => ['%%%'],
    'other columns' => [fn () => base64_encode(json_encode(['email' => 'x', '_pointsToNextItems' => true]))],
    'nested value' => [fn () => base64_encode(json_encode(['created_at' => ['x'], 'id' => 'a', '_pointsToNextItems' => true]))],
    'too long' => [fn () => str_repeat('a', 1001)],
]);

it('shows only panel notifications of the user', function () {
    $other = NotifiableUser::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x']);
    nhRow($other, ['title' => 'Not mine']);
    nhRow($this->user, ['title' => 'Host notification'], 'App\\Notifications\\InvoicePaid');
    nhRow($this->user, ['title' => 'Mine']);

    $response = $this->getJson('/admin/api/notifications')->assertOk();

    expect(array_column($response->json('items'), 'title'))->toBe(['Mine'])->and($response->json('unread'))->toBe(1);
});

it('re-checks stored data when read', function () {
    nhRow($this->user, [
        'title' => str_repeat('t', 300),
        'body' => '<script>alert(1)</script>',
        'level' => 'critical',
        'icon' => 'skull',
        'url' => 'https://evil.com/phish',
        'urlLabel' => 'Click',
    ]);
    nhRow($this->user, ['title' => '   ', 'url' => '//evil.com', 'body' => 5]);

    $items = $this->getJson('/admin/api/notifications')->assertOk()->json('items');
    $items = collect($items)->sortBy(fn ($item) => $item['title'])->values();

    expect($items[1])->toMatchArray([
        'body' => '<script>alert(1)</script>', 'level' => 'info', 'icon' => null, 'url' => null, 'urlLabel' => null,
    ])
        ->and(mb_strlen($items[1]['title']))->toBe(200)
        ->and($items[0])->toMatchArray(['title' => '', 'body' => null, 'url' => null]);
});

it('reports the status: unread, latest and active runs', function () {
    $this->getJson('/admin/api/notifications/status')
        ->assertOk()
        ->assertExactJson(['unread' => 0, 'latest' => null, 'activeRuns' => 0]);

    nhRow($this->user, ['title' => 'A'], createdAt: '2026-11-02 12:05:11');
    nhRow($this->user, ['title' => 'B'], createdAt: '2026-11-01 12:00:00', readAt: '2026-11-01 13:00:00');
    ActionRun::query()->create([
        'entity' => 'posts', 'action' => 'x', 'action_name' => 'X', 'user_type' => $this->user->getMorphClass(),
        'user_id' => (string) $this->user->getKey(), 'locale' => 'en', 'status' => 'running',
    ]);

    $this->getJson('/admin/api/notifications/status')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['unread' => 1, 'latest' => '2026-11-02T14:05:11+02:00', 'activeRuns' => 1]);
});

it('marks one, marks all and deletes one', function () {
    $a = nhRow($this->user, ['title' => 'A']);
    $b = nhRow($this->user, ['title' => 'B']);
    $c = nhRow($this->user, ['title' => 'C']);

    $this->postJson("/admin/api/notifications/{$a}/read")->assertOk()->assertExactJson(['unread' => 2]);
    expect(DatabaseNotification::find($a)->read_at)->not->toBeNull();

    $this->deleteJson("/admin/api/notifications/{$b}")->assertOk()->assertExactJson(['unread' => 1]);
    expect(DatabaseNotification::find($b))->toBeNull();

    $this->postJson('/admin/api/notifications/read')->assertOk()->assertExactJson(['unread' => 0]);
    expect(DatabaseNotification::find($c)->read_at)->not->toBeNull();
});

it('answers 404 for notifications of others, of the host or unknown and for non-uuid ids', function () {
    $other = NotifiableUser::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x']);
    $foreign = nhRow($other, ['title' => 'Not mine']);
    $host = nhRow($this->user, ['title' => 'Host'], 'App\\Notifications\\InvoicePaid');

    foreach ([$foreign, $host, (string) Str::uuid(), 'not-a-uuid'] as $id) {
        $this->postJson("/admin/api/notifications/{$id}/read")->assertNotFound();
        $this->deleteJson("/admin/api/notifications/{$id}")->assertNotFound();
    }

    $this->postJson('/admin/api/notifications/read')->assertOk();

    expect(DatabaseNotification::find($foreign)->read_at)->toBeNull()
        ->and(DatabaseNotification::find($host)->read_at)->toBeNull();
});

it('answers 404 on every route while disabled', function () {
    config(['larsadmin.notifications.enabled' => false]);
    $id = nhRow($this->user, ['title' => 'A']);

    $this->getJson('/admin/api/notifications')->assertNotFound();
    $this->getJson('/admin/api/notifications/status')->assertNotFound();
    $this->postJson('/admin/api/notifications/read')->assertNotFound();
    $this->postJson("/admin/api/notifications/{$id}/read")->assertNotFound();
    $this->deleteJson("/admin/api/notifications/{$id}")->assertNotFound();
    $this->getJson('/admin/api/runs')->assertNotFound();
    $this->postJson('/admin/broadcasting/auth', ['channel_name' => 'private-larsa_inbox.x', 'socket_id' => '1.2'])->assertNotFound();
});

it('answers 404 to users that cannot receive notifications', function () {
    $this->actingAs(PlainUser::create(['name' => 'P', 'email' => 'p@example.com', 'password' => 'x']));

    $this->getJson('/admin/api/notifications')->assertNotFound();
    $this->getJson('/admin/api/notifications/status')->assertNotFound();
});

it('requires authentication and panel access', function () {
    $this->app['auth']->forgetGuards();
    $this->getJson('/admin/api/notifications')->assertUnauthorized();

    $this->actingAs($this->user);
    Larsadmin::auth(fn () => false);
    $this->getJson('/admin/api/notifications')->assertForbidden();
});

it('shares the bell config on pages', function () {
    nhRow($this->user, ['title' => 'A']);

    $this->get('/admin/entities/posts')->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.notifications', [
            'unread' => 1,
            'poll' => 60,
            'urls' => ['list' => '/admin/api/notifications', 'status' => '/admin/api/notifications/status', 'runs' => '/admin/api/runs'],
            'realtime' => null,
        ])
        ->where('larsadmin.run', null));
});

it('clamps the poll interval and the page size', function (mixed $poll, int $expected) {
    config(['larsadmin.notifications.poll_interval' => $poll]);

    $this->get('/admin/entities/posts')->assertInertia(fn (Assert $page) => $page->where('larsadmin.notifications.poll', $expected));
})->with([
    'off' => [0, 0],
    'negative' => [-5, 0],
    'too short' => [5, 15],
    'too long' => [99999, 3600],
    'normal' => [120, 120],
]);

it('shares no bell when disabled, without querying notifications', function () {
    config(['larsadmin.notifications.enabled' => false]);
    DB::enableQueryLog();

    $this->get('/admin/entities/posts')->assertInertia(fn (Assert $page) => $page
        ->where('larsadmin.notifications', null)
        ->where('larsadmin.run', null));

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'notifications') || str_contains($sql, 'larsadmin_action_runs')))->toBeEmpty();
});

it('shares no bell without panel access or without Notifiable', function () {
    $this->actingAs(PlainUser::create(['name' => 'P', 'email' => 'p@example.com', 'password' => 'x']));

    $this->get('/admin/entities/posts')->assertInertia(fn (Assert $page) => $page->where('larsadmin.notifications', null));
});

it('keeps pages working when the notifications table is missing', function () {
    Exceptions::fake();
    Schema::drop('notifications');

    $this->get('/admin/entities/posts')->assertOk()->assertInertia(fn (Assert $page) => $page->where('larsadmin.notifications.unread', null));

    Exceptions::assertReported(fn (QueryException $e) => true);

    $this->getJson('/admin/api/notifications/status')->assertServerError();
});

it('names the missing tables in the error', function () {
    Schema::drop('notifications');
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/admin/api/notifications'))->toThrow(RuntimeException::class, 'make:notifications-table');
});

it('shares the run flashed after queueing', function () {
    $this->withSession(['larsadmin.run' => ['id' => '01jc0000000000000000000000']])
        ->get('/admin/entities/posts')
        ->assertInertia(fn (Assert $page) => $page->where('larsadmin.run', ['id' => '01jc0000000000000000000000']));
});

it('throttles the bell endpoints per user', function () {
    config(['larsadmin.notifications.throttle' => 2]);

    $this->getJson('/admin/api/notifications/status')->assertOk();
    $this->getJson('/admin/api/notifications/status')->assertOk();
    $this->getJson('/admin/api/notifications/status')->assertTooManyRequests();
});
