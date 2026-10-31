# Testing custom fields and extensions

Examples use Pest. With PHPUnit, put the same body in a `public function test_...(): void` method of a class
that uses `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` (see the skill's Quick start); use
`$this->expectException(ValidationException::class)` instead of `->throws()`.

## Custom and extension fields

```php
use App\Larsadmin\Fields\Rating;
use App\Models\Post;
use Illuminate\Validation\ValidationException;

it('serializes the rating with the record abilities', function () {
    $field = Rating::make('Rating', 'rating')->max(5);

    // This field adds 'canReset' => $this->authorizedTo('update') in jsonSerialize().
    expect($this->serializeField($field, new Post(['rating' => 4]), abilities: ['update' => true]))
        ->toMatchArray(['component' => 'app/rating', 'value' => 4, 'canReset' => true]);
});

it('rejects a rating above the maximum', function () {
    $this->fillField(Rating::make('Rating', 'rating')->max(5), Post::factory()->create(), ['rating' => 9]);
})->throws(ValidationException::class);
```

- `serializeField($field, $record, $context = Field::DETAIL, $entity = null, $abilities = [])`: `$entity` (class or
  uriKey) calls `bindTo()` first; `$abilities` (1.6, `view`/`update`/`delete` booleans) feed
  `Field::authorizedTo()`; they are ignored without a record and in the create context.
- `fillField($field, $record, $input, $files = [], $context = Field::UPDATE)` validates with the field's rules,
  runs `fillInto()` with a file journal, saves, and deletes `created()` files on failure.
- `cardData($card, $query = [], $user = null, $owner = 'dashboard:test')` runs a `DataCard`: `canSee()`
  (`AuthorizationException`), `rules()` (`ValidationException`), then `data()`.
- For an extension package: `$this->assertExtensionRegistered('acme/larsadmin-rating')` and
  `$this->extensionAssetResponse('acme/larsadmin-rating', 'extension.js')->assertOk()`.

## Vue components of an extension (vitest)

```js
import { expect, it } from 'vitest'
import { mountField } from 'larsadmin-kit/testing.js'
import RatingInput from './RatingInput.vue'

it('works in an action modal', () => {
    const wrapper = mountField(RatingInput, {
        field: { attribute: 'rating', value: null, max: 5 },
        action: { uriKey: 'rate-posts', entity: 'posts' }, // 1.6: inAction: true, context 'create'
    })

    // RatingInput declares the `action` prop with `default: null`.
    expect(wrapper.props('action')).toEqual({ uriKey: 'rate-posts', entity: 'posts' })
})
```

`withPanel()`, `mountField()`, `mountDisplay()` and `mountCard()` come from `kit/testing.js`; details in the
`larsadmin-extending` skill.
