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
            Text::make('Коментар', 'note')->nullable()->rules('max:255'),
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

`redirect()` and `download()` accept only **internal** URLs: a relative path starting with a single `/`,
or an absolute `http(s)` URL on the current host. Anything else throws an `InvalidArgumentException`.

```php
return ActionResponse::download('/exports/posts.csv', 'posts.csv');
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
`redirect`/`download`, then `message`. At equal severity, the earlier one wins.

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

## Files in actions

`File` and `Image` fields are not supported in action `fields()`: the first call to the action's
fields throws `LogicException`. An action that deletes records with files must call
`Entity::deleteRecord()` instead of `$model->delete()`, otherwise the files stay on the disk. See
[Files and images](15-files.md#deleting-records).

## `BelongsTo` fields in the modal

A `BelongsTo` field can be used in an action's `fields()`. Its options come from the
`GET {path}/api/entities/{entity}/fields/{field}/options?context=action&action=<action uriKey>` endpoint;
the related Entity's `relatableQuery` and its `viewAny` apply, and the action's visibility is decided by
`canSee()`. The value is validated with `RelatableExists`, as on forms. `ActionFields` receives the
foreign key.

Next: [Localization](08-i18n.md).
