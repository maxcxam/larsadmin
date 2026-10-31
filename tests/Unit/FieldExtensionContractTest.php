<?php

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\Rating;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

beforeEach(function () {
    Rating::$calls = [];
});

it('serializes built-in fields with exactly the 1.4 keys', function () {
    expect(array_keys(Text::make('Title')->jsonSerialize()))->toBe([
        'component', 'name', 'attribute', 'value', 'displayedAs', 'sortable', 'nullable', 'readonly', 'required',
        'help', 'placeholder', 'align', 'wrap', 'truncate', 'fullWidth',
    ])
        ->and(Boolean::make('Published')->jsonSerialize())->not->toHaveKey('multipart')
        ->and(File::make('Cover')->jsonSerialize())->not->toHaveKey('multipart');
});

it('serializes multipart when a field uploads files', function () {
    $field = Rating::make('Rating', 'views')->multipart();

    expect($field->multipart)->toBeTrue()
        ->and($field->jsonSerialize()['multipart'])->toBeTrue()
        ->and($field->multipart(false)->jsonSerialize())->not->toHaveKey('multipart')
        ->and(Text::make('Title')->multipart)->toBeFalse();
});

it('lets the keys of a subclass win over the base keys', function () {
    $field = new class('Score') extends Field
    {
        public string $component = 'acme/score';

        public function jsonSerialize(): array
        {
            return [...parent::jsonSerialize(), 'align' => 'right', 'component' => 'acme/score-v2', 'extra' => 1];
        }
    };

    expect($field->jsonSerialize())->toMatchArray(['align' => 'right', 'component' => 'acme/score-v2', 'extra' => 1]);
});

it('merges meta over the base keys', function () {
    $json = Rating::make('Rating', 'views')->withMeta(['placeholder' => 'Stars', 'step' => 1])->multipart()->jsonSerialize();

    expect($json)->toMatchArray(['placeholder' => 'Stars', 'step' => 1, 'multipart' => true, 'max' => 5, 'component' => 'acme/rating']);
});

it('resolves through resolveValue, then formatForDisplay', function () {
    $field = Rating::make('Rating', 'views')->resolve(new Post(['views' => '3']));

    expect(Rating::$calls)->toBe(['resolveValue', 'formatForDisplay'])
        ->and($field->value)->toBe(3)
        ->and($field->displayedAs)->toBe('***')
        ->and($field->getAlign())->toBe('center');
});

it('fills through fillValue into fillAttribute and returns the deferred closure', function () {
    $after = fn (Post $post) => null;
    $field = Rating::make('Rating', 'views')->afterSave($after);
    $post = new Post;

    $result = $field->fill(Request::create('/', 'POST', ['views' => '4']), $post);

    expect(Rating::$calls)->toBe(['fill', 'fillValue'])
        ->and($post->views)->toBe(4)
        ->and($result)->toBe($after)
        ->and($field->fillAttribute())->toBe('views');
});

it('builds rules from typeRules, nullable and the user rules', function () {
    expect(Rating::make('Rating', 'views')->max(3)->rules('required')->rulesFor(Field::CREATE))
        ->toBe(['views' => ['integer', 'min:0', 'max:3', 'required']])
        ->and(Rating::make('Rating', 'views')->nullable()->rulesFor(Field::UPDATE))
        ->toBe(['views' => ['nullable', 'integer', 'min:0', 'max:5']]);
});

it('keeps the stable public hooks of the base field', function () {
    $field = Rating::make('Rating', 'views');

    expect($field->eagerLoads())->toBe([])
        ->and($field->isRelation())->toBeFalse()
        ->and($field->component)->toBe('acme/rating')
        ->and($field->jsonSerialize()['align'])->toBe('center');
});
