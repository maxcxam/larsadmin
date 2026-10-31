<?php

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionRegistry;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Actions\Queued\RunQueuedAction;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\Image;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\ActionPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Actions\ClosureAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\ActionValueField;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\Rating;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPost;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedPostPolicy;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Users\NotifiableUser;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/*
 * D3a (1.6, ADR-041, design §8.3): extension fields in action modals. Action fields know they are in an
 * action (inAction(), serialized as "inAction": true), the action receives Field::actionValue() (also for
 * a value fill() does not write to fillAttribute()), a queued action carries that value through the
 * encrypted payload or fails before anything is stored, and fields that upload files stay unsupported (Q-75).
 */

beforeEach(function () {
    config(['larsadmin.locale' => 'en']);
    ActionPost::reset();
    ActionValueField::reset();
    Rating::$calls = [];
    Larsadmin::auth(fn () => true);
    Larsadmin::entities([ActionPost::class]);

    $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']));
});

afterEach(function () {
    ActionValueField::reset();
});

function aefRun(array $body = [], string $key = 'mark-live')
{
    return test()->from('/admin/entities/action-posts')->post("/admin/entities/action-posts/actions/{$key}", $body);
}

function aefPost(string $title = 'A'): Post
{
    return Post::create(['title' => $title, 'status' => 'draft']);
}

/**
 * Switch the panel to queued actions (notifications on) with the queued fixture entity.
 */
function aefQueued(): NotifiableUser
{
    config(['larsadmin.notifications.enabled' => true, 'auth.providers.users.model' => NotifiableUser::class]);
    QueuedPost::reset();
    QueuedPostPolicy::reset();
    Gate::policy(Post::class, QueuedPostPolicy::class);
    Larsadmin::entities([QueuedPost::class]);

    $user = NotifiableUser::create(['name' => 'Olena', 'email' => 'olena@example.com', 'password' => 'secret']);
    test()->actingAs($user);

    return $user;
}

function aefQueue(array $body)
{
    return test()->from('/admin/entities/queued-posts')->post('/admin/entities/queued-posts/actions/publish', $body);
}

it('marks the fields of an action, and only them, as in an action', function () {
    $action = (new ClosureAction(fieldList: [Text::make('Note'), Rating::make('Score')]))->bindTo(ActionPost::class);

    $prototypes = $action->fieldPrototypes(request());
    $resolved = $action->resolveFields(request());
    $entityFields = ActionPost::make()->fieldPrototypes(request());

    expect($prototypes->map(fn (Field $field) => $field->inAction())->all())->toBe([true, true])
        ->and($resolved->map(fn (Field $field) => $field->inAction())->all())->toBe([true, true])
        ->and($resolved->map(fn (Field $field) => $field->context)->unique()->all())->toBe([Field::CREATE])
        ->and($entityFields->contains(fn (Field $field) => $field->inAction()))->toBeFalse()
        ->and($resolved->contains(fn (Field $field) => $field->authorizedTo('view') || $field->authorizedTo('update')))->toBeFalse();
});

it('serializes inAction for action fields only', function () {
    ActionPost::$actions = [new ClosureAction(fieldList: [Rating::make('Score')->max(3)])];

    $props = json_decode(json_encode(ActionRegistry::serialize(ActionPost::class, request())), true);
    $entity = json_decode(json_encode(ActionPost::make()->resolveFields(request(), Field::CREATE)), true);

    expect($props[0]['fields'][0])->toMatchArray(['attribute' => 'score', 'component' => 'acme/rating', 'max' => 3, 'inAction' => true])
        ->and(collect($entity)->pluck('inAction')->filter()->all())->toBe([]);
});

it('hands the action the value of actionValue(), also when fill() writes other attributes', function () {
    $post = aefPost();
    ActionPost::$actions = [new ClosureAction(fieldList: [ActionValueField::make('Price'), Rating::make('Score')])];

    aefRun(['resources' => [$post->id], 'price' => ['min' => '2', 'max' => '9'], 'score' => '4'])->assertSessionHasNoErrors();

    expect(ActionPost::$calls[0]['fields'])->toBe(['price' => ['min' => 2, 'max' => 9], 'score' => 4])
        ->and(ActionValueField::$calls)->toBe([['hook' => 'actionValue', 'inAction' => true]])
        ->and(Rating::$calls)->toContain('fill', 'fillValue');
});

it('validates the nested keys of an extension field with its own attribute names', function () {
    $post = aefPost();
    ActionPost::$actions = [new ClosureAction(fieldList: [ActionValueField::make('Price')])];

    aefRun(['resources' => [$post->id], 'price' => ['min' => '9', 'max' => '2']])
        ->assertSessionHasErrors(['price.max' => 'The Price (max) field must be greater than or equal to 9.']);

    aefRun(['resources' => [$post->id], 'price' => ['min' => 'x']])->assertSessionHasErrors('price.min');

    expect(ActionPost::$calls)->toBe([])
        ->and(ActionValueField::$calls)->toBe([]);
});

it('does not run the deferred closure fill() returns in an action', function () {
    $post = aefPost();
    $ran = false;
    ActionPost::$actions = [new ClosureAction(fieldList: [Rating::make('Score')->afterSave(function () use (&$ran) {
        $ran = true;
    })])];

    aefRun(['resources' => [$post->id], 'score' => '2'])->assertSessionHasNoErrors();

    expect(ActionPost::$calls[0]['fields'])->toBe(['score' => 2])
        ->and($ran)->toBeFalse();
});

it('rejects fields that upload files in an action (Q-75)', function (Field $field) {
    $action = new class([Text::make('Note'), $field]) extends Action
    {
        public function __construct(private array $list) {}

        public function fields(Request $request): array
        {
            return $this->list;
        }

        public function handle(ActionFields $fields, Collection $models): ?ActionResponse
        {
            return null;
        }
    };

    expect(fn () => $action->bindTo(ActionPost::class)->fieldPrototypes(request()))
        ->toThrow(LogicException::class, 'file fields are not supported in actions');
})->with([
    'image' => fn () => Image::make('Cover'),
    'multipart extension field' => fn () => Rating::make('Upload')->multipart(),
]);

it('answers a run of an action with a file field with a server error, running nothing', function () {
    $post = aefPost();
    ActionPost::$actions = [new ClosureAction(fieldList: [Rating::make('Upload')->multipart()])];

    aefRun(['resources' => [$post->id]])->assertServerError();

    expect(ActionPost::$calls)->toBe([])
        ->and(Post::sole()->status)->toBe('draft');
});

it('queues the action value of an extension field and hands it to the worker unchanged', function () {
    aefQueued();
    $post = aefPost();
    QueuedPost::$actions = [new QueuedAction(fieldList: [ActionValueField::make('Price'), Rating::make('Score')])];
    Bus::fake([RunQueuedAction::class]);

    aefQueue(['resources' => [$post->id], 'price' => ['min' => '1', 'max' => '5'], 'score' => '3'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('larsadmin.run');

    $run = ActionRun::sole();

    expect($run->payload['fields'])->toBe(['price' => ['min' => 1, 'max' => 5], 'score' => 3])
        ->and(QueuedAction::$calls)->toBe([]);

    ActionValueField::$calls = [];
    (new RunQueuedAction($run->id))->handle();

    expect($run->refresh()->status)->toBe(ActionRun::COMPLETED)
        ->and(QueuedAction::$calls)->toHaveCount(1)
        ->and(QueuedAction::$calls[0]['fields'])->toBe(['price' => ['min' => 1, 'max' => 5], 'score' => 3])
        // The worker uses the stored value: actionValue() is not asked again without the original input.
        ->and(ActionValueField::$calls)->toBe([]);
});

it('refuses an action value that cannot be queued, naming the field, before a run is stored', function () {
    aefQueued();
    $post = aefPost();
    QueuedPost::$actions = [new QueuedAction(fieldList: [ActionValueField::make('Price')])];
    ActionValueField::$override = fn () => ['min' => new stdClass];
    Bus::fake([RunQueuedAction::class]);

    test()->withoutExceptionHandling();

    expect(fn () => aefQueue(['resources' => [$post->id], 'price' => ['min' => '1']]))
        ->toThrow(LogicException::class, 'A queued action cannot carry the value of the field [price] of type [stdClass]');

    expect(ActionRun::count())->toBe(0)
        ->and(QueuedAction::$calls)->toBe([]);

    Bus::assertNotDispatched(RunQueuedAction::class);
});
