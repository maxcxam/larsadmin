<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\ActionPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\ClosureAction;
use Maxcxam\Larsadmin\Tests\Fixtures\NoUpdatePolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\PostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

beforeEach(function () {
    config(['larsadmin.locale' => 'en']);
    ActionPost::reset();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ActionPost::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

function rowPost(string $title = 'A', string $status = 'draft'): Post
{
    return Post::create(['title' => $title, 'status' => $status]);
}

it('sets visibility with showOnIndex, showOnDetail and onlyInline', function () {
    $action = ClosureAction::make();
    expect([$action->showOnIndex, $action->showOnDetail, $action->showInline])->toBe([true, true, false]);

    $action->showOnIndex(false)->showOnDetail(false);
    expect([$action->showOnIndex, $action->showOnDetail])->toBe([false, false]);

    $action->showOnIndex()->showOnDetail();
    expect([$action->showOnIndex, $action->showOnDetail])->toBe([true, true]);

    $inline = ClosureAction::make()->onlyInline();
    expect([$inline->showOnIndex, $inline->showOnDetail, $inline->showInline])->toBe([false, false, true]);

    $keeps = ClosureAction::make()->showInline()->onlyOnDetail();
    expect([$keeps->showOnIndex, $keeps->showOnDetail, $keeps->showInline])->toBe([false, true, true]);
});

it('detects the default run check', function () {
    expect(ClosureAction::make()->usesDefaultRunCheck())->toBeTrue()
        ->and(ClosureAction::make()->canRun(fn () => true)->usesDefaultRunCheck())->toBeFalse();

    $overridden = new class extends ClosureAction
    {
        public function authorizedToRun(Request $request, Model $model): bool
        {
            return true;
        }
    };

    expect($overridden->usesDefaultRunCheck())->toBeFalse();
});

it('selects row actions per context and skips standalone and hidden ones', function () {
    ActionPost::$actions = [
        ClosureAction::make('inline')->onlyInline(),
        ClosureAction::make('plain'),
        ClosureAction::make('alone')->standalone()->showInline(),
        ClosureAction::make('hidden')->showInline()->canSee(fn () => false),
        ClosureAction::make('index-only')->onlyOnIndex(),
    ];

    expect(array_keys(ActionRegistry::rowActions(ActionPost::class, request(), 'index')))->toBe(['inline'])
        ->and(array_keys(ActionRegistry::rowActions(ActionPost::class, request(), 'detail')))->toBe(['plain']);
});

it('computes runnable keys per record without calling the policy for default checks', function () {
    $record = rowPost();
    ActionPost::$actions = [
        ClosureAction::make('default')->showInline(),
        ClosureAction::make('custom')->showInline()->canRun(fn ($request, $model) => $model->title === 'A'),
        ClosureAction::make('denied')->showInline()->canRun(fn () => false),
        ClosureAction::make('alone')->standalone(),
    ];
    $actions = ActionRegistry::for(ActionPost::class, request());

    Gate::policy(Post::class, PostPolicy::class);
    $checks = 0;
    Gate::after(function () use (&$checks) {
        $checks++;
    });

    expect(ActionRegistry::runnableFor(request(), $actions, $record, true))->toBe(['default', 'custom'])
        ->and(ActionRegistry::runnableFor(request(), $actions, $record, false))->toBe(['custom'])
        ->and($checks)->toBe(0);
});

it('serializes runnable keys on index rows only when inline actions exist', function () {
    rowPost('A');
    rowPost('B');
    ActionPost::$actions = [
        ClosureAction::make('default')->onlyInline(),
        ClosureAction::make('custom')->onlyInline()->canRun(fn ($request, $model) => $model->title === 'A'),
    ];

    $this->get('/admin/entities/action-posts')->assertInertia(function (Assert $page) {
        $rows = collect($page->toArray()['props']['records']['data'])->mapWithKeys(fn ($row) => [$row['title'] => $row['actions']]);

        expect($rows->all())->toEqualCanonicalizing(['A' => ['default', 'custom'], 'B' => ['default']]);
    });

    ActionPost::$actions = [ClosureAction::make('toolbar')];
    $this->get('/admin/entities/action-posts')->assertInertia(fn (Assert $page) => $page->missing('records.data.0.actions'));
});

it('hides default inline actions on rows the user may not update', function () {
    rowPost();
    Gate::policy(Post::class, NoUpdatePolicy::class);
    ActionPost::$actions = [ClosureAction::make()->onlyInline()];

    $this->get('/admin/entities/action-posts')->assertInertia(fn (Assert $page) => $page->where('records.data.0.actions', []));
});

it('serializes runnable keys on the detail record only when detail actions exist', function () {
    $post = rowPost();
    ActionPost::$actions = [
        ClosureAction::make('plain'),
        ClosureAction::make('custom')->canRun(fn () => false),
        ClosureAction::make('inline')->onlyInline(),
    ];

    $this->get("/admin/entities/action-posts/{$post->id}")->assertInertia(fn (Assert $page) => $page->where('record.actions', ['plain']));

    ActionPost::$actions = [ClosureAction::make('inline')->onlyInline()];
    $this->get("/admin/entities/action-posts/{$post->id}")->assertInertia(fn (Assert $page) => $page->missing('record.actions'));

    Gate::policy(Post::class, NoUpdatePolicy::class);
    ActionPost::$actions = [ClosureAction::make('plain')];
    $this->get("/admin/entities/action-posts/{$post->id}")->assertInertia(fn (Assert $page) => $page->where('record.actions', []));
});

it('adds no queries or policy calls per row for inline actions', function () {
    Gate::policy(Post::class, PostPolicy::class);
    $updates = 0;
    Gate::after(function ($user, $ability) use (&$updates) {
        $updates += $ability === 'update' ? 1 : 0;
    });

    $measure = function (int $rows) use (&$updates): array {
        Post::query()->delete();
        foreach (range(1, $rows) as $i) {
            rowPost("P{$i}");
        }
        $updates = 0;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/admin/entities/action-posts')->assertOk();

        return [count(DB::getQueryLog()), $updates];
    };

    ActionPost::$actions = [ClosureAction::make('default')->onlyInline()];
    [$queries3, $updates3] = $measure(3);
    [$queries25, $updates25] = $measure(25);

    ActionPost::$actions = [];
    [, $baseline25] = $measure(25);

    expect($queries25)->toBe($queries3)
        ->and($updates3)->toBe(3)
        ->and($updates25)->toBe($baseline25)->toBe(25);
});

it('keeps the run-time authorization authoritative', function () {
    $post = rowPost();
    Gate::policy(Post::class, NoUpdatePolicy::class);
    ActionPost::$actions = [ClosureAction::make()->onlyInline()];

    $this->from('/admin/entities/action-posts')
        ->post('/admin/entities/action-posts/actions/mark-live', ['resources' => [$post->id]])
        ->assertForbidden();
});
