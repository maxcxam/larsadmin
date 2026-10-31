---
name: larsadmin-actions
description: "Larsadmin actions: Action classes, modal fields (including extension fields), row/detail/standalone actions, responses, queued runs with progress and cancellation, authorization and audit. Use when adding or changing a bulk/row action, an action modal, a background action or ActionResponse in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin actions

## When to use

- Add a bulk action to an Entity list, a row "⋯" action or a detail-page action.
- Add a standalone action (no selected records), such as an export.
- Ask for input in the action modal (built-in or extension fields).
- Move a slow action to the queue (progress in the bell, cancellation, result notification).

## Quick start

```bash
php artisan larsadmin:action MarkLive                # app/Larsadmin/Actions/MarkLive.php
php artisan larsadmin:action Archive --destructive   # red confirm button
```

`--force` overwrites. With the MCP server, `generate` with `kind: "action"` and
`options: {destructive: true}` returns the plan first; write it with `write: true`.

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
        return [Text::make('Note', 'note')->nullable()->rules('max:255')];
    }

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        foreach ($models as $model) {
            $model->update(['status' => 'live', 'note' => $fields->note]);
        }

        return ActionResponse::message(__('Done.'));
    }
}
```

Register it in the Entity: `public function actions(Request $request): array { return [MarkLive::make()]; }`.
`handle()` is called once per chunk (`$chunkSize`, 200), each chunk in its own transaction.

## Recipes

### Where the action shows up

```php
use App\Larsadmin\Actions\ArchivePost;
use App\Larsadmin\Actions\ExportPostsCsv;
use App\Larsadmin\Actions\PublishPost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

$actions = [
    PublishPost::make()->showInline(),                    // list menu, detail page and row "⋯"
    ArchivePost::make()->onlyInline()->destructive(),     // row "⋯" only: runs on exactly one record
    ExportPostsCsv::make()->standalone()->onlyOnIndex(),  // no records needed
    PublishPost::make()->canRun(fn (Request $request, ?Model $post) => $post === null || ! $post->published),
];
```

- An action that is not on the list menu and not standalone runs on one record only
  (`runsOnSingleRecord()`); several ids get 422.
- `canRun()` is called for every row on the page to build the menus: no database queries inside it
  (eager load through `Entity::$with`).

### Responses

`ActionResponse::message()`, `danger()`, `redirect($url)`, `download($url, $filename)`,
`file($disk, $path, ?$filename)`. `null` means "Action completed.". `redirect()` and `download()` take
internal URLs only. For exports prefer `file()` with a unique path:

```php
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Actions\ActionResponse;

$path = 'exports/posts-'.Str::ulid().'.csv';
Storage::disk('local')->put($path, $csv);

return ActionResponse::file('local', $path, 'posts.csv');
```

Deleting records with files: call `$this->entity::make($model)->deleteRecord(request())`, not
`$model->delete()`, so files are pruned after the commit (skill `larsadmin-files`).

### Background (queued) action

```php
namespace App\Larsadmin\Actions;

use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;

class RecalculateViews extends Action
{
    public bool $queued = true;

    public ?string $queue = 'heavy';

    public ?int $timeout = 600;

    public int $chunkSize = 50;

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        $models->each->recalculateViews();

        return null;
    }
}
```

Or chain: `RecalculateViews::make()->queued()->onQueue('heavy')->onQueueConnection('redis')`.

Hand-off to notifications: a queued action runs on the queue only when notifications are on
(`LARSADMIN_NOTIFICATIONS=true`) and a worker runs; otherwise it runs synchronously. Progress,
"Cancel" and the result live in the bell (skill `larsadmin-notifications-realtime`). The result
notification replaces the immediate response: `file()` becomes a download link for the run's owner.

On the queue:

- the worker re-checks everything as the starting user (panel access, `viewAny`, `canSee()`,
  `authorizedToRun()`), and records come again from `indexQuery()` / `detailQuery()`;
- `session()` is an empty per-run session, the rebuilt request has no cookies, headers or route;
- field values are stored as JSON: dates arrive as ISO-8601 strings (`Carbon::parse()`), enums as their
  value or name; any other object throws `LogicException` naming the field when the action is queued;
- one job per run, no retries: make `handle()` idempotent; cancel stops after the current chunk;
- `retry_after` of the queue connection must exceed `$timeout` and the worker's `--timeout`.

React to the end of a run:

```php
use Illuminate\Support\Facades\Event;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Events\ActionRunFinished;

Event::listen(function (ActionRunFinished $event) {
    if ($event->run->status === ActionRun::FAILED) {
        logger()->warning('Run failed', $event->run->only('id', 'entity', 'action', 'user_id'));
    }
});
```

### Extension fields in the modal (1.6)

Any field from `fields()` works in the modal, including extension fields (`vendor/name` components).
Since 1.6 a field knows it is in an action:

- `$field->inAction()` is `true`; its context stays `create`; `authorizedTo()` returns `false` (there
  is no record).
- `ActionFields` gets `$field->actionValue($request)`. The default fills a throw-away model and reads
  `fillAttribute()`; a closure returned by `fill()` is not run. Override it when your value is not
  written to the attribute:

```php
namespace App\Larsadmin\Fields;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Field;

class Rating extends Field
{
    public string $component = 'acme/rating';

    public function actionValue(Request $request): mixed
    {
        // A scalar: safe for queued actions too.
        return $request->filled($this->attribute) ? $request->integer($this->attribute) : null;
    }
}
```

The Vue component gets `action: { uriKey, entity }`, `context: 'create'`, `resourceId` (the record key
for a single-record run, else `null`) and `field.inAction: true`; an unknown or crashed component shows
"Unavailable: the extension is not loaded" and its value is not sent. JS side and `mountField(..., { action })`: skill
`larsadmin-extending`.

## API cheat sheet

| API | Notes |
| --- | --- |
| `Maxcxam\Larsadmin\Actions\Action` | `handle()` required; `name()`, `uriKey()`, `fields()`, `make()` |
| `$destructive`, `$standalone`, `$chunkSize` (200) | also `destructive()`, `standalone()` |
| `confirmText()`, `confirmButtonText()`, `cancelButtonText()`, `withoutConfirmation()` | modal texts |
| `showOnIndex()`, `showOnDetail()`, `showInline()`, `onlyOnIndex()`, `onlyOnDetail()`, `onlyInline()` | placement |
| `canSee(fn (Request $r): bool)` | hidden action returns 404 |
| `canRun(fn (Request $r, ?Model $m): bool)` | replaces the default `update` check; `null` model for standalone |
| `$queued` / `queued()`, `$queue` / `onQueue()`, `$queueConnection` / `onQueueConnection()`, `$timeout`, `runsQueued()` | background runs |
| `Maxcxam\Larsadmin\Actions\ActionFields` | a `Fluent`: `$fields->note`, `$fields->get('note')` |
| `Maxcxam\Larsadmin\Actions\ActionResponse` | `message`, `danger`, `redirect`, `download`, `file` |
| `Maxcxam\Larsadmin\Actions\Queued\ActionRun` | run model; statuses incl. `ActionRun::FAILED` |
| `Maxcxam\Larsadmin\Events\ActionRunFinished` | `$event->run` after a final status |
| `Field::inAction()`, `Field::actionValue(Request)` | stable since 1.6 |
| `larsadmin:action Name [--destructive] [--force]` | generator |

Config: `larsadmin.actions.max_all` (1000, "all matching" limit), `larsadmin.actions.queued_max_all`
(10000), `larsadmin.actions.queued_max_active` (3 per user), `larsadmin.actions.queue`
(`LARSADMIN_QUEUE`), `larsadmin.actions.queue_connection` (`LARSADMIN_QUEUE_CONNECTION`).

## Security rules

- MUST rely on the per-record check: by default the record's `update` policy; with `canRun()` your
  closure decides. Records that fail are skipped, not run.
- MUST make `canRun()` of a standalone action accept `null`; without `canRun()` a standalone action
  needs the Entity's `create` permission (fail closed).
- NEVER build `ActionResponse::file()` or `redirect()` paths from user input; give export files unique
  names (`Str::ulid()`) so users never get each other's exports.
- NEVER depend on session or request headers in `canSee()`, `canRun()` or `fields()` of a queued
  action: they are empty in the worker.
- MUST prefix CSV cells starting with `=`, `+`, `-`, `@`, tab or CR with `'` in exports.
- MUST mark secret modal fields `->sensitive()` (or use `Password`): field values are written to the
  audit log.

## Pitfalls

- No file uploads in actions (1.6): `File`, `Image` or any `multipart` field in `fields()` throws
  `LogicException` on the first call to the action's fields.
- Queued values must be serializable: scalars, arrays, dates or enums. Return such a value from
  `actionValue()`.
- `handle()` runs per chunk: a later chunk failing leaves earlier chunks saved. Keep it idempotent.
- "All matching" over `max_all` (1000) gets 422; queued actions use `queued_max_all`.
- `$this->entity` is `null` when the action is built outside the panel (`new` in a test).
- Actions in `HasMany` / `BelongsToMany` panels have no row menu.
- When several chunks respond differently, the most severe response wins (`danger` first).

## Reference

- Guide: `vendor/maxcxam/larsadmin/docs/guide/en/07-actions.md` (MCP: `read-docs` with
  `page: "07-actions"`).
- Background actions: `vendor/maxcxam/larsadmin/docs/guide/en/18-notifications.md#background-actions`
  (MCP: `read-docs` with `page: "18-notifications"`, `section: "background-actions"`).
- Audit of action runs: skill `larsadmin-audit-log`. Extension fields: skill `larsadmin-extending`.
- Tests: POST to `{path}/entities/{entity}/actions/{action}` with `resources` (ids or `all`) and the
  field values; see skill `larsadmin-testing`.
