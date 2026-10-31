# 19. Extensions: the public API
🇺🇦 [Українська](../19-extensions.md)

Since 1.5 the panel has a public extension API. An extension is a regular Composer package with PHP
classes and built Vue components. It adds your own **fields**, dashboard **cards**, **filters**, sidebar
pages (**tools**), HTTP routes and translations, and it can replace the UI of a few built-in components.

An extension is installed like this:

```bash
composer require acme/larsadmin-rating
```

The host needs no npm, no `vendor:publish` and no config changes: the package registers itself in its
service provider and the panel serves its files. Only the extension author needs npm, to build the Vue
components.

Everything on this page is **stable 1.x API** (see [Versioning and compatibility](#versioning-and-compatibility)).
What you can extend without JavaScript (entities, actions, metrics, events) is described in
[Extending](10-extending.md).

## Concepts

| What | PHP | Vue (in `defineExtension()`) |
| --- | --- | --- |
| Field | a subclass of `Maxcxam\Larsadmin\Fields\Field` with `$component = 'acme/rating'` | `fields: { 'acme/rating': { form, display } }` |
| Static card | a subclass of `Maxcxam\Larsadmin\Metrics\Card` | `cards: { 'acme/welcome': Welcome }` |
| Data card | a subclass of `Maxcxam\Larsadmin\Metrics\DataCard` | `cards: { 'acme/top-rated': TopRated }` + `useCardData()` |
| Filter | a subclass of `Maxcxam\Larsadmin\Filters\Filter` with `$component = 'acme/stars'` | `filters: { 'acme/stars': StarsFilter }` |
| Page (tool) | a subclass of `Maxcxam\Larsadmin\Tools\Tool` | `tools: { 'acme/backups': BackupsPage }` |
| Override | (nothing) | `fields: { 'date-field': { form: Picker, override: true } }` |

Server and client are matched by the **component key**. Extension keys look like `vendor/name`
(`^[a-z0-9-]+\/[a-z0-9-]+$`); keys without `/` belong to the panel. The `larsadmin/` prefix is reserved for
first-party packages (such as the sandbox demo extension), `app/` for an extension inside the application.

How it works: the panel builds Vue, Inertia and its SDK as separate modules and adds an
[import map](https://developer.mozilla.org/docs/Web/HTML/Element/script/type/importmap) to the page that maps
the `vue`, `@inertiajs/vue3` and `larsadmin` specifiers to them. An extension is built as an ES module that
leaves those imports external. So the panel and every extension work with **one** Vue and Inertia instance:
`ref`, `provide/inject` and `usePage()` in an extension see the same page as the panel.

### Trust model

An installed extension is **trusted code**. Its PHP already runs with the full rights of the application,
and its JavaScript runs on panel pages with the rights of the signed-in administrator: it can read the page
props and make any request the user can make. **Install only extensions you trust, as with any other
package.**

The security boundary stays on the server: the server authorizes all data and actions (policies,
`canSee()`, `indexQuery()`/`detailQuery()` scoping, validation). Extension JavaScript cannot bypass these
checks any more than the user can in DevTools. Hence the main rule for authors: **every extension route
that works with a record authorizes it on the server** with [`Larsadmin::findRecord()`](#findrecord).

Only users who pass the panel gate (`viewLarsadmin`) receive extension code. Guests and the login page load
no extension file.

What else the panel does:

- when serving an extension file it checks the **resolved** path (`realpath`), not only the requested one,
  so alternative names (for example Windows 8.3 short names such as `VITE~1`) do not open dotfiles or files
  outside the allowed ones;
- the scaffold commands (`larsadmin:extension`, `:field`, `:card`, `:tool`) refuse to write when the path
  below the application root contains any symbolic link or junction (dangling ones included);
- `useJsonRequest()` adds the CSRF token to same-origin requests only.

When an extension has to copy internal security code of the panel (as the medialibrary spike copies the
file checks into `Support/FileSafety.php`, because `FileTypes`/`FileResponse` are internal), cover the copy
with **parity tests** against the original, so it does not drift after a panel update. Example:
`packages/larsadmin-medialibrary/tests/Feature/FileSafetyParityTest.php`.

## Quick start

An artisan command creates the package skeleton with a working example field and card:

```bash
php artisan larsadmin:extension acme/larsadmin-rating
```

The command writes the package to `{--path}/{vendor}/{package}`, i.e. `packages/acme/larsadmin-rating/`,
and prints the next steps:

```bash
composer config repositories.acme-larsadmin-rating path packages/acme/larsadmin-rating
composer require acme/larsadmin-rating:@dev
cd packages/acme/larsadmin-rating && npm install && npm run build
```

The skeleton's provider registers the extension only when `dist/` exists, so after the build it is registered. Add the example field to an entity and the card to a
dashboard:

```php
use Acme\LarsadminRating\Cards\ExampleCard;
use Acme\LarsadminRating\Fields\ExampleField;

ExampleField::make('Example field', 'example_field'), // in an entity's fields()
ExampleCard::make(),                                  // in a dashboard's or an entity's cards()
```

Done: the form shows the extension field and the dashboard shows the card with data from its endpoint.

Command options:

| Option | Default | Purpose |
| --- | --- | --- |
| `--path=` | `packages` | directory relative to the application root; the package goes to `PATH/VENDOR/PACKAGE` |
| `--field=` | `ExampleField` | class of the example field; key `{vendor}/example-field` |
| `--card=` | `ExampleCard` | class of the example `DataCard`; key `{vendor}/example-card` |
| `--force` | | overwrite existing files |

The `larsadmin:field`, `larsadmin:card` and `larsadmin:tool` commands add single pieces, with the
`--extension=` option (the package directory relative to the application root):

```bash
php artisan larsadmin:field Rating --extension=packages/acme/larsadmin-rating
php artisan larsadmin:card TopRated --data --extension=packages/acme/larsadmin-rating
php artisan larsadmin:tool Backups --extension=packages/acme/larsadmin-rating
```

| Command | Creates |
| --- | --- |
| `larsadmin:field {name}` | `src/Fields/{name}.php`, `resources/js/fields/{name}Input.vue`, `{name}Display.vue` |
| `larsadmin:card {name} [--data]` | `src/Cards/{name}.php` (`Card`, with `--data` a `DataCard`), `resources/js/cards/{name}Card.vue` |
| `larsadmin:tool {name}` | `src/Tools/{name}.php`, `resources/js/tools/{name}Page.vue` |

The component key comes from the package vendor: `acme/rating`, `acme/top-rated`, `acme/backups`. The
commands **do not edit** `extension.js`; they print the lines for `defineExtension()` (and, for a tool, the
`Larsadmin::tools([...])` line for the provider). Without `--extension` the class goes to
`app/Larsadmin/{Fields,Cards,Tools}`, the Vue files to `resources/larsadmin/js/...`, and the key looks like
`app/rating` (see [An extension inside the application](#an-extension-inside-the-application)).

> **Developing against a panel branch.** The skeleton requires `maxcxam/larsadmin: ^1.5`. When the panel is
> installed from a branch or a path repository, Composer sees it as `dev-…`: the `main` branch has the
> `branch-alias` `dev-main → 1.x-dev`, and for a path repository set
> `"options": {"versions": {"maxcxam/larsadmin": "1.5.x-dev"}}`. The command warns about this and suggests
> `composer require … :@dev -W`.

## Package layout

```
packages/acme/larsadmin-rating/
├── composer.json            # require maxcxam/larsadmin ^1.5; dev: orchestra/testbench, pestphp/pest
├── package.json             # dev: vite, @vitejs/plugin-vue, vue, @inertiajs/vue3, tailwindcss,
│                            #      @tailwindcss/vite, vitest, jsdom, @vue/test-utils
├── vite.config.js           # plugins: [vue(), tailwindcss(), larsadmin()]
├── src/
│   ├── RatingServiceProvider.php
│   ├── Fields/ExampleField.php
│   └── Cards/ExampleCard.php
├── resources/
│   ├── js/
│   │   ├── extension.js                 # export default defineExtension({...})
│   │   ├── fields/ExampleFieldInput.vue, ExampleFieldDisplay.vue, ExampleFieldInput.test.js
│   │   └── cards/ExampleCard.vue
│   └── css/extension.css                # Tailwind utilities + kit/theme.css
├── lang/{en,uk}/ui.php
├── tests/                               # Pest.php, TestCase.php, Fixtures/, FieldTest, CardTest, HttpTest
├── dist/                                # output of npm run build; committed
└── README.md
```

The skeleton's `vite.config.js` looks for the panel kit (`kit/vite.js`, `kit/sdk.js`, `kit/testing.js`,
`kit/theme.css`) first in the package's own `vendor/` (after `composer install` in its directory), then in
the application's `vendor/`, and sets the `larsadmin-kit` alias to the directory it found. The kit ships in
the panel's Composer archive (`vendor/maxcxam/larsadmin/kit`); there is no separate npm package.

`dist/` is committed, like the panel's own `dist/`: a host that installs the extension never runs npm.

## Registration: `Larsadmin::extension()`

An extension registers itself with one call in its provider's `boot()`:

```php
namespace Acme\LarsadminRating;

use Illuminate\Support\ServiceProvider;
use Maxcxam\Larsadmin\Larsadmin;

class RatingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'acme-rating');

        Larsadmin::extension('acme/larsadmin-rating', __DIR__.'/../dist')
            ->translations('acme-rating');
    }
}
```

`Larsadmin::extension(string $name, string $path): Extension` takes the Composer package name and the
**absolute** path of the directory with the built files. By default the panel loads `dist/extension.js` and,
when it exists, `dist/extension.css`. The call throws `InvalidArgumentException` when:

- `$name` is not a valid Composer package name (`vendor/package`);
- `$path` is empty, relative, missing, or is itself a symbolic link (a junction on Windows);
- the name is already registered with another directory.

Calling it again with the same name and directory returns the same object (providers may boot twice under
Octane and in tests). There is no separate "disable extension" switch: registering is enabling, and an
extension is removed through Composer.

Fluent methods of `Maxcxam\Larsadmin\Extensions\Extension`:

| Method | Purpose |
| --- | --- |
| `script(string $file = 'extension.js')` | entry module relative to `$path` |
| `style(?string $file = 'extension.css')` | stylesheet; `null` disables it, a missing file is skipped |
| `translations(string $namespace, string $group = 'ui')` | expose `trans("{$namespace}::{$group}")` to JavaScript (see [Translations](#translations)) |
| `version(): string` | 12 chars of the md5 of `.vite/manifest.json` (or of the entry script and stylesheet); part of the file URLs |
| `url(string $file): string` | versioned URL of a file in `$path` |
| `$name`, `$path` | name and `realpath` of the directory (readonly) |

There are also `Larsadmin::registeredExtensions()` (all of them, in registration order) and
`Larsadmin::extensionFor($name)`.

The files are served by the panel route `GET {path}/extensions/{vendor}/{package}/{version}/{file}` straight
from the registered directory, without publishing to `public/`. The version is in the path, so relative
imports of the extension's chunks get it too, and the browser caches the files for a year (`immutable`).
When the version changes (`composer update`), so does the panel's Inertia version: open tabs reload on their
next navigation. Details in [HTTP endpoints](#http-endpoints).

### An extension inside the application

The same mechanism works without a package. The application registers a directory in its
`app/Providers/LarsadminServiceProvider.php`:

```php
public function boot(): void
{
    parent::boot();

    Larsadmin::extension('app/admin', resource_path('larsadmin/dist'));
}
```

and builds `resources/larsadmin/js/extension.js` with a separate Vite config and the same preset:

```js
// vite.larsadmin.config.js; build: npx vite build -c vite.larsadmin.config.js
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import larsadmin from './vendor/maxcxam/larsadmin/kit/vite.js'

export default defineConfig({
    plugins: [vue(), larsadmin({ entry: 'resources/larsadmin/js/extension.js', outDir: 'resources/larsadmin/dist' })],
})
```

`larsadmin:field`, `larsadmin:card` and `larsadmin:tool` without `--extension` create their files here, with
`app/…` keys. The `name` in `defineExtension()` must be `'app/admin'`.

## `defineExtension()` and `requires`

The extension's entry module has a **default export** with the result of `defineExtension()`:

```js
import { defineExtension } from 'larsadmin'
import '../css/extension.css'
import RatingInput from './fields/RatingInput.vue'
import RatingDisplay from './fields/RatingDisplay.vue'
import TopRatedCard from './cards/TopRatedCard.vue'
import StarsFilter from './filters/StarsFilter.vue'
import BackupsPage from './tools/BackupsPage.vue'

export default defineExtension({
    name: 'acme/larsadmin-rating', // the same as in Larsadmin::extension()
    requires: '^1.5',              // range of the panel API
    fields: {
        'acme/rating': { form: RatingInput, display: RatingDisplay },
    },
    cards: { 'acme/top-rated': TopRatedCard },
    filters: { 'acme/stars': StarsFilter },
    tools: { 'acme/backups': BackupsPage },
    setup({ app }) {
        // optional: app.component(), app.directive(), Vue plugins
    },
})
```

- `defineExtension()` only checks the shape of the object (error: a `TypeError` with the extension name)
  and marks it. The panel's loader registers the components.
- `name` must equal the name given to `Larsadmin::extension()`, or the extension is not loaded.
- `requires` supports only the `^MAJOR.MINOR` form. It is satisfied when the major equals the panel's
  `apiVersion` and the panel's minor is not lower. `'^1.5'` works with API `1.5`, `1.6`, … and not with
  `2.0`. An incompatible extension is not loaded and its fields [fail closed](#unavailable-and-crashed-extensions).
- Field parts: `form` (create and update forms), `display` (index and detail), optional `index` and
  `detail` (replace `display` in their context) and `panel` (a separate block on the detail page, see
  [Display](#vue-display)).
- `setup({ app })` runs once, when the Vue app exists. An error in `setup` marks the extension as not
  loaded and removes all its keys.
- Components can be loaded lazily: `defineAsyncComponent(() => import('./Heavy.vue'))`. The extension's
  chunks load relative to its URL.
- A key without `/` or taken by another extension is rejected with `console.error`; the rest of the
  extension works. The first registration of a key wins.
- A built-in key without `override: true` is rejected with `console.warn` (see [Overrides](#overriding-built-in-components)).

Without a build step an extension can only use render functions (`h()`): the panel ships Vue without the
template compiler.

## Custom fields

### The PHP class

A custom field is a subclass of `Field` (or `Text`, `Number`, …) with an extension key in `$component`.
A working example from the sandbox demo extension:

```php
namespace Acme\LarsadminRating\Fields;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;

class Rating extends Field
{
    public string $component = 'acme/rating';

    protected int $max = 5;

    public function max(int $max): static
    {
        $this->max = $max;

        return $this;
    }

    protected function defaultAlign(): string
    {
        return 'center';
    }

    protected function typeRules(): array
    {
        return ['integer', 'between:1,'.$this->max];
    }

    protected function resolveValue(mixed $value): mixed
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function fillValue(Request $request): mixed
    {
        $value = $request->input($this->attribute);

        return is_numeric($value) ? (int) $value : null;
    }

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'max' => $this->max];
    }
}
```

```php
Rating::make('Rating', 'rating')->max(5)->nullable()->sortable(),
```

Stable members of `Field` for subclasses:

| Member | Purpose |
| --- | --- |
| `public string $component` | key of the Vue component; for extensions always `vendor/name` |
| `jsonSerialize(): array` | `[...parent::jsonSerialize(), 'max' => …]`; subclass keys win over the base ones |
| `withMeta(array $meta)` | arbitrary props without overriding `jsonSerialize()` |
| `$multipart`, `multipart(bool $multipart = true)` | the field uploads files (see [below](#fields-that-upload-files)) |
| `resolveValue()`, `formatForDisplay()`, `fillValue()`, `fillString()`, `typeRules()`, `implicitRules()`, `acceptsNullInput()`, `defaultAlign()` | protected hooks |
| `fill(Request, Model): ?Closure` | filling; the returned closure runs after `save()` (for related data) |
| `fillInto(Request, Model, FileJournal): ?Closure` | filling with the save's file journal |
| `fillAttribute()`, `resolve()`, `eagerLoads()`, `applySort()` | public hooks |
| `bindTo(Request, string $entity)` | the field learns its entity |
| `hasAuditValue()`, `auditValue(Model)` | auditing a field without a column |
| `__construct(string $name, ?string $attribute = null, ?callable $resolveCallback = null)`, `resolveDefault()`, `rulesFor()`, `validationAttributeNames()`, `isRequired()`, `sortable()` | hooks you may override (for example, a field without a column refuses `sortable()`) |

Only the members in this table may be overridden. Overriding anything else (`isShownOn()`,
`isReadonlyFor()`, `serialize()`, …) is not API, and the [public API check](#checking-the-public-api) fails
on it.

Serialized keys of the base field: `component`, `name`, `attribute`, `value`, `displayedAs`, `sortable`,
`nullable`, `readonly`, `required`, `help`, `placeholder`, `align`, `wrap`, `truncate`, `fullWidth` and
`multipart` (only when `true`). Minor versions may add keys, so name your own keys so they will not clash
with future ones (for example `ratingMax`), and ignore unknown keys in your components.

Rules, visibility (`showOnIndex()`, `canSee()`, …), `readonly()`, `sensitive()` and the other methods work
as for built-in fields ([Fields](03-fields.md)).

### Vue: form

The form component renders **only the control**. The panel renders the label, the "required" marker, the
help text and the error message; it also owns the form state, the dirty state, resetting and submitting, so
the component never submits the form itself.

| Prop / event | Type | Content |
| --- | --- | --- |
| `field` | Object | the serialized field: `attribute`, `name`, `value`, `readonly`, `required`, `placeholder`, `help`, own keys |
| `modelValue` / `update:modelValue` | any | the value in the form state; initially `field.value`. Do not mutate `field` |
| `invalid` | Boolean | the field has a validation error |
| `errors` | Array\<string\> | messages for `attribute` and its nested keys `attribute.*` |
| `errorsByKey` | Object | the same messages by key: `{ 'gallery': '…', 'gallery.upload.1': '…' }` |
| `describedBy` | String \| null | id of the error or help element, for `aria-describedby` |
| `context` | `'create'` \| `'update'` | form context |
| `resourceId` | String \| Number \| null | the record key when updating |

Requirements: the focusable root element has `:id="field.attribute"` (the label's `for` points at it) and
honours `field.readonly`.

```vue
<script setup>
defineProps({
    field: { type: Object, required: true },
    modelValue: { default: null },
    invalid: { type: Boolean, default: false },
    errors: { type: Array, default: () => [] },
    describedBy: { type: String, default: null },
    context: { type: String, default: 'update' },
    resourceId: { type: [String, Number], default: null },
})

defineEmits(['update:modelValue'])
</script>

<template>
    <input
        :id="field.attribute"
        type="text"
        class="larsadmin-input"
        :class="{ 'larsadmin-input-invalid': invalid }"
        :value="modelValue ?? ''"
        :placeholder="field.placeholder ?? undefined"
        :readonly="field.readonly"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="describedBy ?? undefined"
        @input="$emit('update:modelValue', $event.target.value)"
    />
</template>
```

The server validates the value as always (the field's rules), so client-side checks are for convenience only.

### Vue: display

The `display`, `index` and `detail` components receive:

| Prop | Content |
| --- | --- |
| `field` | the serialized field with `value` and `displayedAs` (in the table `displayedAs` is already cut to `truncate`) |
| `context` | `'index'` \| `'detail'` |

`index` and `detail` are optional: without them `display` is used in both contexts.

The `panel` part renders a separate block below the detail fields for a field serialized with `panel: true`
(for example `->withMeta(['panel' => true])`). The component receives `field`, `entity` (`{ uriKey }`) and
`record` (`{ id }`), like the built-in relation panel.

### Fields that upload files

A field that uploads files turns on `multipart`:

```php
class Gallery extends Field
{
    public string $component = 'acme/gallery';

    public bool $multipart = true; // or ->multipart() on an instance
}
```

The field is serialized with `multipart: true`, and the form is sent as `multipart/form-data` (updates as
`POST` with `_method=PUT`), as a form with a `File` field is. The field's value is sent as it is: Inertia
turns objects holding `File`s into `FormData` entries. So the component emits, for example,
`{ present: 1, upload: [file1, file2] }`, and the server reads `$request->file("{$attribute}.upload")`.

The multipart limitation: an empty array is not sent at all. If your field's value is an array, send a
presence flag with it (like `present` above) or treat a missing key as an empty array (see
[Extending](10-extending.md#custom-array-fields-and-forms-with-files)).

`fileField: true` is an internal key that `File`, `Image` and their subclasses send: it makes the form turn
the value into the built-in file wire format (`{ present, known, keep, upload }`). Your own fields must not
set it. An extension field whose value merely looks like that format is sent unchanged.

Errors of nested keys (`gallery.upload.1`) arrive in `errors` and `errorsByKey`; the panel shows the first
one below the field.

### Save hooks: `fillInto()` and `FileJournal`

Disks are not transactional. So that the files a field wrote do not stay behind after a failed save,
override `fillInto()` instead of `fill()` and register the files in the save's journal:

```php
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Files\FileJournal;

public function fillInto(Request $request, Model $record, FileJournal $journal): ?Closure
{
    $uploads = $request->file("{$this->attribute}.upload", []);

    return function (Model $record) use ($uploads, $journal) {
        foreach ($uploads as $file) {
            $path = $file->store("gallery/{$record->getKey()}", 'public');

            // Deleted when the save fails or rolls back.
            $journal->created('public', $path);
        }

        foreach ($record->galleryImages()->whereIn('id', $this->removedIds())->get() as $image) {
            $image->delete();

            // Deleted only after the outermost commit.
            $journal->obsolete('public', $image->path);
        }
    };
}
```

- `Entity::save()` calls `fillInto()` for every field. By default `fillInto()` calls `fill()`, so existing
  fields work as before.
- `$journal->created(string $disk, string $path)`: a file this save wrote. It is deleted when the save
  fails, also when the error happened in the deferred closure.
- `$journal->obsolete(string $disk, string $path)`: a file this save made obsolete. It is deleted after
  the commit.
- The rest of `FileJournal` (construction, `discardCreated()`, purging) is internal.
- The journal exists in form saves only. Extension routes manage their files themselves.

Full example: the `Media` field in `packages/larsadmin-medialibrary` (the medialibrary spike) registers
media files and the files of removed media through the journal.

### Audit: `hasAuditValue()` and `auditValue()`

The audit log records model column values. A field without a column (media, tags in another table, …) gets
audited when it returns its value itself:

```php
public function hasAuditValue(): bool
{
    return true;
}

public function auditValue(Model $record): mixed
{
    // A fresh query, not a cached relation: the value is read before and after the save.
    return $record->tags()->orderBy('name')->pluck('name')->all();
}
```

`auditValue()` must return a value `json_encode` accepts and be cheap: it runs twice on every save with the
audit log enabled. `sensitive()` masks it as usual. Built-in fields do not override these hooks, so their
audit is unchanged. Changes made in extension routes are audited with [`Larsadmin::audited()`](#audited).

### Binding to the entity: `bindTo()`

`bindTo(Request $request, string $entity)` is called once, when the entity builds its fields. A field that
needs its entity (to build extension route URLs or check policies) stores it:

```php
protected ?string $entity = null;

public function bindTo(Request $request, string $entity): void
{
    $this->entity = $entity; // class-string<Entity>
}
```

Fields do not receive per-record `view`/`update` rights yet (see [Limitations](#limitations-and-plans-for-16)).

## Cards

### A static card

A static card has no server data, only props:

```php
namespace Acme\LarsadminRating\Cards;

use Maxcxam\Larsadmin\Metrics\Card;

class Welcome extends Card
{
    public string $component = 'acme/welcome';

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'message' => __('acme-rating::ui.welcome')];
    }
}
```

```php
Welcome::make()->width('1/2')->help('…')->withMeta(['links' => [...]]),
```

Stable members of `Card`: `$component`, `$width`, `$meta`, `name()`, `uriKey()`, `width()`, `help()`,
`canSee()`, `withMeta(array $meta)` (adds keys after the base ones; a base key `component`, `uriKey`, `name`,
`width`, `helpText` or `endpoint` throws `InvalidArgumentException`) and `jsonSerialize()`. In Vue the card
receives the `card` prop with `component`, `uriKey`, `name`, `width`, `helpText`, `endpoint` and its own
keys. Cards are registered in a dashboard's or an entity's `cards()`, like metrics ([Dashboards](14-dashboards.md)).

```vue
<script setup>
import { CardShell } from 'larsadmin'

defineProps({ card: { type: Object, required: true } })
</script>

<template>
    <CardShell :card="card">
        <p class="px-4 pb-4 text-sm">{{ card.message }}</p>
    </CardShell>
</template>
```

### `DataCard`

`Maxcxam\Larsadmin\Metrics\DataCard` loads its data from the card's own JSON endpoint. It is the same
endpoint and the same authorization as for metrics: the panel gate, the dashboard's visibility (or the
entity's `viewAny` for cards above a list), the card's `canSee()`, the `larsadmin-metrics` limiter.

```php
namespace Acme\LarsadminRating\Cards;

use App\Larsadmin\Post;
use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;

class TopRated extends DataCard
{
    public string $component = 'acme/top-rated';

    public string $width = '1/2';

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function data(CardRequest $request): mixed
    {
        return [
            'items' => $this->entityQuery($request, Post::class, fn ($query) => $query->whereNotNull('rating'))
                ->orderByDesc('rating')
                ->limit((int) $request->get('limit', 5))
                ->get(['id', 'title', 'rating'])
                ->all(),
        ];
    }
}
```

| Method | Purpose |
| --- | --- |
| `rules(): array` | validation rules of the query parameters; invalid ones answer 422, **every other parameter is dropped** |
| `data(CardRequest $request): mixed` | the card's data, `json_encode`-able |
| `cacheFor()` | cache lifetime (`int` seconds, `DateInterval`, `DateTimeInterface`); `null` (the default) disables it |
| `cacheKeyParts(CardRequest $request): array` | extra parts of the cache key |
| `entityQuery(CardRequest $request, string $entity, ?Closure $constraint = null): Builder` | protected: a scoped entity query |

The endpoint answers `200 {"data": …}`. Do not read the raw `$request->request->query()` in `data()`: the
validated parameters are in `CardRequest` only.

`Maxcxam\Larsadmin\Metrics\CardRequest` (readonly):

| Member | Content |
| --- | --- |
| `$request` | the current `Illuminate\Http\Request` |
| `$parameters` | only the validated keys of `rules()` |
| `$timezone` | the panel timezone |
| `$now` | the current moment (`CarbonImmutable`) in that timezone |
| `$owner` | `dashboard:{key}` or `entity:{key}` |
| `user()` | the user of the panel guard |
| `get(string $key, mixed $default = null)` | a validated parameter (dot notation) |

The panel builds the `CardRequest` (its constructor is internal); in tests use `cardData()`.
`DataCard::cardRequest()`, `resolve()` and `cacheKey()` are internal, and `resolve()` and `cacheKey()` are
also `final`: do not give your card's own methods these names.

### `entityQuery()`

`entityQuery()` is the data card counterpart of `Metric::scoped()`: it returns the entity's query limited
by its `indexQuery()`, after checking `viewAny`. Without `viewAny` it throws `AuthorizationException` and the
endpoint answers 403. `$entity` takes only a **registered** entity, as its class or `uriKey`; anything
else throws `InvalidArgumentException`. The
`$constraint` is wrapped in its own `where` group, so an `orWhere` inside it cannot escape the scope.

A `data()` that does not use `entityQuery()` sees everything its query can read: use a model or a query
builder for global data only, and hide such a card with `canSee()`.

### Caching

```php
public function cacheFor(): int
{
    return 300;
}

public function cacheKeyParts(CardRequest $request): array
{
    return [$request->user()?->current_team_id];
}
```

The cache key holds the owner (dashboard or entity), the card class and `uriKey`, the validated parameters,
timezone, locale, guard and the **user** (class + id), the result of `Larsadmin::metricCacheScopeUsing()` and
`cacheKeyParts()`. Store: `larsadmin.metrics.cache_store`.

On a cache hit `data()` is not called; the owner's authorization (dashboard or the entity's `viewAny`),
the card's `canSee()` and the `viewAny` of every entity the data was read from through `entityQuery()`
run again: a user whose right was revoked gets 403 on cached data too. The card knows no other sources
(a model, a query builder, an external API). So:

- when `data()` depends on anything besides the user and the parameters (tenant, team, role, session),
  return it from `cacheKeyParts()` or the global `metricCacheScopeUsing()`, or a user is served another
  scope's data;
- access checks inside `data()` do not run on cached data: move them to `canSee()`.

The `metricCacheScopeUsing()` callback receives the card as its second argument. When that parameter is
typed as `Metric` only, the callback cannot receive the card, and such a card is **never cached** (fail
closed). Type the parameter as `Metric|DataCard`, `Card` or `mixed` to cache data cards.

### Vue: `useCardData()` and `CardShell`

```vue
<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { CardShell, useCardData, useI18n, useLarsadmin } from 'larsadmin'

const props = defineProps({ card: { type: Object, required: true } })

const { t } = useI18n()
const { entityUrl } = useLarsadmin()
const { status, data, reload } = useCardData(() => props.card, { limit: 5 })

const items = computed(() => data.value?.items ?? [])
</script>

<template>
    <CardShell :card="card" :status="status" :ready="data !== null" :empty="items.length === 0" @retry="reload()">
        <ol class="divide-y divide-muted px-4 pb-4 text-sm">
            <li v-for="item in items" :key="item.id" class="py-2">
                <Link :href="entityUrl('posts', item.id)" class="text-accent-fg hover:underline">{{ item.title }}</Link>
            </li>
        </ol>
    </CardShell>
</template>
```

`useCardData(card, params = {})` sends `GET card.endpoint` with `params` in the query and returns
`{ status, data, reload }`. `card` and `params` can be a value, a ref or a getter; a ref or getter `params` is
watched and the data reloads when it changes. `status` is `idle`, `loading`, `error` or `forbidden`; `data`
keeps the last good value while reloading and becomes `null` after an error.

`CardShell` is the card frame the built-in metrics use: the title, the `help()` tooltip, the skeleton, the
"no access" and "error" states with a "Retry" button (the `retry` event).

| Prop / slot | Content |
| --- | --- |
| `card` | the serialized card (required) |
| `status` | the status from `useCardData()` / `useJsonResource()` |
| `ready` | there is something to show (while `false` during the first load, the skeleton is shown) |
| `empty` | show the "no data" state instead of the slot |
| `skeletonClass` | class of the skeleton, `h-24` by default |
| slots `default`, `actions` | the content and header controls (for example a range picker) |

## Filters

An extension filter is a regular `Filter` subclass ([Filters](06-filters.md)) with an extension key in
`$component`; the server logic (`apply()`, `normalize()`, `options()`) does not change.

```php
class StarsFilter extends Filter
{
    public string $component = 'acme/stars';
    // apply(), normalize(), options() as in any filter
}
```

The Vue component receives `filter` (`key`, `label`, `component`, `options` for filters with options,
`value`, `default`) and `modelValue` / `update:modelValue`. Add your own props with the stable hook
`serialize(Request $request, mixed $value): array`; the result must keep every base key of
`parent::serialize()`:

```php
public function serialize(Request $request, mixed $value): array
{
    return [...parent::serialize($request, $value), 'max' => 5];
}
```
 The value goes into the query string, so it is a
scalar or a flat object of strings. The server normalizes it with `normalize()`.

## Tools: sidebar pages

A tool is a page in the sidebar ("Tools" section) with its own Vue component.

```php
namespace Acme\LarsadminRating\Tools;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Tools\Tool;

class Backups extends Tool
{
    public string $component = 'acme/backups';

    public function icon(): ?string
    {
        return 'database';
    }

    public function props(Request $request): array
    {
        return ['lastRun' => cache('backups.last_run')];
    }
}
```

```php
// boot() of the extension's or the application's provider
Larsadmin::tools([
    Backups::class,
    // Backups::make()->canSee(fn (Request $request) => $request->user()->isAdmin()),
]);
```

| Member | Purpose |
| --- | --- |
| `$component` | key of the extension component, always `vendor/name` |
| `name()` | menu label and page title; by default `__()` of the class name |
| `uriKey()` | key in the URL; by default the kebab-cased class name |
| `icon()` | menu icon from `Tool::ICONS`; `null` or an unknown name gives the default icon |
| `canSee(Closure)` / `authorizedToSee(Request)` | visibility; without `canSee()` everyone past the panel gate sees the tool |
| `props(Request $request): array` | page props (JSON) |
| `make(...$args)` | create an instance |

The page: `GET {path}/tools/{uriKey}`. An unknown or invisible tool answers 404 (not 403, so its existence
is not revealed). `Larsadmin::tools()` throws `InvalidArgumentException` for anything that is not a `Tool`,
for a key without `/`, and for two classes with the same `uriKey`. There are also
`Larsadmin::registeredTools()` and `Larsadmin::toolForKey($key)` (visibility is not checked).

The Vue component receives `tool` (`name`, `uriKey`, `component`) and `props` (from `props()`); the panel
renders it in its layout under the `tool.name` title. Data that is not in `props()` comes from
[extension routes](#extension-routes).

```vue
<script setup>
defineProps({
    tool: { type: Object, required: true },
    props: { type: Object, default: () => ({}) },
})
</script>

<template>
    <div class="larsadmin-box p-4">{{ props.lastRun }}</div>
</template>
```

Icons (`Tool::ICONS`, in JS `Icon.NAMES`): `alert`, `arrow-down`, `arrow-up`, `bell`, `check`,
`chevron-down`, `chevron-left`, `chevron-right`, `dash`, `database`, `device-desktop`, `download`, `eye`,
`file`, `globe`, `history`, `home`, `kebab`, `lock`, `menu`, `moon`, `pencil`, `plus`, `search`,
`sign-out`, `sun`, `trash`, `upload`, `x`.

## Extension routes

Register your own JSON routes with `Larsadmin::routes()` in the provider:

```php
use Acme\LarsadminRating\Http\ResetRatingController;
use Illuminate\Support\Facades\Route;
use Maxcxam\Larsadmin\Larsadmin;

Larsadmin::routes(function () {
    Route::post('acme-rating/{entity}/{id}/reset', ResetRatingController::class)->name('acme-rating.reset');
});
```

The routes go into the `{path}/ext` group, named `larsadmin.ext.…`, behind the panel middleware
(`larsadmin.middleware` + `larsadmin.auth_middleware`): `POST /admin/ext/acme-rating/posts/1/reset` named
`larsadmin.ext.acme-rating.reset`. Mutating routes get CSRF protection from the `web` group. The same
`Closure` object is registered once. With `route:cache` the closures run while caching, like any package
routes.

**`routes()` guarantees only the panel gate.** The route does the rest of the authorization (policy,
scoping, field visibility) itself, with the helpers below.

```php
namespace Acme\LarsadminRating\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;

class ResetRatingController
{
    public function __invoke(Request $request, string $entity, string $id): JsonResponse
    {
        $record = Larsadmin::findRecord($request, $entity, $id, 'update');
        $field = Larsadmin::findField($request, $entity, $record, 'rating');

        Larsadmin::audited($request, $entity, $record, function (Model $record) use ($field) {
            $record->forceFill([$field->attribute => null])->save();
        });

        return response()->json(['ok' => true]);
    }
}
```

From Vue such a route is called through the SDK:

```js
import { useJsonRequest, useLarsadmin } from 'larsadmin'

const { url } = useLarsadmin()
const { send } = useJsonRequest()

const { ok, status, data } = await send('POST', url('ext', 'acme-rating', 'posts', 1, 'reset'))
```

### `findRecord()`

```php
Larsadmin::findRecord(Request $request, string $entity, int|string $id, string $ability = 'view'): Model
```

Finds a record the way the panel does: the entity's `viewAny` (403), the `detailQuery()` scope (404 for
rows outside it), then the policy ability on the record (403). `$entity` is the entity's `uriKey`; an
unknown one is a 404. `$ability`: `view`, `update` or `delete` (anything else throws
`InvalidArgumentException`). Call it in **every** route that works with a record.

### `findField()`

```php
Larsadmin::findField(Request $request, string $entity, Model $record, string $attribute, string $context = Field::UPDATE): Field
```

Returns the entity's field for the record as the panel sees it: bound (`bindTo()`), shown in `$context` to
this user for this record (404 otherwise) and, for `create`/`update`, not `readonly` (403 otherwise). It
returns a clone, so you may `resolve()` it. Call it after `findRecord()`. `$context`: `Field::INDEX`,
`DETAIL`, `CREATE` or `UPDATE`.

### `audited()`

```php
Larsadmin::audited(Request $request, string $entity, Model $record, Closure $change): mixed
```

Runs a change to a record outside the form and records it in the audit log as `updated`: a snapshot of the
entity's fields before, `$change($record)`, a snapshot after, the diff, masking as usual. All in one
transaction; returns the closure's result. There is no entry when nothing changed, the audit log is off or
the entity is not audited. Fields with `hasAuditValue()` are part of the diff too. A record that is not a
model of that entity throws `InvalidArgumentException`.

## Translations

The extension has `lang/{locale}/ui.php`:

```php
// lang/en/ui.php
return [
    'stars' => ':count star|:count stars',
    'not_rated' => 'Not rated',
];
```

The provider registers the files the standard way and exposes the group to JavaScript:

```php
$this->loadTranslationsFrom(__DIR__.'/../lang', 'acme-rating');

Larsadmin::extension('acme/larsadmin-rating', __DIR__.'/../dist')
    ->translations('acme-rating'); // the 'ui' group by default
```

```js
const { t, tc, locale } = useI18n()

t('acme-rating::not_rated')
tc('acme-rating::stars', 3) // "3 stars"
```

In JavaScript a key looks like `namespace::key` (the group is not written); nested arrays give
`namespace::group.key`. In PHP (class names, rules) translations are the usual ones:
`__('acme-rating::ui.not_rated')`. Built-in panel keys never contain `::`, so there are no collisions. The
panel locales are shared; when a locale has no translation, Laravel's fallback locale applies, and without it
`t()` returns the key. The host can override the translations in `lang/vendor/acme-rating` (standard
Laravel). The skeleton ships `uk` and `en`.

## Styles and theming

An extension builds its own Tailwind CSS v4 utilities with the panel's theme:

```css
/* resources/css/extension.css */
@import "tailwindcss/utilities";
@import "larsadmin-kit/theme.css";
@source "../js";
```

`kit/theme.css` imports Tailwind's default theme as a *reference*: classes such as `p-4`, `size-6`,
`text-sm`, `rounded`, `md:grid-cols-2` are generated, but the theme variables are not duplicated; they come
from the panel. Colours are mapped onto the `--la-*` tokens, so they follow the light and dark themes:

| Utility | Token |
| --- | --- |
| `text-fg`, `text-fg-muted`, `text-fg-subtle`, `text-fg-on-emphasis` | `--la-fg-*` |
| `bg-canvas`, `bg-canvas-subtle`, `bg-canvas-inset` | `--la-canvas-*` |
| `border-default`, `border-muted` | `--la-border-*` |
| `*-accent-fg`, `*-accent-emphasis`, `*-accent-subtle` | `--la-accent-*` |
| `*-success-*`, `*-danger-*` (`fg`, `emphasis`, `subtle`) | `--la-success-*`, `--la-danger-*` |
| `bg-neutral-muted`, `bg-header`, `text-header-fg`, `bg-overlay`, `shadow-overlay` | other tokens |

The stable CSS contract: the `--la-*` tokens ([Theming](09-theming.md#design-tokens)) and the classes
`larsadmin-input`, `larsadmin-input-invalid`, `larsadmin-checkbox`, `larsadmin-btn`,
`larsadmin-btn-primary`, `larsadmin-btn-secondary`, `larsadmin-btn-danger`, `larsadmin-btn-sm`,
`larsadmin-box`, `larsadmin-box-header`, `larsadmin-label`, `larsadmin-label-success`,
`larsadmin-label-accent`, `larsadmin-counter`. The utility classes in the panel's `app.css` are **not** a
contract: their set depends on the panel's code, so an extension builds its own.

For charts that pick colours themselves, `useTheme().resolved` returns `'light'` or `'dark'` (the user's
choice or the OS setting); the chart palette is `--la-chart-1` … `--la-chart-8`.

## The public JS API

### The `larsadmin` module

Every export below is part of the 1.x SemVer contract (minors only add):

| Export | Description |
| --- | --- |
| `apiVersion` | the string `'1.5'` (`Larsadmin::API_VERSION`) |
| `defineExtension(options)` | the extension definition ([above](#defineextension-and-requires)) |
| `useI18n()` | `{ t, tc, locale }`; extension keys `t('acme-rating::key')` |
| `useLarsadmin()` | `{ url(...segments), entityUrl(uriKey, ...segments) }` under the panel path; segments are encoded, `.` and `..` refused |
| `useJsonRequest()` | `{ send(method, url, body?, { background }) }` → `{ ok, status, data, expired? }`; `X-XSRF-TOKEN` for mutations, to same-origin URLs only; on 401/419 a `GET` or `background: true` request returns `expired: true`, any other reloads the page |
| `useJsonResource()` | `{ status, load(url, { background }), cancel }`; cancels a stale request; `status`: `idle`, `loading`, `error`, `forbidden` |
| `useCardData(card, params?)` | `{ status, data, reload }` for a `DataCard` |
| `useToasts()` | `{ push({ message, type, title?, timeout? }), dismiss(id) }`; `type`: `success`, `info`, `warning`, `error`; links are not supported |
| `useTheme()` | `{ resolved }`: `'light'` \| `'dark'` |
| `useNumberFormat()` | `{ format(value, options) }`: `Intl.NumberFormat` options plus `prefix`/`suffix`, in the panel locale; `null` and `NaN` give "—" |
| `buildUrl(base, segments)`, `withParams(url, params)` | pure URL helpers |
| `Icon` | `<Icon name="pencil" />`; names in `Icon.NAMES` (the same as `Tool::ICONS`); decorative, the control provides the label |
| `CardShell` | the card frame ([above](#vue-usecarddata-and-cardshell)) |
| `ConfirmDialog` | an accessible confirmation dialog |

The import map specifiers `vue` (the Vue 3 runtime, `^3.5`) and `@inertiajs/vue3` (Inertia v3, the panel's
own instance) are stable too: `usePage()`, `Link` and `router` in an extension work with the panel's page.
Of the shared props only `larsadmin.locale`, `larsadmin.path` and `user` are a contract.

**Internal (not a contract):** everything else in the panel's `resources/js/**`, including `registerField`,
`registerCard`, `registerFilter`, `Combobox`, `EntityForm`, `FieldBlocks` and the rest of the shared props.
Importing panel files directly (`vendor/maxcxam/larsadmin/resources/js/...`) does not work: they are not in
the Composer archive.

### The author kit (`vendor/maxcxam/larsadmin/kit`)

| File | Exports |
| --- | --- |
| `kit/vite.js` | `larsadmin(options)` (and default): the Vite preset |
| `kit/testing.js` | `withPanel()`, `mountField()`, `mountDisplay()`, `mountCard()` |
| `kit/theme.css` | the Tailwind theme for extensions |
| `kit/sdk.js` | the `larsadmin` module built for vitest (the preset wires it in) |

## Overriding built-in components

An extension can replace the **UI** of a few built-in components with an explicit `override: true`:

```js
export default defineExtension({
    name: 'acme/larsadmin-ui',
    requires: '^1.5',
    fields: {
        // the form only; index and detail stay built-in
        'boolean-field': { form: BooleanSwitch, override: true },
    },
    cards: {
        'value-metric': { component: BigNumberCard, override: true },
    },
    filters: {
        'date-range-filter': { component: RangePicker, override: true },
    },
})
```

For fields the parts (`form`, `display`, `index`, `detail`) are overridden separately. Cards and filters use
the object form `{ component, override: true }`.

### What can be overridden

| Kind | Keys | Parts |
| --- | --- | --- |
| Fields | `text-field`, `password-field`, `textarea-field`, `number-field`, `boolean-field`, `select-field`, `date-field`, `datetime-field` | `form`, `display`, `index`, `detail` |
| Filters | `select-filter`, `boolean-filter`, `date-range-filter` | component |
| Cards | `value-metric`, `trend-metric`, `partition-metric`, `progress-metric` | component |

The other built-in keys (`id-field`, `file-field`, `image-field`, `belongs-to-field`, `has-many-field`,
`belongs-to-many-field`) and the `panel` part cannot be overridden (`console.warn`): their formats and
endpoints are internal. A built-in key without `override: true` is rejected with `console.warn` too. Only
one extension may override a part of a key: the first wins, later ones are rejected with `console.error`.

### The contract

An override receives the same props and events as the built-in component, plus the general extension
contract. All of it is stable in 1.x:

- **fields, form:** `field`, `modelValue` / `update:modelValue`, `invalid` and the [form](#vue-form)
  contract (`errors`, `errorsByKey`, `describedBy`, `context`, `resourceId`); **display:** `field`,
  `context`.
- **`field` keys:** the base ones ([above](#the-php-class)) plus `text-field` / `password-field`
  `inputType`; `textarea-field` `rows`; `number-field` `min`, `max`, `step`; `select-field` `options`
  (`[{ value, label }]`).
- **the value shape** in `update:modelValue` is the built-in one: a string (`text`, `password`,
  `textarea`), a number (`number`), `true`/`false` (`boolean`), an option value (`select`), `YYYY-MM-DD`
  (`date-field`), `YYYY-MM-DDTHH:mm:ss` (`datetime-field`, as `datetime-local`).
- **filters:** `filter`, `modelValue` / `update:modelValue`; the value is `'1'`, `'0'` or `null` for
  `boolean-filter`, `{ from, to }` (`YYYY-MM-DD` strings) or `null` for `date-range-filter`, an option value
  for `select-filter`.
- **cards:** `card` with the `Card` keys plus `ranges` (`[{ value, label }]`) and `defaultRange`. The
  endpoint answers `{ range, result }`; the shape of `result` is described in
  [Metrics](13-metrics.md#endpoint-response-format).

The internal markup, CSS classes and child components of built-in components are not a contract.

### Security and fallback

- An override changes **only the client-side rendering**. The `component` key on the server, the rules,
  `fill()`, `canSee()`, policies, scoping and the value format the server accepts do not change: the server
  validates input as always.
- While the extension is loading, the built-in component renders (no skeleton).
- When the extension did not load, is incompatible or its `setup` threw, the override does not apply.
- When an override component throws while rendering, the panel switches that key (part) back to the
  built-in component for the rest of the page session and logs the error. The field's value **is** sent,
  because the built-in component handles it.
- With `APP_DEBUG=true` a `console.info` lists "who overrode what" after loading.

## Unavailable and crashed extensions

Extension failures are isolated; the panel keeps working:

- The loader imports all extensions in parallel and waits for them for up to 5 seconds, then mounts the
  panel; late extensions render when they arrive. They register in the order of the server's list (the
  order of `Larsadmin::extension()`), not in the order their imports finish, so the winner of a key
  conflict does not depend on the network. While any is still loading, an extension component shows a
  "Loading extension…" skeleton.
- An extension is not loaded (`console.error` with the reason) when the import fails or does not finish
  within 30 seconds, the default export is not `defineExtension()`, the `name` differs from the server's,
  `requires` is incompatible or `setup()` threw.
- `ExtensionBoundary` switches an extension component off for the rest of the page session only when it
  cannot render: an error in render, `setup`, lifecycle hooks or an async component loader. Errors in event
  handlers, watchers and async code are only logged with the extension name (plus a toast with
  `APP_DEBUG=true`): the component stays, and the user's input is kept and sent.

What the user sees in place of an unavailable `vendor/name` component:

| Where | Placeholder |
| --- | --- |
| form | a box "Unavailable: the extension is not loaded" with the hint "This field's value will not be changed on save." |
| index, detail | "—" with an "Unavailable…" tooltip |
| card, tool, panel | a block with the name and "Unavailable…" |
| filter | the filter label and "Unavailable…" |

**Fail closed in forms.** A field whose extension component is unavailable (not loaded, incompatible,
crashed) **is not sent**: the server keeps the stored value, and on create `required` applies. A key without
`/` that the panel does not know still renders as a text input (1.4 compatibility).

**Debug toast.** Only with `APP_DEBUG=true` the panel user also sees a toast "Extension X was not loaded"
or "Extension X requires API ^1.6, the panel provides 1.5". In production there are only the console and the
placeholders.

## CSP and nonces

Panel and extension files are on the same origin, so `script-src 'self'` and `style-src 'self'` cover them.
The panel's inline scripts (theme detection and the import map), the `app.js` tag and the `modulepreload`
tags get a `nonce` attribute when the application turns on Laravel's standard `Vite::useCspNonce()`:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);
        $response->headers->set('Content-Security-Policy', "script-src 'self' 'nonce-".Vite::cspNonce()."'");

        return $response;
    }
}
```

The middleware must run before the panel page renders (for example in the `web` group or in
`larsadmin.middleware`). Without a nonce a strict CSP blocks the import map: the panel works and extension
fields show "Unavailable". `'strict-dynamic'` is compatible: modules imported by the trusted `app.js` are
allowed. SRI is not used (same origin, files of the same deployment). Without registered extensions there is
no import map on the page.

## Versioning and compatibility

- `Larsadmin::API_VERSION` (PHP) and `apiVersion` (JS) are `'1.5'`: the `MAJOR.MINOR` of the version that
  last extended the public API. A minor that does not change the API does not raise it.
- Composer guarantees PHP compatibility (`"maxcxam/larsadmin": "^1.5"` in the extension's
  `composer.json`). `requires` in JavaScript catches a stale `dist/`.
- **Frozen for 1.x:** Vue 3 (≥ 3.5) and Inertia v3, the import map specifiers, the component contracts on
  this page, the exports of the `larsadmin` module and of the kit, the CSS contract, the PHP members listed
  as stable. Moving to Vue 4 or Inertia v4 can happen only in 2.0.
- **Minors only add:** new component props, new SDK exports, new serialized keys. Extensions must ignore
  unknown props and keys.
- **Deprecations:** a member gets `@deprecated` (PHP) or a one-time `console.warn` with `APP_DEBUG=true`
  (JS), stays until the end of the major and is removed in the next one; the CHANGELOG has a "Deprecated"
  section with the replacement.

Stable PHP classes and members:

| Class | Stable members |
| --- | --- |
| `Larsadmin` | `API_VERSION`, `extension()`, `registeredExtensions()`, `extensionFor()`, `routes()`, `findRecord()`, `findField()`, `audited()`, `tools()`, `registeredTools()`, `toolForKey()`, `assetsVersion()` |
| `Extensions\Extension` | `$name`, `$path`, `script()`, `style()`, `translations()`, `version()`, `url()` |
| `Fields\Field` | see [the field's PHP class](#the-php-class) |
| `Files\FileJournal` | `created()`, `obsolete()` |
| `Metrics\Card` | `$component`, `$width`, `$meta`, `name()`, `uriKey()`, `width()`, `help()`, `canSee()`, `withMeta()`, `jsonSerialize()` |
| `Metrics\DataCard` | `rules()`, `data()`, `cacheFor()`, `cacheKeyParts()`, `entityQuery()` |
| `Metrics\CardRequest` | `$request`, `$parameters`, `$timezone`, `$now`, `$owner`, `user()`, `get()` |
| `Tools\Tool` | `$component`, `ICONS`, `make()`, `name()`, `uriKey()`, `icon()`, `canSee()`, `authorizedToSee()`, `props()` |
| `Filters\Filter` | `$component`, `make()`, `apply()`, `name()`, `key()`, `options()`, `default()`, `normalize()`, `canSee()`, `serialize()` |
| `Testing\InteractsWithLarsadmin` | `serializeField()`, `fillField()`, `cardData()`, `assertExtensionRegistered()`, `extensionAssetResponse()` |

An extension may refer to the panel's other documented classes (entities, built-in fields, filters,
metrics, actions, dashboards, `Layout\Panel`, `Layout\Tabs`, events, `LarsadminNotification`) just as an
application does. Internal members are not API, even when they are public in PHP: `Entity::fieldPrototypes()`,
`isShownOn()`, `isReadonlyFor()` (use `findField()` instead), the constructor and helper methods of
`Extension`, the `CardRequest` constructor, `DataCard::cardRequest()`/`resolve()`/`cacheKey()`,
`Extension::NAME_PATTERN`, `Tool::COMPONENT_PATTERN`, `Tool::$canSee` and anything marked `@internal`.

### Checking the public API

The full list of the stable surface is `kit/public-api.json`, and `kit/check-public-api.mjs` checks it
(Node ≥ 20, no dependencies). Both ship in the panel's Composer archive, so you check an extension from its
directory (paths are relative to the current directory):

```bash
node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .
```

The script checks two things:

- **the extension uses only the stable surface.** JS/Vue/CSS imports only `vue`, `@inertiajs/vue3`,
  third-party packages, its own files, the `larsadmin` exports and the kit files (no panel files from
  `resources/js`, no `larsadmin/...` subpaths); PHP refers only to listed `Maxcxam\Larsadmin\…` classes and
  calls no internal members;
- **the extension overrides only stable hooks.** A class extending `Field`, `Filter`, `Card` / `DataCard` or
  `Tool` (directly or through its own classes) may override only the stable members of its base class.

The extension's `tests/` directory is not checked: tests may refer to internal classes, for example in
parity tests against code copied from the panel. In the panel repository `npm run check:api` checks the
surface itself: nothing in the snapshot disappeared and nothing was added without updating the snapshot.

## Testing

### Pest (PHP)

The skeleton has a `tests/TestCase.php` on Orchestra Testbench (in-memory SQLite, Laravel's migrations, a
fixture user model, an open panel gate) and `tests/Pest.php`:

```php
pest()->extend(TestCase::class)->use(InteractsWithLarsadmin::class)->in(__DIR__);
```

The `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin` trait (also for PHPUnit) uses only the public API:

| Method | What it does |
| --- | --- |
| `serializeField(Field $field, ?Model $record = null, string $context = Field::DETAIL, ?string $entity = null): array` | serializes the field as the panel does for a page; with `$entity` (a class or `uriKey`) calls `bindTo()` first |
| `fillField(Field $field, Model $record, array $input, array $files = [], string $context = Field::UPDATE): Model` | validates the input with the field's rules (`ValidationException`), calls `fillInto()` with a file journal as the panel does, saves and runs the deferred closure in a transaction; on failure deletes the files registered with `created()` |
| `cardData(DataCard $card, array $query = [], ?Authenticatable $user = null, string $owner = 'dashboard:test'): mixed` | `canSee()` (`AuthorizationException`), `rules()` (`ValidationException`), `data()` |
| `assertExtensionRegistered(string $name)` | the extension is registered |
| `extensionAssetResponse(string $name, string $file): TestResponse` | `GET` of the file's versioned URL as the acting user |

```php
it('serializes the rating', function () {
    $post = new Post(['rating' => 4]);

    expect($this->serializeField(Rating::make('Rating', 'rating')->max(5), $post))
        ->toMatchArray(['component' => 'acme/rating', 'value' => 4, 'max' => 5]);
});

it('rejects invalid card parameters', function () {
    $this->cardData(TopRated::make(), ['limit' => 'many']);
})->throws(ValidationException::class);
```

- `Larsadmin::flushState()` resets **all** static state, including the extensions, routes and tools that
  providers registered at boot. If your tests call it, register the extension again in `setUp()` after
  `flushState()`, or do not call it in extension tests.
- The skeleton's HTTP test (`tests/HttpTest.php`) checks the card endpoint and serving `dist/extension.js`
  (skipped until `npm run build`).

### vitest (Vue)

In test mode the preset points `larsadmin` at `kit/sdk.js`, turns on `jsdom` and excludes `vendor/**` and
`dist/**`. The `kit/testing.js` helpers (through the skeleton's `larsadmin-kit` alias) mount components with
the panel contract without an Inertia app:

```js
import { describe, expect, it } from 'vitest'
import { mountDisplay, mountField } from 'larsadmin-kit/testing.js'
import RatingInput from './RatingInput.vue'
import RatingDisplay from './RatingDisplay.vue'

const translations = { 'acme-rating': { stars: ':count star|:count stars' } }

describe('RatingInput', () => {
    it('emits the chosen number', async () => {
        const wrapper = mountField(RatingInput, { field: { attribute: 'rating', value: 2, max: 5 }, panel: { translations } })

        await wrapper.findAll('input[type="radio"]')[3].setValue(true)

        expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([4])
    })
})

describe('RatingDisplay', () => {
    it('renders on the index', () => {
        const wrapper = mountDisplay(RatingDisplay, { field: { attribute: 'rating', value: 4, max: 5 }, context: 'index', panel: { translations } })

        expect(wrapper.text()).toContain('4')
    })
})
```

| Helper | What it mounts |
| --- | --- |
| `withPanel({ locale, translations, path, props })` | mount options that give the SDK a panel page (`useI18n`, `useLarsadmin`, …) |
| `mountField(Component, { field, modelValue, errors, errorsByKey, context, resourceId, panel, options })` | a form component with the contract; `v-model` is wired; `errors` turns on `invalid` and `describedBy` `{attribute}-error` |
| `mountDisplay(Component, { field, context, panel, options })` | a display component; `displayedAs` defaults to `String(value)` |
| `mountCard(Component, { card, panel, options })` | a card with the serialized `Card` keys |

`options` is passed to `@vue/test-utils`' `mount()`.

## Building and publishing

```bash
npm run build   # dist/extension.js, extension.css, chunks/, .vite/manifest.json
npm run dev     # rebuilds after every change (vite build --watch)
npm test        # vitest
vendor/bin/pest # after composer install in the package directory
```

The preset `larsadmin({ entry = 'resources/js/extension.js', outDir = 'dist' })` builds the ES library
`dist/extension.js`, all styles into one `dist/extension.css`, chunks into `dist/chunks/` and a manifest for
the version, and keeps `vue`, `@inertiajs/vue3` and `larsadmin` external. A guard **stops the build** when
the bundle contains its own copy of Vue, `@vue/*`, Inertia or the SDK (a second Vue is the sneakiest bug: it
silently breaks reactivity and `usePage()`). Import them only as `vue`, `@inertiajs/vue3` and `larsadmin`,
without deep paths or aliases.

Publishing:

1. Commit `dist/` after `npm run build`.
2. The package's `composer.json` requires `"maxcxam/larsadmin": "^1.5"` and registers the provider in
   `extra.laravel.providers` (the skeleton does both).
3. Tag a version and publish the package (Packagist or a private repository).

The host installs the package with `composer require` and does nothing else. After `composer update` the
file version changes and open tabs reload on their next navigation.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| The build fails with "contains its own copy of Vue, Inertia or the larsadmin SDK" | Vue/Inertia imported through a deep path or alias, or a dependency pulls its own Vue; import only `vue`, `@inertiajs/vue3`, `larsadmin` |
| Fields show "Unavailable", nothing about the extension in the console | the CSP blocks the import map: turn on `Vite::useCspNonce()` ([CSP](#csp-and-nonces)) |
| "Extension "…" was not loaded (the module is named …)" | the `name` in `defineExtension()` differs from `Larsadmin::extension()` |
| "… requires ^1.6, the panel API is 1.5" | `requires` is above the panel API: update the panel or lower `requires` |
| The extension's styles do not apply | no `dist/extension.css` (the CSS is not imported in `extension.js`) or the classes were not generated (`@source "../js"`) |
| A key is rejected with "use override: true" | a key without `/` matched a built-in one; use `vendor/name`, or `override: true` for an allowed key |
| `composer require acme/…:^1.5` finds no compatible panel | the panel comes from a branch or path repository: see [Quick start](#quick-start) |
| The extension disappears in tests | the test called `Larsadmin::flushState()`: register it again |
| `check-public-api.mjs`: "… overrides …, which is not a stable override hook" | the class overrides an unstable member of its base class; move the logic into a stable hook |

## HTTP endpoints

| Method | URI (under `{path}`) | Name (`larsadmin.`…) | Responses |
| --- | --- | --- | --- |
| GET | `extensions/{vendor}/{package}/{version}/{file}` | `extensions.asset` | 200 file, 304, 404 (not registered, traversal, dotfile, type) |
| GET | `api/dashboards/{dashboard}/metrics/{card}` | `api.dashboard-metric` | `DataCard`: `200 {"data": …}`; 404 invisible dashboard or card, a static card; 422 `{"message", "errors"}` |
| GET | `api/entities/{entity}/metrics/{card}` | `api.entity-metric` | as above; 403 without `viewAny` |
| GET | `tools/{tool}` | `tools.show` | 200 Inertia page `Tool`; 404 unknown or invisible |
| any | `ext/…` | `ext.…` | defined by the extension |

Serving extension files: only to users who pass the panel gate (limiter `larsadmin-files`); only files
inside the `realpath` of the registered directory; dotfiles (including `.vite/manifest.json`) are closed;
allowed types `js`, `mjs`, `css`, `json`, `woff2`, `woff`, `ttf`, `png`, `jpg`, `jpeg`, `gif`, `webp`,
`avif`, `ico`, `svg` (with `Content-Security-Policy: sandbox`) and `map` (only with
`LARSADMIN_EXTENSION_SOURCE_MAPS=true`). Headers: `X-Content-Type-Options: nosniff`,
`Cross-Origin-Resource-Policy: same-origin`, ETag and `Last-Modified`. The current version is cached as
`private, max-age={extensions.max_age}, immutable`, an old one is served with `no-cache`.

Config (`config/larsadmin.php`):

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `extensions.source_maps` | `LARSADMIN_EXTENSION_SOURCE_MAPS` | `false` | serve the extensions' `*.map` files (debugging only) |
| `extensions.max_age` | | `31536000` | browser cache lifetime of versioned files, in seconds |

Shared props (only for users past the gate): `larsadmin.extensions` (`[{ name, script, style }]`),
`larsadmin.extensionTranslations`, `larsadmin.debug`, `tools`. Without registered extensions and tools these
props are absent, and the Inertia version is the same as in 1.4.

## Limitations and plans for 1.6

- **The first load is about 27 KB gzip heavier** than in 1.4, also without extensions: Vue and Inertia live
  in shared chunks that extensions import through the import map, and they are not tree-shaken. After that
  the browser caches the files. There are no extra sequential round trips: the root view emits
  `<link rel="modulepreload">` tags (with the `nonce`) for the static imports of `app.js`, read from
  `dist/.vite/manifest.json` (no tags without a manifest), so after `app.js` the browser loads the vue,
  inertia and shared chunks in parallel.
- The panel's `FileTypes` and `FileResponse` are internal. An extension that serves files (like the
  medialibrary spike) has to keep its own copy of the safety checks, with parity tests (see
  [Trust model](#trust-model)). A public safe file responder is planned for 1.6.
- Fields do not receive per-record `view`/`update` rights. A field that needs them checks the policy itself:
  on the index that is N policy calls per page. A solution is planned for 1.6.
- Extension fields (like `File`/`Image`) are not supported in action modals; a field does not know it is in
  an action. Planned for 1.6.
- `Larsadmin::flushState()` removes the extensions providers registered at boot (see [Testing](#pest-php)).
- `FileJournal` deletes files, not directories: after a discarded upload empty directories can remain (for
  example spatie/laravel-medialibrary media directories).
- `larsadmin:extension` always writes the package to `PATH/VENDOR/PACKAGE` (`--path=sandbox/extensions`
  gives `sandbox/extensions/acme/larsadmin-rating`).
- Extension routes manage their files themselves: they have no file journal.
- Extensions without a build step can only use render functions (`h()`), no string templates.
- No remote scripts (CDN), no npm package of the kit, no TypeScript types.
- No custom action modals, layout slots, entity pages, cards on the detail page or dependent fields. Cards
  only read data; changes are made with actions or extension routes.
- Links in extension toasts are not supported.

Next: [Extending (overview)](10-extending.md).
