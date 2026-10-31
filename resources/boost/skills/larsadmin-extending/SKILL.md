---
name: larsadmin-extending
description: "Larsadmin extensions: extension packages with custom Vue fields, cards and tools, defineExtension and the larsadmin JS SDK, the kit (Vite plugin, theme, testing helpers), DataCard and CardRequest, extension routes, record and field lookups, audited changes, overrides, CSP and API versions. Use when building a custom field, card, tool or extension package for maxcxam/larsadmin, or when a built-in feature is not enough."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin extending

## When to use

- A built-in field, card, filter or page is not enough: you need your own Vue component (`vendor/name` key).
- You build a reusable Composer extension package, or an extension inside the application (`app/...` keys).
- You add JSON routes for an extension, replace the UI of a built-in component (`override: true`), serve or
  store files from an extension, or use an extension field in an action modal (1.6).

Server-only customizations (a `Text` subclass with other formatting, entities, actions, metrics) need no
JavaScript: see `larsadmin-fields-layout`, `larsadmin-actions`, `larsadmin-metrics-dashboards`.

## Quick start

```bash
php artisan larsadmin:extension acme/larsadmin-rating
composer config repositories.acme-larsadmin-rating path packages/acme/larsadmin-rating
composer require acme/larsadmin-rating:@dev
cd packages/acme/larsadmin-rating && npm install && npm run build && cd -
# add pieces to the package
php artisan larsadmin:field Rating --extension=packages/acme/larsadmin-rating
php artisan larsadmin:card TopRated --data --extension=packages/acme/larsadmin-rating
php artisan larsadmin:tool Backups --extension=packages/acme/larsadmin-rating
```

The skeleton has a provider (it registers the extension once `dist/` exists), an example field and `DataCard`,
Vue components, `lang/`, Pest and vitest tests, and requires `"maxcxam/larsadmin": "^1.6"` with
`requires: '^1.6'`. The commands never edit `extension.js`: they print the `defineExtension()` lines (and `Larsadmin::tools()` for a
tool) to add. Without `--extension` files go to `app/Larsadmin/{Fields,Cards,Tools}` and
`resources/larsadmin/js`, with `app/...` keys. Commit `dist/`: hosts never run npm.

## Recipes

### Register the extension (provider `boot()`)

```php
use Maxcxam\Larsadmin\Larsadmin;

$this->loadTranslationsFrom(__DIR__.'/../lang', 'acme-rating');
Larsadmin::extension('acme/larsadmin-rating', __DIR__.'/../dist')->translations('acme-rating');
```

It returns a `Maxcxam\Larsadmin\Extensions\Extension`; `Larsadmin::registeredExtensions()` and
`Larsadmin::extensionFor('acme/larsadmin-rating')` read the registry.
Inside the app: `Larsadmin::extension('app/admin', resource_path('larsadmin/dist'))` and a separate
`vite.larsadmin.config.js` using `larsadmin({ entry, outDir })` from `vendor/maxcxam/larsadmin/kit/vite.js`.

### A custom field

```php
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

    protected function typeRules(): array
    {
        return ['integer', 'between:1,'.$this->max];
    }

    public function jsonSerialize(): array
    {
        // authorizedTo() (1.6) reuses the row's policy answers: no extra policy call per row.
        return [...parent::jsonSerialize(), 'ratingMax' => $this->max, 'canReset' => $this->authorizedTo('update')];
    }
}
```

Register its Vue parts in `defineExtension({ fields: { 'acme/rating': { form, display } } })`. The form component
renders only the control (the panel renders label, help and errors), emits `update:modelValue`, puts
`:id="field.attribute"` on the focusable element and honours `field.readonly`. Full prop list:
[references/js-sdk.md](references/js-sdk.md). Override only the stable hooks listed in
[references/php-api.md](references/php-api.md).

### Extension fields in actions (1.6)

An action's `fields()` may return extension fields; the modal renders them with the same contract plus an
`action` prop `{ uriKey, entity }`. In PHP `inAction()` is `true`, `context` stays `create`, `authorizedTo()` is
`false`. When `fill()` does not write a single attribute, override `actionValue()`:

```php
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;

class Range extends Field
{
    public string $component = 'acme/range';

    public function actionValue(Request $request): mixed
    {
        $range = $request->input($this->attribute);

        return is_array($range) ? ['min' => (int) ($range['min'] ?? 0), 'max' => (int) ($range['max'] ?? 0)] : null;
    }
}
```

The value must be JSON-safe (scalars, arrays, dates, enums) for queued actions, or queuing throws
`LogicException`. Fields with `$multipart` (and `File`/`Image`) in actions throw `LogicException`.

### A data card

```php
use App\Larsadmin\Post;
use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;

class TopRated extends DataCard
{
    public string $component = 'acme/top-rated';

    public function rules(): array
    {
        return ['limit' => ['sometimes', 'integer', 'min:1', 'max:20']];
    }

    public function data(CardRequest $request): mixed
    {
        return ['items' => $this->entityQuery($request, Post::class)
            ->orderByDesc('rating')->limit((int) $request->get('limit', 5))->get(['id', 'title', 'rating'])->all()];
    }
}
```

In Vue: `const { status, data, reload } = useCardData(() => props.card, { limit: 5 })` inside a `CardShell`.
A static card extends `Maxcxam\Larsadmin\Metrics\Card` and adds props in `jsonSerialize()`. Register cards in
a dashboard's or entity's `cards()`.

### A tool (sidebar page)

Extend `Maxcxam\Larsadmin\Tools\Tool` with `$component = 'acme/backups'`, `icon()` from `Tool::ICONS` and
`props(Request $request)`; register with `Larsadmin::tools([Backups::class])`. Look one up with
`Larsadmin::toolForKey('backups')` or list `Larsadmin::registeredTools()`.

### An extension route that changes a record

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Maxcxam\Larsadmin\Larsadmin;

Larsadmin::routes(function () {
    Route::post('acme-rating/{entity}/{id}/reset', function (\Illuminate\Http\Request $request, string $entity, string $id) {
        $record = Larsadmin::findRecord($request, $entity, $id, 'update');
        $field = Larsadmin::findField($request, $entity, $record, 'rating');

        Larsadmin::audited($request, $entity, $record, function (Model $record) use ($field) {
            $record->forceFill([$field->attribute => null])->save();
        });

        return response()->json(['ok' => true]);
    })->name('acme-rating.reset');
});
```

Routes land under `{path}/ext`, named `larsadmin.ext.acme-rating.reset`. From Vue:
`send('POST', url('ext', 'acme-rating', 'posts', id, 'reset'))` with `useJsonRequest()` and `useLarsadmin()`.

### Store or serve files safely (1.6)

```php
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Files\FileSafety;

$request->validate(['attachment' => ['required', 'file', FileSafety::rule()]]);
$file = $request->file('attachment');
$path = $file->storeAs('attachments', Str::ulid().'.'.FileSafety::storedExtension($file), 'local');
$name = FileSafety::sanitizeName($file->getClientOriginalName()) ?: 'file';

// Later, after Larsadmin::findRecord($request, $entity, $id, 'view'):
$response = FileSafety::response(Storage::disk('local'), $path, $name);
```

In form saves, override `fillInto()` and register written files with `$journal->created($disk, $path)` and
replaced ones with `$journal->obsolete($disk, $path)` (`Maxcxam\Larsadmin\Files\FileJournal`).

### Overrides, versions, CSP and the API check

- Override a built-in UI: `fields: { 'boolean-field': { form: BooleanSwitch, override: true } }`,
  `cards: { 'value-metric': { component: BigNumber, override: true } }`. Allowed: the text, password,
  textarea, number, boolean, select, date and datetime fields (parts `form`, `display`, `index`, `detail`), the
  three built-in filters and four metric cards. Rendering only; the server still validates.
- `requires: '^1.6'` (and `"maxcxam/larsadmin": "^1.6"`) when you use `authorizedTo()`, `inAction()`,
  `actionValue()`, the `action` prop or `FileSafety`; `^1.5` otherwise. `apiVersion` in JS equals
  `Larsadmin::API_VERSION`.
- With a CSP, call `Vite::useCspNonce()` in a middleware before the panel renders, or the import map is blocked
  and extension fields show "Unavailable".
- Run `node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .` in the extension directory (CI too).
- `Larsadmin::assetsVersion()` changes when extension files change, so open tabs reload after `composer update`.

## API cheat sheet

| Need | API |
|---|---|
| Scaffold | `larsadmin:extension`, `larsadmin:field`, `larsadmin:card [--data]`, `larsadmin:tool` |
| Register | `Larsadmin::extension()`, `Maxcxam\Larsadmin\Extensions\Extension` (`script`, `style`, `translations`, `version`, `url`) |
| Client entry | `defineExtension({ name, requires, fields, cards, filters, tools, setup })` |
| SDK | `useI18n`, `useLarsadmin`, `useJsonRequest`, `useJsonResource`, `useCardData`, `useToasts`, `useTheme`, `useNumberFormat`, `buildUrl`, `withParams`, `Icon`, `CardShell`, `ConfirmDialog`, `apiVersion` |
| Data card | `Maxcxam\Larsadmin\Metrics\DataCard`, `Maxcxam\Larsadmin\Metrics\CardRequest` |
| Page | `Maxcxam\Larsadmin\Tools\Tool`, `Larsadmin::tools()` |
| Routes | `Larsadmin::routes()`, `Larsadmin::findRecord()`, `Larsadmin::findField()`, `Larsadmin::audited()` |
| Files | `Maxcxam\Larsadmin\Files\FileSafety`, `Maxcxam\Larsadmin\Files\FileJournal` |
| Kit | `vite.js` (`larsadmin()`), `testing.js` (`withPanel`, `mountField`, `mountDisplay`, `mountCard`), `theme.css` |

Full tables: [references/php-api.md](references/php-api.md), [references/js-sdk.md](references/js-sdk.md).

## Security rules

- MUST call `Larsadmin::findRecord()` (with `update`/`delete` for changes) in **every** extension route that
  touches a record; `Larsadmin::routes()` only guarantees the panel gate.
- MUST call `Larsadmin::findField()` before changing a field's value from a route, so visibility and readonly
  rules apply; record the change with `Larsadmin::audited()`.
- MUST query entity data in a `DataCard` through `entityQuery()`; put access checks in `canSee()` (they do not run
  inside `data()` on cache hits) and add tenant/team to `cacheKeyParts()`.
- MUST use `FileSafety` for uploads and file responses instead of copying the panel's checks; authorize first.
- NEVER trust client-side checks or overrides: the server validates and authorizes.
- NEVER bundle Vue, Inertia or the SDK, import panel files from `resources/js`, or install untrusted
  extensions: extension JavaScript runs with the administrator's rights.

## Pitfalls

- `name` in `defineExtension()` must equal the name in `Larsadmin::extension()`, or nothing loads.
- A key without `/`, or a built-in key without `override: true`, is rejected in the console.
- `Larsadmin::flushState()` in tests removes extensions registered at boot; register again after it.
- Multipart forms do not send empty arrays: send a presence flag or treat a missing key as `[]`.
- `fill()` closures do not run in actions; override `actionValue()` instead.
- A panel installed from a branch is `dev-*` for Composer: use `:@dev -W` or a path repository version.

## Reference

- Guides: [vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md](vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md)
  (full contract, CSP, troubleshooting, HTTP endpoints) and
  [vendor/maxcxam/larsadmin/docs/guide/en/10-extending.md](vendor/maxcxam/larsadmin/docs/guide/en/10-extending.md)
  (what can be extended, the component registry).
- Testing extensions: `larsadmin-testing`. Action modals: `larsadmin-actions`. Files: `larsadmin-files`.
- MCP (when connected): `read-docs page=19-extensions section=extension-routes`, `list-extensions`,
  `generate kind=field name=Rating options={"extension": "packages/acme/larsadmin-rating"}`.
