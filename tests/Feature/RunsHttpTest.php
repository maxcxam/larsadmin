<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Http\Controllers\DownloadController;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\Post as PostEntity;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;

beforeEach(function () {
    config([
        'larsadmin.notifications.enabled' => true,
        'larsadmin.locale' => 'en',
        'larsadmin.timezone' => 'UTC',
        'auth.providers.users.model' => NotifiableUser::class,
    ]);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([PostEntity::class]);
    Storage::fake('local');

    $this->user = NotifiableUser::create(['name' => 'Olena', 'email' => 'olena@example.com', 'password' => 'secret']);
    $this->other = NotifiableUser::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'secret']);
    $this->actingAs($this->user);
});

function rhRun(object $user, array $attributes = []): ActionRun
{
    return ActionRun::query()->create([
        'entity' => 'posts',
        'action' => 'export',
        'action_name' => 'Export',
        'user_type' => $user->getMorphClass(),
        'user_id' => (string) $user->getKey(),
        'guard' => 'web',
        'locale' => 'en',
        'status' => 'running',
        'total' => 10,
        'processed' => 4,
        'skipped' => 1,
        'payload' => ['selection' => 'all', 'fields' => ['secret' => 'x']],
        ...$attributes,
    ]);
}

it('lists the active and recently finished runs of the user, newest first', function () {
    $this->travelTo(now()->subDays(2));
    $old = rhRun($this->user, ['status' => 'completed', 'finished_at' => now()]);
    $stale = rhRun($this->user, ['status' => 'queued']);
    $this->travelBack();

    $recent = rhRun($this->user, ['status' => 'failed', 'finished_at' => now()->subHour()]);
    $this->travel(1)->second();
    $active = rhRun($this->user);
    rhRun($this->other);

    $runs = $this->getJson('/admin/api/runs')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('runs');

    expect(array_column($runs, 'id'))->toBe([$active->id, $recent->id, $stale->id])
        ->and($runs[0])->toMatchArray([
            'action' => 'Export',
            'entity' => ['uriKey' => 'posts', 'label' => 'Posts'],
            'status' => 'running',
            'total' => 10,
            'processed' => 4,
            'skipped' => 1,
            'level' => null,
            'message' => null,
            'url' => null,
            'urlLabel' => null,
            'download' => false,
            'cancellable' => true,
            'cancelRequested' => false,
            'finishedAt' => null,
        ])
        ->and($runs[0])->not->toHaveKey('payload')
        ->and(json_encode($runs))->not->toContain('secret');
});

it('lists at most ten runs', function () {
    foreach (range(1, 12) as $i) {
        rhRun($this->user);
    }

    expect($this->getJson('/admin/api/runs')->json('runs'))->toHaveCount(10);
});

it('shows one run of the user and hides the others', function () {
    $mine = rhRun($this->user);
    $theirs = rhRun($this->other);

    $this->getJson("/admin/api/runs/{$mine->id}")->assertOk()->assertJsonPath('id', $mine->id);
    $this->getJson('/admin/api/runs/'.strtoupper($mine->id))->assertOk();
    $this->getJson("/admin/api/runs/{$theirs->id}")->assertNotFound();
    $this->getJson('/admin/api/runs/01jc0000000000000000000000')->assertNotFound();
    $this->getJson('/admin/api/runs/not-a-ulid')->assertNotFound();
});

it('cancels a queued run at once and notifies', function () {
    Notification::fake();
    $run = rhRun($this->user, ['status' => 'queued', 'processed' => 0]);

    $this->postJson("/admin/api/runs/{$run->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled')
        ->assertJsonPath('cancellable', false)
        ->assertJsonPath('message', 'Processed 0 of 10 records.');

    expect($run->refresh()->payload)->toBeNull();
    Notification::assertSentTo($this->user, LarsadminNotification::class);
});

it('asks a running run to stop after its chunk', function () {
    $run = rhRun($this->user);

    $this->postJson("/admin/api/runs/{$run->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'running')
        ->assertJsonPath('cancelRequested', true)
        ->assertJsonPath('cancellable', false);

    expect($run->refresh()->cancel_requested_at)->not->toBeNull();
});

it('refuses to cancel finished runs (409) and runs of others (404)', function () {
    $done = rhRun($this->user, ['status' => 'completed']);
    $theirs = rhRun($this->other);

    $this->postJson("/admin/api/runs/{$done->id}/cancel")->assertStatus(409);
    $this->postJson("/admin/api/runs/{$theirs->id}/cancel")->assertNotFound();

    expect($theirs->refresh()->cancel_requested_at)->toBeNull();
});

it('serves the file result to its owner while it exists', function () {
    Storage::disk('local')->put('exports/posts.csv', "id\n1\n");
    $run = rhRun($this->user, ['status' => 'completed', 'result' => ['type' => 'file', 'disk' => 'local', 'path' => 'exports/posts.csv', 'filename' => 'posts.csv']]);

    $this->getJson("/admin/api/runs/{$run->id}")
        ->assertJsonPath('url', "/admin/runs/{$run->id}/download")
        ->assertJsonPath('urlLabel', 'Download posts.csv')
        ->assertJsonPath('download', true);

    $response = $this->get("/admin/runs/{$run->id}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('posts.csv')
        ->and($response->streamedContent())->toBe("id\n1\n");

    $this->actingAs($this->other)->get("/admin/runs/{$run->id}/download")->assertNotFound();

    $this->actingAs($this->user);
    Storage::disk('local')->delete('exports/posts.csv');
    $this->get("/admin/runs/{$run->id}/download")->assertNotFound();
    // Lists never touch the disk (CRN-08): the link stays, the download answers 404.
    $this->getJson("/admin/api/runs/{$run->id}")->assertJsonPath('url', "/admin/runs/{$run->id}/download");
});

it('serves no file for unfinished runs, other results or unsafe paths', function (array $attributes) {
    Storage::disk('local')->put('exports/posts.csv', 'x');
    $run = rhRun($this->user, $attributes);

    $this->get("/admin/runs/{$run->id}/download")->assertNotFound();
})->with([
    'running' => [['result' => ['type' => 'file', 'disk' => 'local', 'path' => 'exports/posts.csv', 'filename' => 'a.csv']]],
    'redirect' => [['status' => 'completed', 'result' => ['type' => 'redirect', 'url' => '/admin']]],
    'traversal' => [['status' => 'completed', 'result' => ['type' => 'file', 'disk' => 'local', 'path' => '../exports/posts.csv', 'filename' => 'a.csv']]],
    'unknown disk' => [['status' => 'completed', 'result' => ['type' => 'file', 'disk' => 'nope', 'path' => 'exports/posts.csv', 'filename' => 'a.csv']]],
]);

it('re-checks redirect and download results when read', function () {
    $safe = rhRun($this->user, ['status' => 'completed', 'result' => ['type' => 'redirect', 'url' => '/admin/entities/posts']]);
    $unsafe = rhRun($this->user, ['status' => 'completed', 'result' => ['type' => 'download', 'url' => 'https://evil.com/x', 'filename' => 'x']]);

    $this->getJson("/admin/api/runs/{$safe->id}")->assertJsonPath('url', '/admin/entities/posts')->assertJsonPath('urlLabel', 'Open')->assertJsonPath('download', false);
    $this->getJson("/admin/api/runs/{$unsafe->id}")->assertJsonPath('url', null);
});

it('serves a synchronous file() through a user-bound, expiring token', function () {
    Storage::disk('local')->put('exports/report.csv', 'data');
    $url = rhDownloadUrl($this->user, ActionResponse::file('local', 'exports/report.csv', 'report.csv'));

    $response = $this->get($url)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('report.csv');

    // Not for another user, not after the TTL, not with a forged token.
    $this->actingAs($this->other)->get($url)->assertNotFound();
    $this->actingAs($this->user);

    $this->travel(31)->minutes();
    $this->get($url)->assertNotFound();
    $this->travelBack();

    $forged = rtrim(strtr(base64_encode(Crypt::encryptString(json_encode([
        'disk' => 'local', 'path' => '../.env', 'filename' => 'x', 'uid' => $this->user->getMorphClass().'|'.$this->user->id, 'exp' => now()->addHour()->getTimestamp(),
    ]))), '+/', '-_'), '=');

    $this->get('/admin/downloads/'.$forged)->assertNotFound();
    $this->get('/admin/downloads/'.rtrim(strtr(base64_encode('not encrypted'), '+/', '-_'), '='))->assertNotFound();
    $this->get('/admin/downloads/AAAA')->assertNotFound();

    Storage::disk('local')->delete('exports/report.csv');
    $this->get($url)->assertNotFound();
});

it('keeps synchronous file downloads working while notifications are disabled', function () {
    config(['larsadmin.notifications.enabled' => false]);
    Storage::disk('local')->put('exports/report.csv', 'data');

    $this->get(rhDownloadUrl($this->user, ActionResponse::file('local', 'exports/report.csv')))->assertOk();
});

it('refuses to build a download url without a file response or a user', function () {
    expect(fn () => DownloadController::url(request(), ActionResponse::message('x')))->toThrow(LogicException::class);
});

it('answers 404 on the run routes while disabled', function () {
    config(['larsadmin.notifications.enabled' => false]);
    $run = rhRun($this->user, ['status' => 'completed']);

    $this->getJson('/admin/api/runs')->assertNotFound();
    $this->getJson("/admin/api/runs/{$run->id}")->assertNotFound();
    $this->postJson("/admin/api/runs/{$run->id}/cancel")->assertNotFound();
    $this->get("/admin/runs/{$run->id}/download")->assertNotFound();
});

function rhDownloadUrl(object $user, ActionResponse $response): string
{
    $request = Request::create('/admin');
    $request->setUserResolver(fn () => $user);

    return DownloadController::url($request, $response);
}

it('names the missing runs table in the error', function () {
    Schema::drop('larsadmin_action_runs');
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/admin/api/runs'))->toThrow(RuntimeException::class, 'larsadmin-migrations');
});
