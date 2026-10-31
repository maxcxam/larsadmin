<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\ConfigurablePost;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\AbilityAwareField;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\Rating;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * Viewing and updating are allowed for drafts only; deleting never.
 */
class FieldAbilitiesPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): bool
    {
        return $post->status === 'draft';
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Post $post): bool
    {
        return $post->status === 'draft';
    }

    public function delete(User $user, Post $post): bool
    {
        return false;
    }

    public function forceDelete(User $user, Post $post): bool
    {
        return true;
    }
}

const ABILITIES_BASE = '/admin/entities/configurable-posts';

beforeEach(function () {
    ConfigurablePost::reset();
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ConfigurablePost::class]);
    Gate::policy(Post::class, FieldAbilitiesPolicy::class);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

afterEach(fn () => ConfigurablePost::reset());

function withAbilityField(bool $aware = true): void
{
    ConfigurablePost::$factory = fn () => array_filter([
        ID::make(),
        Text::make('Title'),
        $aware ? AbilityAwareField::make('Rating', 'views') : null,
    ]);
}

/**
 * The number of Gate checks made while running the callback.
 */
function gateChecks(Closure $callback): int
{
    $checks = 0;
    Gate::after(function () use (&$checks) {
        $checks++;
    });

    $callback();

    return $checks;
}

$denied = ['view' => false, 'update' => false, 'delete' => false, 'forceDelete' => false];

it('answers the abilities of each index row', function () {
    withAbilityField();
    $draft = Post::create(['title' => 'Draft', 'status' => 'draft']);
    $live = Post::create(['title' => 'Live', 'status' => 'live']);

    $this->get(ABILITIES_BASE)->assertOk()->assertInertia(function (Assert $page) use ($draft, $live) {
        $answers = collect($page->toArray()['props']['records']['data'])
            ->mapWithKeys(fn (array $row) => [$row['id'] => collect($row['fields'])->firstWhere('attribute', 'views')['answers']]);

        // forceDelete is allowed by the policy, but it is not a record ability the panel computes: fail closed.
        expect($answers[$draft->id])->toBe(['view' => true, 'update' => true, 'delete' => false, 'forceDelete' => false])
            ->and($answers[$live->id])->toBe(['view' => false, 'update' => false, 'delete' => false, 'forceDelete' => false]);
    });
});

it('answers the abilities on the detail and edit pages, and none on the create page', function () use ($denied) {
    withAbilityField();
    $draft = Post::create(['title' => 'Draft', 'status' => 'draft']);
    $expected = ['view' => true, 'update' => true, 'delete' => false, 'forceDelete' => false];

    $answers = fn (array $fields) => collect($fields)->firstWhere('attribute', 'views')['answers'];

    $this->get(ABILITIES_BASE."/{$draft->id}")->assertOk()->assertInertia(
        fn (Assert $page) => expect($answers($page->toArray()['props']['record']['fields']))->toBe($expected)
    );

    $this->get(ABILITIES_BASE."/{$draft->id}/edit")->assertOk()->assertInertia(
        fn (Assert $page) => expect($answers($page->toArray()['props']['record']['fields']))->toBe($expected)
    );

    $this->get(ABILITIES_BASE.'/create')->assertOk()->assertInertia(
        fn (Assert $page) => expect($answers($page->toArray()['props']['fields']))->toBe($denied)
    );
});

it('does not add the abilities to the serialized field', function () {
    withAbilityField(false);
    Post::create(['title' => 'Draft', 'status' => 'draft']);

    $this->get(ABILITIES_BASE)->assertInertia(function (Assert $page) {
        $field = collect($page->toArray()['props']['records']['data'][0]['fields'])->firstWhere('attribute', 'title');

        expect($field)->not->toHaveKeys(['abilities', 'authorizedTo', 'inAction']);
    });
});

it('makes as many Gate checks on an index of 25 rows as 1.5', function () {
    foreach (range(1, 25) as $i) {
        Post::create(['title' => "Post {$i}", 'status' => $i % 2 ? 'draft' : 'live']);
    }

    withAbilityField(false);
    $without = gateChecks(fn () => $this->get(ABILITIES_BASE)->assertOk());

    withAbilityField();
    $with = gateChecks(fn () => $this->get(ABILITIES_BASE)->assertOk());

    Post::query()->delete();
    $empty = gateChecks(fn () => $this->get(ABILITIES_BASE)->assertOk());

    // 1.5: view, update and delete once per row (serializeRecord), plus the page-level checks.
    expect($with)->toBe($without)
        ->and($with - $empty)->toBe(25 * 3);
});

it('asks the policy lazily, once per ability, outside serializeRecord()', function () {
    $draft = Post::create(['title' => 'Draft', 'status' => 'draft']);

    // The prototypes are shared per request: a new request for each set of fields.
    ConfigurablePost::$factory = fn () => [ID::make(), Text::make('Title')];
    expect(gateChecks(fn () => ConfigurablePost::make($draft)->resolveFields(Request::create('/'), Field::DETAIL)))->toBe(0);

    ConfigurablePost::$factory = fn () => [AbilityAwareField::make('A', 'views'), AbilityAwareField::make('B', 'title')];
    $fields = null;
    $checks = gateChecks(function () use (&$fields, $draft) {
        $fields = ConfigurablePost::make($draft)->resolveFields(Request::create('/'), Field::UPDATE);
    });

    expect($checks)->toBe(3)
        ->and($fields[0]->answers)->toBe($fields[1]->answers)
        ->and($fields[0]->answers['update'])->toBeTrue();
});

it('answers false for a field built by hand, an unknown ability and the create context', function () {
    $field = AbilityAwareField::make('Rating', 'views');

    expect($field->authorizedTo('view'))->toBeFalse();

    $field->withAbilities(['view' => true, 'update' => fn () => true, 'forceDelete' => true]);

    expect($field->authorizedTo('view'))->toBeTrue()
        ->and($field->authorizedTo('update'))->toBeTrue()
        ->and($field->authorizedTo('delete'))->toBeFalse()
        ->and($field->authorizedTo('forceDelete'))->toBeFalse()
        ->and($field->authorizedTo('VIEW'))->toBeFalse();

    $field->context = Field::CREATE;

    expect($field->authorizedTo('view'))->toBeFalse();
});

it('computes a closure ability once', function () {
    $calls = 0;
    $field = AbilityAwareField::make('Rating', 'views')->withAbilities(['view' => function () use (&$calls) {
        $calls++;

        return true;
    }]);

    $field->authorizedTo('view');
    $field->authorizedTo('view');

    expect($calls)->toBe(1);
});

it('hands findField() the record abilities, and none for create', function () {
    withAbilityField();
    $draft = Post::create(['title' => 'Draft', 'status' => 'draft']);
    $live = Post::create(['title' => 'Live', 'status' => 'live']);
    $request = request();

    $field = Larsadmin::findField($request, 'configurable-posts', $draft, 'views', Field::DETAIL);

    expect($field->ask())->toBe(['view' => true, 'update' => true, 'delete' => false, 'forceDelete' => false])
        ->and(Larsadmin::findField($request, 'configurable-posts', $live, 'views')->ask()['update'])->toBeFalse()
        ->and(Larsadmin::findField($request, 'configurable-posts', $draft, 'views', Field::CREATE)->ask())
        ->toBe(['view' => false, 'update' => false, 'delete' => false, 'forceDelete' => false]);

    // Nothing is asked until the field asks.
    expect(gateChecks(fn () => Larsadmin::findField($request, 'configurable-posts', $draft, 'views')))->toBe(0);
});

it('marks a field of an action, which has no record abilities', function () {
    $field = AbilityAwareField::make('Rating', 'views');

    expect($field->inAction())->toBeFalse()
        ->and($field->jsonSerialize())->not->toHaveKey('inAction');

    $field->withAbilities(['view' => true])->forAction('publish');
    $field->context = Field::CREATE;

    expect($field->inAction())->toBeTrue()
        ->and($field->context)->toBe(Field::CREATE)
        ->and($field->jsonSerialize()['inAction'])->toBeTrue()
        ->and($field->authorizedTo('view'))->toBeFalse()
        ->and($field->withAbilities(['view' => true])->authorizedTo('view'))->toBeFalse();
});

it('reads the action value from fill() and fillAttribute() without running the deferred closure', function () {
    $ran = false;
    $field = Rating::make('Rating', 'stars')->afterSave(function () use (&$ran) {
        $ran = true;
    })->forAction('rate');

    expect($field->actionValue(Request::create('/', 'POST', ['stars' => '4'])))->toBe(4)
        ->and($field->actionValue(Request::create('/', 'POST')))->toBeNull()
        ->and($ran)->toBeFalse();

    $custom = Text::make('Note')->fillUsing(function (Request $request, $model) {
        $model->note = strtoupper((string) $request->input('note'));
    });

    expect($custom->actionValue(Request::create('/', 'POST', ['note' => 'hi'])))->toBe('HI');
});
