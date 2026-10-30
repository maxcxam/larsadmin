<?php

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\ActionPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\ClosureAction;
use Maxcxam\Larsadmin\Tests\Fixtures\NoUpdatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Security\DenyAllPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    config(['larsadmin.locale' => 'en']);
    ActionPost::reset();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ActionPost::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

function mk(string $title, string $status = 'draft'): Post
{
    return Post::create(['title' => $title, 'status' => $status]);
}

function runAction(object $test, array $body = [], string $key = 'mark-live')
{
    return $test->from('/admin/entities/action-posts')->post("/admin/entities/action-posts/actions/{$key}", $body);
}

function statuses(): array
{
    return Post::orderBy('id')->pluck('status', 'title')->all();
}

it('runs on selected ids and redirects back with a toast', function () {
    [$a, $b, $c] = [mk('A'), mk('B'), mk('C')];
    ActionPost::$actions = [new ClosureAction];

    runAction($this, ['resources' => [$a->id, $c->id]])
        ->assertRedirect('http://localhost/admin/entities/action-posts')
        ->assertSessionHas('larsadmin.toast', ['message' => 'Action completed.', 'type' => 'success']);

    expect(statuses())->toBe(['A' => 'live', 'B' => 'draft', 'C' => 'live'])
        ->and(ActionPost::$calls)->toHaveCount(1);
});

it('runs on all records matching search and filters', function () {
    mk('Alpha one', 'draft');
    mk('Alpha two', 'live');
    mk('Alpha three', 'draft');
    mk('Beta', 'draft');
    ActionPost::$actions = [new ClosureAction];

    runAction($this, ['resources' => 'all', 'search' => 'Alpha', 'filters' => ['status' => 'draft']])->assertRedirect();

    expect(statuses())->toBe(['Alpha one' => 'live', 'Alpha two' => 'live', 'Alpha three' => 'live', 'Beta' => 'draft'])
        ->and(collect(ActionPost::$calls)->flatMap(fn ($call) => $call['ids'])->count())->toBe(2);
});

it('runs on every record for all without search', function () {
    mk('A');
    mk('B');
    ActionPost::$actions = [new ClosureAction];

    runAction($this, ['resources' => 'all'])->assertRedirect();

    expect(array_unique(statuses()))->toBe(['A' => 'live']);
});

it('runs standalone actions without records', function () {
    ActionPost::$actions = [(new ClosureAction('ping'))->standalone()];

    runAction($this, ['resources' => []], 'ping')->assertSessionHas('larsadmin.toast.type', 'success');
    runAction($this, [], 'ping')->assertRedirect();

    expect(ActionPost::$calls)->toHaveCount(2)
        ->and(ActionPost::$calls[0]['ids'])->toBe([]);
});

it('requires a valid resources selection', function (mixed $resources) {
    mk('A');
    ActionPost::$actions = [new ClosureAction];

    runAction($this, ['resources' => $resources])->assertSessionHasErrors('resources');

    expect(ActionPost::$calls)->toBe([]);
})->with([
    'missing' => [null],
    'empty' => [[]],
    'string' => ['everything'],
    'nested' => [[[1]]],
    'too many' => [fn () => range(1, 101)],
    'assoc' => [['a' => 1]],
]);

it('validates action fields and exposes the values', function () {
    $post = mk('A');
    ActionPost::$actions = [new ClosureAction(fieldList: [
        Text::make('Note')->rules('required', 'max:5'),
        Number::make('Amount')->min(1)->nullable(),
    ])];

    runAction($this, ['resources' => [$post->id], 'note' => 'too long!'])->assertSessionHasErrors('note');
    expect(ActionPost::$calls)->toBe([]);

    runAction($this, ['resources' => [$post->id], 'note' => 'ok', 'amount' => '3'])->assertSessionHasNoErrors();

    expect(ActionPost::$calls[0]['fields'])->toBe(['note' => 'ok', 'amount' => 3]);
});

it('hides actions the user cannot see', function () {
    $post = mk('A');
    ActionPost::$actions = [(new ClosureAction)->canSee(fn () => false)];

    runAction($this, ['resources' => [$post->id]])->assertNotFound();
    runAction($this, ['resources' => [$post->id]], 'unknown')->assertNotFound();

    expect(statuses())->toBe(['A' => 'draft']);
});

it('skips records canRun denies and runs the rest', function () {
    [$a, $b] = [mk('A'), mk('B', 'live')];
    $b->update(['status' => 'locked']);
    ActionPost::$actions = [(new ClosureAction(callback: fn () => ActionResponse::message('Done.')))
        ->canRun(fn ($request, $model) => $model->status !== 'locked')];

    runAction($this, ['resources' => [$a->id, $b->id]])
        ->assertSessionHas('larsadmin.toast', ['message' => 'Done. 1 record skipped (not allowed).', 'type' => 'success']);

    expect(statuses())->toBe(['A' => 'live', 'B' => 'locked']);
});

it('returns 403 and runs nothing when no record is runnable', function () {
    $post = mk('A');
    ActionPost::$actions = [(new ClosureAction)->canRun(fn () => false)];

    runAction($this, ['resources' => [$post->id]])->assertForbidden();

    expect(ActionPost::$calls)->toBe([])->and(statuses())->toBe(['A' => 'draft']);
});

it('defaults canRun to the entity update ability', function () {
    $post = mk('A');
    Gate::policy(Post::class, NoUpdatePolicy::class);
    ActionPost::$actions = [new ClosureAction];

    runAction($this, ['resources' => [$post->id]])->assertForbidden();

    expect(ActionPost::$calls)->toBe([]);
});

it('requires viewAny on the entity', function () {
    Gate::policy(Post::class, DenyAllPolicy::class);
    ActionPost::$actions = [new ClosureAction];

    runAction($this, ['resources' => [1]])->assertForbidden();
});

it('ignores ids outside the detail scope or missing', function () {
    $a = mk('A');
    ActionPost::$actions = [new ClosureAction];

    runAction($this, ['resources' => [$a->id, 9999, 'abc']])->assertRedirect();

    expect(ActionPost::$calls[0]['ids'])->toBe([$a->id]);
});

it('commits finished chunks, reports the failure and tells how many ran', function () {
    foreach (range(1, 5) as $i) {
        mk("P{$i}");
    }

    $action = new ClosureAction(callback: function () {
        static $n = 0;
        if (++$n === 2) {
            throw new RuntimeException('boom');
        }

        return null;
    });
    $action->chunkSize = 2;
    ActionPost::$actions = [$action];

    Exceptions::fake();

    runAction($this, ['resources' => 'all'])
        ->assertRedirect()
        ->assertSessionHas('larsadmin.toast', ['message' => 'Completed for 2 of 5 records, then an error occurred.', 'type' => 'error']);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');

    // chunk 1 committed, chunk 2 rolled back, chunk 3 never ran
    expect(ActionPost::$calls)->toHaveCount(2)
        ->and(collect(ActionPost::$calls)->map(fn ($c) => count($c['ids']))->all())->toBe([2, 2])
        ->and(Post::where('status', 'live')->count())->toBe(2);
});

it('lets the exception through when the first chunk fails and nothing was committed', function () {
    mk('A');
    ActionPost::$actions = [new ClosureAction(callback: fn () => throw new RuntimeException('boom'))];

    $this->withoutExceptionHandling();

    expect(fn () => runAction($this, ['resources' => 'all']))->toThrow(RuntimeException::class)
        ->and(statuses())->toBe(['A' => 'draft']);
});

it('splits large selections into chunks', function () {
    foreach (range(1, 5) as $i) {
        mk("P{$i}");
    }
    $action = new ClosureAction;
    $action->chunkSize = 2;
    ActionPost::$actions = [$action];

    runAction($this, ['resources' => 'all'])->assertRedirect();

    expect(collect(ActionPost::$calls)->map(fn ($c) => count($c['ids']))->all())->toBe([2, 2, 1]);
});

it('shows a danger response as an error toast', function () {
    $post = mk('A');
    ActionPost::$actions = [new ClosureAction(callback: fn () => ActionResponse::danger('Nope.'))];

    runAction($this, ['resources' => [$post->id]])
        ->assertSessionHas('larsadmin.toast', ['message' => 'Nope.', 'type' => 'error']);
});

it('redirects to a panel URL', function () {
    $post = mk('A');
    ActionPost::$actions = [new ClosureAction(callback: fn () => ActionResponse::redirect('/admin/entities/action-posts/1'))];

    runAction($this, ['resources' => [$post->id]])->assertRedirect('/admin/entities/action-posts/1');
});

it('flashes a download', function () {
    $post = mk('A');
    ActionPost::$actions = [new ClosureAction(callback: fn () => ActionResponse::download('/admin/export.csv', 'a/b\\report.csv'))];

    runAction($this, ['resources' => [$post->id]])
        ->assertRedirect('http://localhost/admin/entities/action-posts')
        ->assertSessionHas('larsadmin.download', ['url' => '/admin/export.csv', 'filename' => 'abreport.csv']);
});

it('rejects open redirects and foreign download urls', function (string $url) {
    expect(fn () => ActionResponse::redirect($url))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ActionResponse::download($url, 'x.csv'))->toThrow(InvalidArgumentException::class);
})->with([
    'absolute foreign' => 'https://evil.example/phish',
    'protocol relative' => '//evil.example',
    'backslash trick' => '/\\evil.example',
    'javascript' => 'javascript:alert(1)',
    'userinfo' => 'http://localhost@evil.example/',
    'whitespace' => "/ok\r\nLocation: https://evil.example",
    'empty' => '',
]);

it('accepts same-host absolute urls', function () {
    expect(ActionResponse::redirect('http://localhost/admin')->url)->toBe('http://localhost/admin');
});

it('ignores a foreign referer when going back', function () {
    $post = mk('A');
    ActionPost::$actions = [new ClosureAction];

    $this->withHeader('referer', 'https://evil.example/x')
        ->post('/admin/entities/action-posts/actions/mark-live', ['resources' => [$post->id]])
        ->assertRedirect(route('larsadmin.entities.index', 'action-posts'));
});

it('serializes actions with the binding contract', function () {
    ActionPost::$actions = [
        (new ClosureAction('plain')),
        (new ClosureAction('danger', fieldList: [Text::make('Reason')->rules('required')->default('x')]))->destructive()->showInline()->onlyOnDetail()->confirmText('Sure?'),
        (new ClosureAction('hidden'))->canSee(fn () => false),
    ];

    $props = ActionRegistry::serialize(ActionPost::class, request());
    $props = json_decode(json_encode($props), true);

    expect($props)->toHaveCount(2)
        ->and(array_keys($props[0]))->toBe([
            'uriKey', 'name', 'destructive', 'standalone', 'confirmText', 'confirmButtonText',
            'cancelButtonText', 'withoutConfirmation', 'showOnIndex', 'showOnDetail', 'showInline', 'fields',
        ])
        ->and($props[0])->toMatchArray(['uriKey' => 'plain', 'destructive' => false, 'withoutConfirmation' => true, 'showOnIndex' => true, 'fields' => []])
        ->and($props[1])->toMatchArray([
            'uriKey' => 'danger', 'destructive' => true, 'confirmText' => 'Sure?', 'confirmButtonText' => 'Confirm',
            'withoutConfirmation' => false, 'showOnIndex' => false, 'showOnDetail' => true, 'showInline' => true,
        ])
        ->and($props[1]['fields'][0])->toMatchArray(['attribute' => 'reason', 'required' => true, 'value' => 'x']);
});

it('shares actions on the index and detail pages', function () {
    $post = mk('A');
    ActionPost::$actions = [
        new ClosureAction('plain'),
        (new ClosureAction('hidden'))->canSee(fn () => false),
        (new ClosureAction('gone', fieldList: [Text::make('Reason')->default('x')]))->standalone()->destructive(),
    ];

    $shape = fn (Assert $page) => $page
        ->has('actions', 2)
        ->where('actions.0.uriKey', 'plain')
        ->where('actions.0.destructive', false)
        ->where('actions.0.standalone', false)
        ->where('actions.0.confirmText', 'Run this action?')
        ->where('actions.0.confirmButtonText', 'Run')
        ->where('actions.0.cancelButtonText', 'Cancel')
        ->where('actions.0.withoutConfirmation', true)
        ->where('actions.0.showOnIndex', true)
        ->where('actions.0.showOnDetail', true)
        ->where('actions.0.showInline', false)
        ->where('actions.0.fields', [])
        ->where('actions.1.uriKey', 'gone')
        ->where('actions.1.standalone', true)
        ->where('actions.1.destructive', true)
        ->where('actions.1.withoutConfirmation', false)
        ->where('actions.1.fields.0.attribute', 'reason')
        ->where('actions.1.fields.0.value', 'x');

    $this->get('/admin/entities/action-posts')->assertInertia(fn (Assert $page) => $page->component('Entities/Index')->tap($shape));
    $this->get("/admin/entities/action-posts/{$post->id}")->assertInertia(fn (Assert $page) => $page->component('Entities/Detail')->tap($shape));
});

it('shares an empty actions list when the entity defines none', function () {
    $this->get('/admin/entities/action-posts')->assertInertia(fn (Assert $page) => $page->where('actions', []));
});

it('surfaces a download flash on the next page', function () {
    $post = mk('A');
    ActionPost::$actions = [new ClosureAction(callback: fn () => ActionResponse::download('/admin/export.csv', 'report.csv'))];

    runAction($this, ['resources' => [$post->id]])->assertRedirect();

    $this->get('/admin/entities/action-posts')
        ->assertInertia(fn (Assert $page) => $page->where('larsadmin.download', ['url' => '/admin/export.csv', 'filename' => 'report.csv']));

    $this->get('/admin/entities/action-posts')
        ->assertInertia(fn (Assert $page) => $page->where('larsadmin.download', null));
});

it('generates an action class', function () {
    $this->artisan('larsadmin:action', ['name' => 'Archive', '--destructive' => true])->assertSuccessful();

    $path = app_path('Larsadmin/Actions/Archive.php');
    expect($path)->toBeFile()
        ->and(file_get_contents($path))->toContain('class Archive extends Action', 'public bool $destructive = true;');

    @unlink($path);
});
