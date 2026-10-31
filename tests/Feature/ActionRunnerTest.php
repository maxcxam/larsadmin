<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\ActionRunner;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    config(['larsadmin.locale' => 'en']);
    QueuedPost::reset();
    QueuedPostPolicy::reset();
    Gate::policy(Post::class, QueuedPostPolicy::class);
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([QueuedPost::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
    $this->runner = new ActionRunner;
});

function arPosts(array $titles): array
{
    return array_map(fn (string $title) => Post::create(['title' => $title, 'status' => 'draft']), $titles);
}

function arAction(?Closure $callback = null, int $chunkSize = 2): QueuedAction
{
    $action = (new QueuedAction(callback: $callback))->bindTo(QueuedPost::class);
    $action->chunkSize = $chunkSize;

    return $action;
}

it('runs the chunks, reports the progress after each and keeps the most severe response', function () {
    arPosts(['a', 'locked b', 'c', 'd', 'e']);
    $progress = [];
    $action = arAction(fn ($fields, $models, int $chunk) => match ($chunk) {
        1 => ActionResponse::message('one'),
        2 => ActionResponse::file('local', 'x.csv'),
        default => ActionResponse::message('three'),
    });

    $builder = ActionRunner::builder(request(), QueuedPost::class, 'all');
    $result = $this->runner->run(request(), QueuedPost::class, $action, $builder, new ActionFields, null,
        progress: function (int $ran, int $skipped) use (&$progress) {
            $progress[] = [$ran, $skipped];
        });

    expect($result->total)->toBe(5)
        ->and($result->ran)->toBe(4)
        ->and($result->skipped)->toBe(1)
        ->and($result->error)->toBeNull()
        ->and($result->cancelled)->toBeFalse()
        ->and($result->response->type)->toBe(ActionResponse::FILE)
        ->and($progress)->toBe([[1, 1], [3, 1], [4, 1]]);
});

it('stops between chunks when asked to', function () {
    arPosts(['a', 'b', 'c', 'd', 'e']);
    $asked = 0;

    $result = $this->runner->run(request(), QueuedPost::class, arAction(), ActionRunner::builder(request(), QueuedPost::class, 'all'), new ActionFields, null,
        shouldStop: function () use (&$asked) {
            return ++$asked >= 1;
        });

    expect($result->cancelled)->toBeTrue()
        ->and($result->ran)->toBe(2)
        ->and($asked)->toBe(1)
        ->and(Post::where('status', 'live')->count())->toBe(2);
});

it('returns the exception with what was committed before it', function () {
    arPosts(['a', 'b', 'c']);

    $result = $this->runner->run(request(), QueuedPost::class, arAction(function ($fields, $models, int $chunk) {
        $models->each->update(['status' => 'live']);

        if ($chunk === 2) {
            throw new RuntimeException('boom');
        }

        return null;
    }), ActionRunner::builder(request(), QueuedPost::class, 'all'), new ActionFields, null);

    expect($result->error)->toBeInstanceOf(RuntimeException::class)
        ->and($result->ran)->toBe(2)
        ->and(Post::where('status', 'live')->count())->toBe(2);
});

it('runs standalone actions in one transaction and returns their exception', function () {
    $action = (new QueuedAction('export', fn () => ActionResponse::message('done')))->standalone()->bindTo(QueuedPost::class);

    $result = $this->runner->run(request(), QueuedPost::class, $action, null, new ActionFields, null);

    expect($result->response->message)->toBe('done')->and($result->total)->toBeNull()->and($result->ran)->toBe(0);

    $failing = (new QueuedAction('export', fn () => throw new LogicException('nope')))->standalone()->bindTo(QueuedPost::class);

    expect($this->runner->run(request(), QueuedPost::class, $failing, null, new ActionFields, null)->error)->toBeInstanceOf(LogicException::class);
});

it('builds the id selection within the detail scope, ignoring malformed integer ids', function () {
    [$a, $b] = arPosts(['a', 'b']);

    $ids = ActionRunner::builder(request(), QueuedPost::class, [(string) $a->id, 'x1', '99999999999999999999', $b->id])->pluck('id')->all();

    expect($ids)->toBe([$a->id, $b->id]);
});

it('ranks file responses like downloads', function () {
    $file = ActionResponse::file('local', 'x.csv');
    $download = ActionResponse::download('/admin/x', 'x');

    expect(ActionRunner::mostSevere($download, $file))->toBe($download)
        ->and(ActionRunner::mostSevere(ActionResponse::message('m'), $file))->toBe($file)
        ->and(ActionRunner::mostSevere($file, ActionResponse::danger('d'))->type)->toBe(ActionResponse::DANGER)
        ->and(ActionRunner::mostSevere(null, null))->toBeNull();
});

it('answers a synchronous file() with a user-bound download url', function () {
    [$a] = arPosts(['a']);
    QueuedPost::$actions = [(new QueuedAction(callback: fn () => ActionResponse::file('local', 'exports/x.csv', 'report.csv')))->queued(false)];

    $response = $this->from('/admin/entities/queued-posts')->post('/admin/entities/queued-posts/actions/publish', ['resources' => [$a->id]])
        ->assertRedirect('http://localhost/admin/entities/queued-posts')
        ->assertSessionHas('larsadmin.download.filename', 'report.csv')
        ->assertSessionMissing('larsadmin.toast');

    expect(session('larsadmin.download.url'))->toStartWith('/admin/downloads/');
});

it('keeps the query count of a synchronous run unchanged by the queued feature', function () {
    [$a, $b] = arPosts(['a', 'b']);
    QueuedPost::$actions = [(new QueuedAction)->queued(false)];

    $count = function () use ($a, $b) {
        Post::query()->update(['status' => 'draft']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->from('/admin/entities/queued-posts')->post('/admin/entities/queued-posts/actions/publish', ['resources' => [$a->id, $b->id]])->assertRedirect();

        return collect(DB::getQueryLog())->pluck('query')->all();
    };

    $disabled = $count();
    config(['larsadmin.notifications.enabled' => true]);
    $enabled = $count();

    expect($enabled)->toBe($disabled)
        ->and(collect($disabled)->filter(fn (string $sql) => str_contains($sql, 'notifications') || str_contains($sql, 'action_runs')))->toBeEmpty();
});

it('lets the exception handler deal with a failing synchronous standalone action, as before', function () {
    QueuedPost::$actions = [(new QueuedAction('export', fn () => throw new LogicException('standalone failed')))->standalone()->queued(false)];
    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/admin/entities/queued-posts/actions/export'))->toThrow(LogicException::class, 'standalone failed');
});
