<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In as InRule;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Tests\Fixtures\Flag;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;
use Maxcxam\Larsadmin\Tests\Fixtures\Status;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

it('derives the attribute from the name', function () {
    expect(Text::make('Published At')->attribute)->toBe('published_at')
        ->and(Text::make('Title', 'headline')->attribute)->toBe('headline')
        ->and(ID::make()->attribute)->toBe('id');
});

it('applies default visibility per field type', function () {
    expect(ID::make()->isShownOn(Field::CREATE))->toBeFalse()
        ->and(ID::make()->isShownOn(Field::INDEX))->toBeTrue()
        ->and(Textarea::make('Body')->isShownOn(Field::INDEX))->toBeFalse()
        ->and(Password::make('Password')->isShownOn(Field::DETAIL))->toBeFalse()
        ->and(Password::make('Password')->isShownOn(Field::UPDATE))->toBeTrue();
});

it('changes visibility with the fluent helpers', function () {
    $field = Text::make('Name')->onlyOnDetail();

    expect($field->isShownOn(Field::DETAIL))->toBeTrue()
        ->and($field->isShownOn(Field::INDEX))->toBeFalse()
        ->and($field->isShownOn(Field::CREATE))->toBeFalse();

    expect(Text::make('Name')->hideWhenUpdating()->isShownOn(Field::UPDATE))->toBeFalse();
});

it('merges context rules and replaces the id placeholder', function () {
    $field = Text::make('Email')
        ->rules('required', 'email')
        ->creationRules(['unique:users,email'])
        ->updateRules('unique:users,email,{{id}}');

    $user = new User(['id' => 7]);
    $user->id = 7;

    expect($field->rulesFor(Field::CREATE))->toBe(['email' => ['nullable', 'string', 'required', 'email', 'unique:users,email']])
        ->and($field->rulesFor(Field::UPDATE, $user))->toBe(['email' => ['nullable', 'string', 'required', 'email', 'unique:users,email,7']]);
});

it('resolves display values', function () {
    $post = new Post([
        'status' => 'live',
        'published' => 1,
        'published_at' => '2026-10-29 14:30:00',
    ]);

    expect(Select::make('Status')->options(['live' => 'Live'])->resolve($post)->displayedAs)->toBe('Live')
        ->and(Boolean::make('Published')->resolve($post)->value)->toBeTrue()
        ->and(DateTime::make('Published At')->resolve($post)->value)->toBe('2026-10-29T14:30:00')
        ->and(DateTime::make('Published At')->resolve($post)->displayedAs)->toBe('2026-10-29 14:30:00');
});

it('uses custom display callbacks', function () {
    $field = Text::make('Title')->displayUsing(fn ($value) => strtoupper($value))->resolve(new Post(['title' => 'hi']));

    expect($field->value)->toBe('hi')->and($field->displayedAs)->toBe('HI');
});

it('fills values from the request', function () {
    $post = new Post;
    $request = Request::create('/', 'POST', ['views' => '12', 'published' => '0', 'title' => 'Hello']);

    Number::make('Views')->fill($request, $post);
    Boolean::make('Published')->fill($request, $post);
    Text::make('Title')->fill($request, $post);
    Text::make('Missing')->fill($request, $post);

    expect($post->views)->toBe(12)
        ->and($post->published)->toBeFalse()
        ->and($post->title)->toBe('Hello')
        ->and($post->getAttributes())->not->toHaveKey('missing');
});

it('only hashes a password when one is given', function () {
    $user = (new User)->setRawAttributes(['password' => 'old-hash']);

    Password::make('Password')->fill(Request::create('/', 'POST', ['password' => '']), $user);
    expect($user->getAttributes()['password'])->toBe('old-hash');

    Password::make('Password')->fill(Request::create('/', 'POST', ['password' => 'new-secret']), $user);
    expect(Hash::check('new-secret', $user->password))->toBeTrue();
});

it('never exposes the password value', function () {
    expect(Password::make('Password')->resolve(new User(['password' => 'hash']))->value)->toBeNull();
});

it('serializes field metadata', function () {
    $field = Select::make('Status')->options(['a' => 'A'])->rules('required')->help('Pick one');
    $field->context = Field::CREATE;

    expect($field->jsonSerialize())->toMatchArray([
        'component' => 'select-field',
        'attribute' => 'status',
        'required' => true,
        'help' => 'Pick one',
        'options' => [['value' => 'a', 'label' => 'A']],
    ]);
});

// CR-C1

it('explodes pipe-string rules and keeps rule objects intact', function () {
    $closure = fn ($attribute, $value, $fail) => null;
    $rule = Rule::unique('users');

    $field = Text::make('Name')->rules('required|max:255', $rule, $closure, 'min:2|')->creationRules('unique:users,name,{{id}}|alpha');

    expect($field->rulesFor(Field::CREATE)['name'])->toBe([
        'nullable', 'string', 'required', 'max:255', $rule, $closure, 'min:2', 'unique:users,name,NULL', 'alpha',
    ])->and($field->isRequired())->toBeTrue();
});

it('does not explode string rules that contain a regex', function () {
    $field = Text::make('Code')->rules('regex:/^(a|b)$/');

    expect($field->rulesFor(Field::CREATE)['code'])->toContain('regex:/^(a|b)$/');
});

// SEC-4 / CR-C3 / SEC-7

it('adds implicit rules per field type and skips them with fillUsing', function () {
    expect(Select::make('Status')->options(['a' => 'A', 'b' => 'B'])->rulesFor(Field::CREATE)['status'])
        ->toHaveCount(1)->{0}->toBeInstanceOf(InRule::class)
        ->and(Number::make('Views')->min(0)->max(10)->nullable()->rulesFor(Field::CREATE)['views'])
        ->toBe(['nullable', 'numeric', 'min:0', 'max:10'])
        ->and(Date::make('Day')->rulesFor(Field::CREATE)['day'])->toBe(['date'])
        ->and(DateTime::make('At')->nullable()->rulesFor(Field::CREATE)['at'])->toBe(['nullable', 'date'])
        ->and(Boolean::make('Flag')->rulesFor(Field::CREATE)['flag'])->toBe(['boolean'])
        ->and(Textarea::make('Body')->rulesFor(Field::CREATE)['body'])->toBe(['nullable', 'string'])
        ->and(Password::make('Password')->rulesFor(Field::CREATE)['password'])->toBe(['nullable', 'string'])
        ->and(Number::make('Views')->fillUsing(fn () => null)->rulesFor(Field::CREATE)['views'])->toBe([]);
});

it('keeps isRequired correct with implicit rules', function () {
    expect(Select::make('Status')->options(['a' => 'A'])->rules('required')->isRequired())->toBeTrue()
        ->and(Select::make('Status')->options(['a' => 'A'])->isRequired())->toBeFalse();
});

// CR-C2

it('keeps seconds in the datetime input format', function () {
    $post = new Post(['published_at' => '2026-10-29 10:00:37']);

    expect(DateTime::make('Published At')->resolve($post)->value)->toBe('2026-10-29T10:00:37');
});

// CR-W1

it('compares select options strictly and handles enums', function () {
    $options = ['0' => 'Zero', 'live' => 'Live', 'draft' => 'Draft'];

    expect(Select::make('S')->options($options)->resolve(new Post(['s' => null]))->displayedAs)->toBeNull()
        ->and(Select::make('S')->options($options)->resolve(new Post(['s' => 0]))->displayedAs)->toBe('Zero')
        ->and(Select::make('S')->options($options)->resolve(new Post(['s' => 'unknown']))->displayedAs)->toBe('unknown')
        ->and(Select::make('S')->options($options)->resolve(new Post(['s' => Status::Live]))->displayedAs)->toBe('Live')
        ->and(Select::make('S')->options($options)->resolve(new Post(['s' => Status::Live]))->value)->toBe('live')
        ->and(Select::make('S')->options(['Draft' => 'A draft'])->resolve(new Post(['s' => Flag::Draft]))->displayedAs)->toBe('A draft')
        ->and(Select::make('S')->options(['Draft' => 'A draft'])->resolve(new Post(['s' => Flag::Draft]))->value)->toBe('Draft');
});

it('resolves select options once per field instance', function () {
    $calls = 0;
    $field = Select::make('Status')->options(function () use (&$calls) {
        $calls++;

        return ['a' => 'A'];
    });

    $field->resolve(new Post(['status' => 'a']));
    $field->resolve(new Post(['status' => 'a']));
    $field->jsonSerialize();

    expect($calls)->toBe(1);
});

// CR-W10

it('stores an empty string for null text input unless nullable', function () {
    $request = Request::create('/', 'POST', ['title' => null, 'body' => null]);
    $post = new Post;

    Text::make('Title')->fill($request, $post);
    Textarea::make('Body')->nullable()->fill($request, $post);

    expect($post->title)->toBe('')->and($post->body)->toBeNull();
});

// CR-W11

it('does not throw on invalid stored dates and passes the raw value to displayUsing', function () {
    $user = new User(['name' => 'garbage']);

    $field = Date::make('Name')->resolve($user);
    expect($field->value)->toBe('garbage')->and($field->displayedAs)->toBe('garbage');

    $received = null;
    DateTime::make('Published At')
        ->displayUsing(function ($value) use (&$received) {
            $received = $value;

            return 'x';
        })
        ->resolve(new Post(['published_at' => '2026-10-29 14:30:00']));

    expect($received)->toBeInstanceOf(DateTimeInterface::class);
});
