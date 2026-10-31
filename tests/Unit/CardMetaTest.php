<?php

use Maxcxam\Larsadmin\Tests\Fixtures\Cards\StatusCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Cards\Welcome;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PlainCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Metrics\PostsValue;

it('serializes a card exactly as before without meta', function () {
    expect(PlainCard::make()->jsonSerialize())->toBe([
        'component' => 'plain-card',
        'uriKey' => 'plain-card',
        'name' => 'Plain Card',
        'width' => '1/3',
        'helpText' => null,
        'endpoint' => null,
    ]);
});

it('merges meta after the base keys', function () {
    $card = PlainCard::make()->withMeta(['color' => 'red'])->withMeta(['size' => 2, 'color' => 'blue']);

    expect($card->jsonSerialize())->toBe([
        'component' => 'plain-card',
        'uriKey' => 'plain-card',
        'name' => 'Plain Card',
        'width' => '1/3',
        'helpText' => null,
        'endpoint' => null,
        'color' => 'blue',
        'size' => 2,
    ]);
});

it('refuses meta that would replace a base key', function (string $key) {
    expect(fn () => PlainCard::make()->withMeta([$key => 'x', 'extra' => 1]))->toThrow(InvalidArgumentException::class, $key);
})->with([
    'component' => ['component'],
    'uri key' => ['uriKey'],
    'name' => ['name'],
    'width' => ['width'],
    'help text' => ['helpText'],
    'endpoint' => ['endpoint'],
]);

it('lets subclass keys win over meta', function () {
    expect(Welcome::make()->withMeta(['links' => []])->jsonSerialize()['links'])->toBe(['docs' => '/docs']);
});

it('keeps metric keys after meta', function () {
    $json = PostsValue::make()->withMeta(['ranges' => 'nope', 'extra' => true])->jsonSerialize();

    expect($json['extra'])->toBeTrue()
        ->and($json['ranges'])->toBeArray()
        ->and(array_key_last($json))->toBe('defaultRange');
});

it('serializes data cards like any card', function () {
    expect(StatusCard::make()->withMeta(['refresh' => 30])->jsonSerialize())->toBe([
        'component' => 'acme/status',
        'uriKey' => 'status-card',
        'name' => 'Status Card',
        'width' => '1/3',
        'helpText' => null,
        'endpoint' => null,
        'refresh' => 30,
    ]);
});
