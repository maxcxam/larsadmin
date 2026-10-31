# 07. Actions
🇺🇦 [Українська](../07-actions.md)

An action runs an operation on the selected records (or on all records matching the current filters and
search). Actions are available from the list (index) menu, from the "⋯" menu of a single list row, and
from the record's detail page.

Actions are returned by the `Entity::actions(Request $request): array` method:

```php
public function actions(Request $request): array
{
    return [
        new \App\Larsadmin\Actions\MarkLive,
        \App\Larsadmin\Actions\Archive::make()->destructive(),
    ];
}
```

## Generator

```bash
php artisan larsadmin:action MarkLive
php artisan larsadmin:action Archive --destructive
```

The class is created as `App\Larsadmin\Actions\MarkLive`. Options: `--destructive` (sets
`$destructive = true`) and `--force` (overwrite).

## Example

```php
namespace App\Larsadmin\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;
use Maxcxam\Larsadmin\Fields\Text;

class MarkLive extends Action
{
    public function name(): string
    {
        return __('Mark live');
    }

    public function fields(Request $request): array
    {
        return [
            Text::make('Comment', 'note')->nullable()->rules('max:255'),
        ];
    }

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        foreach ($models as $model) {
            $model->update(['status' => 'live']);
        }

        return ActionResponse::message(__('Done.'));
    }
}
```

`handle()` is the only required method. The values of the modal's fields are available as
`$fields->get('note')` or `$fields->note` (`ActionFields` is an `Illuminate\Support\Fluent`).

## Action API

Configure an action with properties or with the chainable methods of the same name:

| Property / method | Default | Description |
| --- | --- | --- |
| `name()` | the class name in Title Case | the label in the UI (via `__()`) |
| `uriKey()` | the kebab-case class name | the action's URL segment; within an Entity, the first action with that key is used |
| `fields(Request)` | `[]` | fields of the modal (used like create-form fields, with validation rules) |
| `destructive` / `destructive()` | `false` | a dangerous action: red confirmation button |
| `standalone` / `standalone()` | `false` | an action that needs no selected records |
| `confirmText()`, `confirmButtonText()`, `cancelButtonText()` | from `larsadmin::actions.*` | confirmation texts |
| `withoutConfirmation()` | `null` | skip the modal. With `null`, it is skipped only if the action is not destructive and has no `confirmText` and no fields |
| `showOnIndex` / `showOnIndex(bool $show = true)` | `true` | show in the list's actions menu (bulk actions) |
| `showOnDetail` / `showOnDetail(bool $show = true)` | `true` | show in the detail page's actions menu |
| `showInline` / `showInline(bool $inline = true)` | `false` | show in the "⋯" menu of each list row |
| `onlyOnIndex()`, `onlyOnDetail()` | | only in the list menu / only on the detail page; they do not change `showInline` |
| `onlyInline()` | | only in the row's "⋯" menu: `showOnIndex = false`, `showOnDetail = false`, `showInline = true` |
| `chunkSize` | `200` | size of a record chunk |
| `queued` / `queued(bool $queued = true)` | `false` | run in the background, on the queue ([Background actions](#background-actions)) |
| `queue` / `onQueue(?string)`, `queueConnection` / `onQueueConnection(?string)` | `null` | queue and queue connection of a background action |
| `timeout` | `null` | job timeout of a background action, in seconds |
| `canSee(Closure)` | | `fn (Request $request): bool`, whether to show the action |
| `canRun(Closure)` | | `fn (Request $request, ?Model $model): bool`; `null` is passed for a standalone action |

## Row actions and detail page actions

An action can be run for a single record without selecting rows:

- **The row's "⋯" menu.** Actions with `showInline` appear in the "⋯" menu at the right of each list row,
  next to the view, edit and delete buttons. Destructive actions come last in the menu.
- **The detail page.** Actions with `showOnDetail` (all of them by default) are available in the record's
  actions menu.

```php
public function actions(Request $request): array
{
    return [
        PublishPost::make()->showInline(),             // list menu, detail page and row "⋯"
        ArchivePost::make()->onlyInline(),            // row "⋯" only
        ExportPostsCsv::make()->standalone()->onlyOnIndex(), // list menu only
    ];
}
```

`standalone` actions have no record, so they never appear in the row menu and are not checked per record.

The "⋯" button has an accessible name that includes the record's title ("Actions for “First post”"), and
a menu near the bottom of the screen opens upwards.

### Single-record actions

An action that is not in the list menu (`showOnIndex` is `false`, that is `onlyInline()`, `onlyOnDetail()`
or `showOnIndex(false)`) and is not `standalone` runs on **exactly one record**
(`Action::runsOnSingleRecord()`). A request with `resources: 'all'` or with several IDs is rejected with
422 ("This action runs on one record at a time."). For all other actions, `handle()` must be ready for
any number of records.

### Per-record availability

The server works out in advance which actions can run on each row and on the open record:

| Where | What is shown |
| --- | --- |
| the row's "⋯" menu | only the actions available for that record. If there are none, there is no "⋯" button |
| the detail page menu | only the actions available for that record; unavailable ones are hidden |

Availability is checked by the same logic as at run time (see "Authorization" below):

- **Without `canRun()`** and without a custom `authorizedToRun()`, an action is available if the user can
  edit the record (`update`). The panel has already computed this permission for the edit button, so there
  are no extra policy calls.
- **With `canRun()`**, the closure is called for each row on the page. It must work on the already loaded
  model **without database queries**: load the relations it needs through `Entity::$with`. Otherwise each
  row adds a query (N+1).

```php
PublishPost::make()
    ->onlyInline()
    ->canRun(fn (Request $request, ?Model $post) => $post === null || ! $post->published),
```

The pre-check only drives the UI. When the action runs, the server checks permissions again
(see "Authorization"), so a request that bypasses the menu will not run the action on a forbidden record.

> Actions in relation panels (`HasMany`, `BelongsToMany`) do not have a row menu yet.

## Responses

`handle()` returns `?ActionResponse`. `null` gives the message "Action completed."

| Method | Result |
| --- | --- |
| `ActionResponse::message(string)` | goes back with a success message |
| `ActionResponse::danger(string)` | goes back with an error message |
| `ActionResponse::redirect(string $url)` | navigates to the URL |
| `ActionResponse::download(string $url, string $filename)` | the browser downloads the file |
| `ActionResponse::file(string $disk, string $path, ?string $filename = null)` | the panel serves a file from a disk as a download |

`redirect()` and `download()` accept only **internal** URLs: a relative path starting with a single `/`,
or an absolute `http(s)` URL on the current host. Anything else throws an `InvalidArgumentException`.

```php
return ActionResponse::download('/exports/posts-'.$exportId.'.csv', 'posts.csv');
```

`file()` needs no public URL: the panel serves the file from any configured disk, only to the user who
ran the action. In a synchronous action the link is encrypted, bound to the user and valid for
`files.url_ttl` minutes (30), and the file stays on the disk. In a background action the panel serves its
own copy of the file (`larsadmin/runs/{run}/…` on `files.disk`) and deletes only that copy together with
the run; clean up your file yourself. The path must be relative and without `..`, otherwise
`InvalidArgumentException` is thrown. Give files unique names, so that runs of different users never write
to the same file. See [`ActionResponse::file()`](18-notifications.md#actionresponsefile).

```php
$path = 'exports/posts-'.Str::ulid().'.csv';

Storage::disk('local')->put($path, $csv);

return ActionResponse::file('local', $path, 'posts.csv');
```

## How an action runs

Request: `POST {path}/entities/{entity}/actions/{action}`.

| Request field | Value |
| --- | --- |
| `resources` | `all` or a non-empty list of IDs. The list may hold at most the largest value of `Entity::$perPageOptions` (100 by default). Not needed for `standalone` |
| `filters`, `search` | used only when `resources` is `all` |
| action fields | values from `fields()` |

- **`resources: 'all'`** selects every record in the `indexQuery` scope with the active filters and
  search. Limit: `config('larsadmin.actions.max_all')` (`1000` by default). If more records match, the
  response is 422 (a validation error on `resources`), and the user has to narrow the search or filters.
- **A list of IDs** is resolved through `detailQuery`.
- Records are processed in chunks of `chunkSize` (`chunkById`), **each chunk in its own transaction**.
  `handle()` is called separately for each chunk.
- A `standalone` action is called once with an empty collection.

### Partial runs and errors

The transaction covers a chunk, not the whole action. If a chunk throws an exception:

- on the first chunk nothing is saved, and the exception is handled as usual;
- on later chunks, the chunks already saved stay saved. The exception is logged with `report()`, and the
  user sees an error message: "Completed for :done of :total records, then an error occurred."

So `handle()` should be idempotent, so that the action can be repeated.

### Which response the user sees

If different chunks returned different responses, the **most severe** one wins: `danger`, then
`redirect`/`download`/`file`, then `message`. At equal severity, the earlier one wins.

| Situation | Result |
| --- | --- |
| No record matched the selection | error message "No records matched." |
| Records matched, but none passed `authorizedToRun()` | 403 |
| Some records were skipped | success message with the addition "N records skipped (not allowed)." |

## Authorization

1. The user must have `viewAny` for the Entity.
2. `canSee()` hides the action; a hidden action returns 404.
3. `authorizedToRun()` is called for each record. By default it is the record's `update` ability
   (through the policy, see [Authorization](05-authorization.md)); `canRun()` replaces this check.
4. Records that fail the check are **skipped** (see the table above).
5. **A `standalone` action** has no record to check, so `authorizedToRunStandalone()` is called: if
   `canRun()` is set, it is called with `null` instead of a model; otherwise the `create` permission for
   the Entity is required. Without the permission the response is 403 (fail closed). That is why `canRun`
   for a standalone action must accept `null`.

```php
MarkLive::make()
    ->canSee(fn (Request $request) => $request->user()->can('moderate'))
    ->canRun(fn (Request $request, ?Model $post) => $post === null || $post->status !== 'live');
```

## Background actions

A long action can run on the queue: the user gets a confirmation at once, follows the progress in the
bell, can cancel the run and gets a notification with the result.

```php
class RecalculateViews extends Action
{
    public bool $queued = true;

    public int $chunkSize = 50;

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        $models->each->recalculateViews();

        return null;
    }
}
```

- It works only with notifications enabled (`LARSADMIN_NOTIFICATIONS=true`) and a running queue worker.
  While notifications are off, an action with `$queued` runs synchronously, like any other.
- The worker repeats every check as the user who started the action. `handle()` gets the records in
  chunks, in transactions, as in a synchronous action.
- Limits: up to 3 active runs per user, up to 10,000 records for "all matching"
  (`actions.queued_max_all` instead of `actions.max_all`).
- On the queue `session()` is a separate empty session of the run, and date field values arrive as
  ISO-8601 strings.
- Field values are stored as encrypted JSON: scalars, arrays, dates (ISO-8601 strings) and enums (their
  value) are allowed. Any other value (say, a model or an object from an extension field's `actionValue()`)
  throws a `LogicException` naming the field when the run starts, before the run record is created; the
  worker gets the stored values.
- The queue connection's `retry_after` must exceed the action's `$timeout` and the worker's `--timeout`.

Details: [Notifications and background actions](18-notifications.md#background-actions).

## Audit log

When the [audit log](17-audit-log.md) is on, an action run writes one entry per handled record, sharing
a `batch_id`. The field diff is taken by reading the records again after `handle()`, in the chunk's
transaction; calls to `Entity::save()` and `Entity::deleteRecord()` inside `handle()` for the chunk's own
records write no entries of their own, while saves of other records are recorded with the shared
`batch_id`. The values of the action's fields are stored too: `Password` fields and fields with
`->sensitive()` are masked. See [Audit log: actions](17-audit-log.md#actions).

## Files in actions

`File` and `Image` fields, and any field with `multipart: true` (an extension field that uploads files), are
not supported in action `fields()`: the first call to the action's fields throws `LogicException`. An action
fills a throw-away model, so stored uploads would be orphaned. An action that deletes records with files must call
`Entity::deleteRecord()` instead of `$model->delete()`, otherwise the files stay on the disk. See
[Files and images](15-files.md#deleting-records).

## `BelongsTo` fields in the modal

A `BelongsTo` field can be used in an action's `fields()`. Its options come from the
`GET {path}/api/entities/{entity}/fields/{field}/options?context=action&action=<action uriKey>` endpoint;
the related Entity's `relatableQuery` and its `viewAny` apply, and the action's visibility is decided by
`canSee()`. The value is validated with `RelatableExists`, as on forms. `ActionFields` receives the
foreign key.

## Extension fields in the modal

Since 1.6 an action's `fields()` can use custom fields and [extension](19-extensions.md#custom-fields) fields
with a `vendor/name` Vue component, as well as overrides of built-in components. The modal renders them
through the same registry and `ExtensionBoundary` as the record form.

```php
use Acme\LarsadminRating\Fields\Rating;

public function fields(Request $request): array
{
    return [
        Rating::make('Rating', 'rating')->max(5)->rules('required'),
    ];
}

public function handle(ActionFields $fields, Collection $models): ?ActionResponse
{
    $models->each->update(['rating' => $fields->rating]);

    return null;
}
```

**PHP.** A field of an action has `inAction() === true`, while its `context` stays `create` (fields written
for 1.5 keep working). Its rules come from `rulesFor(Field::CREATE)`, the names for messages from
`validationAttributeNames()` (nested keys such as `range.min` included). `authorizedTo()` is always `false`
in an action.

`actionValue(Request $request): mixed` returns the value for `ActionFields`. By default the field fills a
throw-away model with `fill()` and reads its `fillAttribute()` attribute, as in 1.5. A closure returned by
`fill()` is not run in an action: there is no record to save. If `fill()` writes the value somewhere other
than `fillAttribute()` (say, into two attributes), override `actionValue()`:

```php
public function actionValue(Request $request): mixed
{
    $range = $request->input($this->attribute);

    return is_array($range) ? ['min' => (int) ($range['min'] ?? 0), 'max' => (int) ($range['max'] ?? 0)] : null;
}
```

For a [background action](#background-actions) the value must survive JSON (a scalar, array, date or enum).

**Vue.** The form component gets the same contract as in the record form: `field` (with `inAction: true`),
`modelValue`, `invalid`, `errors`, `errorsByKey`, `describedBy`, `context: 'create'`, `resourceId` (the
record's key when the action runs on exactly one record and is not standalone, else `null`) and the new
`action: { uriKey, entity }` prop (the record form does not pass it: declare the prop with `default: null`).
An unavailable or crashed extension shows "Unavailable: the extension is not loaded" and the field's value
is not sent (fail closed). In vitest: `mountField(Component, { action: { uriKey, entity } })` ([Extensions: testing](19-extensions.md#vitest-vue)).

Fields that upload files are not supported in actions ([Files in actions](#files-in-actions)).

Next: [Localization](08-i18n.md).
