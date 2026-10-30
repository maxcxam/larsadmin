<?php

use Illuminate\Support\Arr;

// The admin UI bundles resources/js/i18n/uk.json as its offline fallback; it is generated from
// lang/uk/ui.php by `npm run i18n`. This guards against the two drifting apart.
it('keeps the bundled uk.json fallback in sync with lang/uk/ui.php', function () {
    $json = json_decode(file_get_contents(__DIR__.'/../../resources/js/i18n/uk.json'), true, flags: JSON_THROW_ON_ERROR);

    $expected = array_map('strval', Arr::dot(trans('larsadmin::ui', [], 'uk')));

    expect($json)->toEqual($expected, 'resources/js/i18n/uk.json is stale: run `npm run i18n`.');
});

it('keeps the uk and en UI translations on the same keys', function () {
    expect(array_keys(Arr::dot(trans('larsadmin::ui', [], 'en'))))
        ->toEqual(array_keys(Arr::dot(trans('larsadmin::ui', [], 'uk'))));
});
