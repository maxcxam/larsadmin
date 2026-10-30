<?php

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Field;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

it('normalizes column widths', function (string|int $given, string $expected) {
    expect(Text::make('Title')->width($given)->width)->toBe($expected);
})->with([
    [120, '120px'],
    ['120px', '120px'],
    ['20%', '20%'],
    ['12rem', '12rem'],
    ['1.5em', '1.5em'],
    ['10ch', '10ch'],
]);

it('rejects invalid column widths', function (string|int $given) {
    Text::make('Title')->width($given);
})->with(['120', 'auto', '20 %', '-5px', '12vw', '', -5, 'calc(1px)'])->throws(InvalidArgumentException::class);

it('validates alignment and defaults it by type', function () {
    expect(Text::make('Title')->getAlign())->toBe('left')
        ->and(Number::make('Views')->getAlign())->toBe('right')
        ->and(Boolean::make('Published')->getAlign())->toBe('center')
        ->and(Number::make('Views')->align('center')->getAlign())->toBe('center')
        ->and(Boolean::make('Published')->align('left')->getAlign())->toBe('left');

    Text::make('Title')->align('middle');
})->throws(InvalidArgumentException::class, 'Invalid alignment');

it('makes wrap and truncate mutually exclusive, the last call winning', function () {
    $wrapped = Text::make('Title')->truncate(10)->wrap();
    $truncated = Text::make('Title')->wrap()->truncate(10);
    $css = Text::make('Title')->truncate();

    expect($wrapped->wrap)->toBeTrue()->and($wrapped->getTruncate())->toBeNull()
        ->and($truncated->wrap)->toBeFalse()->and($truncated->getTruncate())->toBe(10)
        ->and($css->getTruncate())->toBe(0)
        ->and(Text::make('Title')->getTruncate())->toBeNull()
        ->and(Text::make('Title')->wrap)->toBeFalse();
});

it('rejects a non positive truncate length', function () {
    Text::make('Title')->truncate(0);
})->throws(InvalidArgumentException::class);

it('serializes the column definition and the cell options', function () {
    $field = Number::make('Views')->width(90)->wrap()->hideByDefault();
    $field->context = Field::INDEX;

    expect($field->columnDefinition())->toBe([
        'attribute' => 'views',
        'name' => 'Views',
        'component' => 'number-field',
        'sortable' => false,
        'width' => '90px',
        'align' => 'right',
        'wrap' => true,
        'truncate' => null,
        'hideByDefault' => true,
    ]);

    $json = $field->resolve(new Post(['views' => 3]))->jsonSerialize();

    expect($json)->toMatchArray(['align' => 'right', 'wrap' => true, 'truncate' => null]);
});

it('evaluates visibility closures with the request and the record, the index record being null', function () {
    $request = Request::create('/');
    $seen = [];
    $field = Text::make('Title')->hideWhenUpdating(function ($req, $record) use (&$seen) {
        $seen[] = $record;

        return $record?->title === 'secret';
    });

    expect($field->isShownOn(Field::UPDATE, $request, new Post(['title' => 'secret'])))->toBeFalse()
        ->and($field->isShownOn(Field::UPDATE, $request, new Post(['title' => 'open'])))->toBeTrue();

    $index = Text::make('Title')->showOnIndex(fn ($req, $record) => $record === null);

    expect($index->isShownOn(Field::INDEX, $request, new Post))->toBeTrue();
});

it('treats a bool passed to hide and show helpers like the static flags', function () {
    expect(Text::make('A')->hideFromIndex(false)->isShownOn(Field::INDEX))->toBeTrue()
        ->and(Text::make('A')->hideFromIndex(true)->isShownOn(Field::INDEX))->toBeFalse()
        ->and(Text::make('A')->onlyOnForms()->showOnIndex()->isShownOn(Field::INDEX))->toBeTrue()
        ->and(Text::make('A')->hideFromIndex(fn () => true)->onlyOnIndex()->isShownOn(Field::INDEX))->toBeTrue()
        ->and(Text::make('A')->showWhenCreating(false)->isShownOn(Field::CREATE))->toBeFalse();
});

it('evaluates canSee once per request', function () {
    $calls = 0;
    $field = Text::make('Title')->canSee(function () use (&$calls) {
        $calls++;

        return false;
    });
    $request = Request::create('/');

    expect($field->isShownOn(Field::INDEX, $request))->toBeFalse()
        ->and($field->isShownOn(Field::DETAIL, $request))->toBeFalse()
        ->and($calls)->toBe(1);
});

it('resolves a readonly closure per record', function () {
    $request = Request::create('/');
    $field = Text::make('Title')->readonly(fn ($req, $record) => $record->status === 'live');

    expect($field->readonly)->toBeFalse()
        ->and($field->isReadonlyFor($request, new Post(['status' => 'live'])))->toBeTrue()
        ->and($field->isReadonlyFor($request, new Post(['status' => 'draft'])))->toBeFalse()
        ->and(Text::make('Title')->readonly()->isReadonlyFor($request, new Post))->toBeTrue();
});
