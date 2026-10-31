# 18. Notifications and background actions
🇺🇦 [Українська](../18-notifications.md)

A bell in the panel header lists the user's notifications. The application sends them (with the
`LarsadminNotification` class), and the panel sends one itself when a background action finishes. An
action with `$queued` runs on the Laravel queue: the user gets a confirmation at once, follows the
progress in the bell, can cancel the run and gets a notification with the result when it is done.

Updates arrive by polling. When the application has Reverb or Pusher configured, the panel receives
signals in real time (see [Realtime](#realtime)).

The feature is **off by default**. While it is off, the panel makes no extra queries, the new routes
answer 404 and actions with `$queued` run synchronously, like any other action. It needs no extra
Composer or npm dependencies: the `laravel-echo` and `pusher-js` client is already bundled in the
panel's built assets.

## Quick start

1. Create Laravel's `notifications` table (unless you have it) and publish the panel migrations
   (`larsadmin:install` already publishes them in new projects):

   ```bash
   php artisan make:notifications-table
   php artisan vendor:publish --tag=larsadmin-migrations
   php artisan migrate
   ```

2. The panel's user model must use the `Notifiable` trait (the default `App\Models\User` already does).

3. Turn the feature on in `.env`:

   ```dotenv
   LARSADMIN_NOTIFICATIONS=true
   QUEUE_CONNECTION=database
   ```

4. Run a queue worker (in production under Supervisor or similar). The queue connection's `retry_after`
   must exceed the worker's `--timeout` and the `$timeout` of every background action (see
   [Queue settings](#queue-settings)):

   ```bash
   php artisan queue:work --timeout=600
   ```

5. Schedule pruning in `routes/console.php`:

   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('larsadmin:prune-notifications')->daily();
   ```

The bell now shows in the header, and actions can be marked as [background actions](#background-actions).

> **Warning.** If the feature is on but the tables are missing, panel pages keep working, but the bell
> shows no counter and the error goes to `report()`. Bell requests and queuing a background action fail
> with an exception that says "run the larsadmin migrations ... make:notifications-table". Run the
> migrations or set `LARSADMIN_NOTIFICATIONS=false` again.

## The bell

The bell sits in the header between search and the user's name. It shows only panel notifications
(type `larsadmin` in the `notifications` table) addressed to the current user of the panel's guard.
The application's other notifications never show up in it.

- **The counter** of unread notifications is shown as a number (above 99 as "99+"); at zero there is no
  counter. While one of the user's background actions runs, the bell shows an activity indicator.
- **The list** opens on click as a dropdown: newest first, 15 at a time (`per_page`), "Show more" appends
  the next ones. Each notification has a level (info, success, warning, error), a title, a text, a time
  and, when set, a link or a download button.
- Clicking a notification marks it as read and opens its link. Each notification can also be marked as
  read or deleted with its own button; "Mark all as read" marks the whole list.
- **"Background tasks"** above the list show active runs and those finished in the last 24 hours (up to
  10): status, a progress bar "400 of 1200", a "Cancel" button and the link to the result.
- **New notifications** also pop up as toasts (up to three at once, then "N more notifications").

When the user's session has expired and a background bell request gets 401 or 419, the page does not
reload: polling and realtime stop, and a "Your session has expired. Reload the page to sign in again."
toast stays until the user closes it. Marking as read, deleting and cancelling are not undone by a list
response that was in flight while they happened.

Esc, a click outside or Tab past the end close the list and return focus to the bell. On narrow screens
the list takes the full width below the header. The bell works with the keyboard and screen readers
(WCAG 2.1 AA): the button's name includes the unread count ("Notifications, 3 unread"), unread items are
marked by more than color, and progress is exposed as a `progressbar`.

There is no bell when the feature is off, when the user has no panel access, or when the user's model
does not use `Notifiable`.

### How it updates

| What | Without realtime | With realtime |
| --- | --- | --- |
| Counter and new notifications | `GET api/notifications/status` every `poll_interval` s (60), only while the tab is visible, and at once when the tab becomes visible again; also on every navigation in the panel | on signals; polling `status` stays on as a fallback |
| Active runs | every 3 s, every 10 s after two minutes | on signals, plus a fallback poll every 30 s |

After a failed request the polling interval doubles (up to 5 minutes). `poll_interval = 0` turns polling
off: the counter then updates only when the user navigates between pages.

## Sending notifications

`Maxcxam\Larsadmin\Notifications\LarsadminNotification` is a regular Laravel notification stored by the
`database` channel with type `larsadmin`:

```php
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;

$user->notify(
    LarsadminNotification::make('Import finished')
        ->body('120 products added.')
        ->success()
        ->url(route('larsadmin.entities.index', 'products', false), 'View'),
);

LarsadminNotification::make('Maintenance at 22:00')
    ->warning()
    ->sendTo(User::where('is_admin', true)->get());
```

| Method | Description |
| --- | --- |
| `make(string $title)` | a new instance; the title is trimmed and cut to 200 characters, an empty one throws `InvalidArgumentException` |
| `title(string $title)` | change the title (same rules) |
| `body(?string $body)` | text under the title, up to 2000 characters; plain text, line breaks are kept |
| `level(string $level)` | `info` (default), `success`, `warning` or `danger`; anything else throws `InvalidArgumentException` |
| `info()`, `success()`, `warning()`, `danger()` | shortcuts for `level()` |
| `icon(?string $icon)` | `bell`, `check`, `alert`, `info`, `download`, `file`, `history` or `null` (the level's icon); anything else throws `InvalidArgumentException` |
| `url(?string $url, ?string $label = null)` | the notification's link and its label (up to 200 characters) |
| `sendTo(mixed $notifiables)` | the same as `Notification::send($notifiables, $this)` |

Only models using the `Notifiable` trait receive notifications. The bell shows notifications of the
user model of the panel's guard: notifications sent to another model (another guard) do not appear.

**Links.** `url()` accepts only URLs of the same application: a relative path starting with a single `/`
(`/admin/...`, not `//host`), or an absolute `http(s)` URL on the current request's host, the `APP_URL`
host or the panel domain (`larsadmin.domain`). Anything else throws `InvalidArgumentException`. The link
is checked again when read, and the browser only opens links on the same origin. A notification with an
invalid link is shown without it. Panel paths open without a page reload, other application paths with
a full page load.

**Text.** The title and text are stored as given and shown as plain text (HTML is not interpreted).
Translate them yourself, for example in the recipient's language with `HasLocalePreference` or
`->locale()`, as for any Laravel notification.

**Queueing.** The class does not implement `ShouldQueue`: storing it is a single `INSERT`. To send it
through the queue, add `ShouldQueue` in a subclass.

### Your own class

`LarsadminNotification` is not `final`. A subclass can, for example, add mail:

```php
use Illuminate\Notifications\Messages\MailMessage;
use Maxcxam\Larsadmin\Notifications\LarsadminNotification;

class ImportFinished extends LarsadminNotification
{
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line('The import has finished.');
    }
}
```

Leave `databaseType()` and `toArray()` alone: the bell finds and renders notifications by them. A class
that does not extend `LarsadminNotification` also shows up in the bell when its `databaseType()`
returns `'larsadmin'` and its `toArray()` has this shape:

```json
{ "v": 1, "title": "Import finished", "body": "120 products added.", "level": "success",
  "icon": "check", "url": "/admin/entities/products", "urlLabel": "View", "run": null }
```

Only `title` is required. Unknown `level` and `icon` values fall back to `info` and the level's icon, and
`url` is checked by the rules above.

## Background actions

An action with `$queued` runs on the queue. The action's modal says in advance that it will run in the
background. After the user confirms, they see a message like "“Export CSV” is running in the background.
See notifications for progress." The progress itself shows in the bell's "Background tasks" section.

```php
use App\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;

class ExportPostsCsv extends Action
{
    public bool $queued = true;

    public ?string $queue = 'exports';

    public ?int $timeout = 600;

    public bool $standalone = true;

    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        // A unique name: runs of different users never share a file.
        $path = 'exports/posts-'.Str::ulid().'.csv';

        Storage::disk('local')->put($path, Post::query()->pluck('title')->implode("\n"));

        return ActionResponse::file('local', $path, 'posts.csv');
    }
}
```

The same with chained methods, without changing the class:

```php
public function actions(Request $request): array
{
    return [
        RecalculateViews::make()->queued()->onQueue('heavy')->onQueueConnection('redis'),
    ];
}
```

| Property / method | Default | Description |
| --- | --- | --- |
| `$queued` / `queued(bool $queued = true)` | `false` | run on the queue. Only takes effect with notifications enabled; otherwise the action runs synchronously |
| `$queue` / `onQueue(?string $queue)` | `null` | queue name; `null` uses `actions.queue` (`LARSADMIN_QUEUE`), then the connection's default queue |
| `$queueConnection` / `onQueueConnection(?string $connection)` | `null` | queue connection; `null` uses `actions.queue_connection` (`LARSADMIN_QUEUE_CONNECTION`), then `queue.default` |
| `$timeout` | `null` | the job timeout in seconds; `null` uses the worker's `--timeout` |
| `runsQueued()` | | whether a run goes to the queue: `$queued` and the feature is enabled |

### How a run works

1. **Queuing.** The panel checks the same as for a synchronous action (access, `viewAny`, `canSee`, field
   validation), counts the selected records and creates a run in the `larsadmin_action_runs` table. One
   `RunQueuedAction` job goes to the queue, carrying only the run id.
2. **Running.** The worker claims the run atomically (`queued → running`), so a second delivery of the
   same job does nothing. Records are processed in chunks of `chunkSize`, each chunk in its own
   transaction, as in a synchronous action. The progress is updated after each chunk commits.
3. **Finishing.** The run gets a final status (`completed`, `failed`, `cancelled`), the owner gets a
   notification and the application gets the [`ActionRunFinished`](#the-actionrunfinished-event) event.

One job per run, `tries = 1`, no automatic retries: `handle()` is not necessarily idempotent. When the
worker dies or times out, the run ends as `failed`, and the chunks already processed stay saved
("Completed for :done of :total records, then an error occurred."). The user never sees the
exception's text; the exception goes to `report()`. Each run sends its notification exactly once, even
when the job is delivered again or the run is found [stale](#stale-runs) at the same time.

**As the user.** The worker acts as the user who started the action: it retrieves them through the
panel guard's provider, rebuilds a request to the path of the page the action was started from, and for
the duration of the job swaps `request()`, the session, `Auth` and the locale, restoring them afterwards.
The rebuilt request's host comes from `app.url` (or `larsadmin.domain`), not from the original request's
`Host` header. Every check is repeated in the worker (fail closed):

- panel access (`viewLarsadmin`);
- the Entity is still registered and the user has `viewAny`;
- the action is still in `actions()` and passes `canSee()`;
- `authorizedToRunStandalone()` for a `standalone` action, otherwise `authorizedToRun()` for each record;
- records come from `indexQuery()` (for "all matching") or `detailQuery()` (for selected ones).

When the user was deleted, their rights revoked, or the action or Entity removed, the run ends with
"You are not allowed to run this action.". Records that fail `authorizedToRun()` are skipped, as in a
synchronous action. Before every further chunk the worker reloads the user and checks panel access again:
rights revoked in the middle of a long run stop it after the current chunk.

**"All matching"** does not store a list of keys: the worker rebuilds the query from the filters and
search, so new or changed records go through scoping and authorization again. The count is refreshed
when the run starts, so the outcome may differ from what the user saw. The `queued_max_all` cap is
checked again: when more records match by now, the run fails with "Too many records selected…".

**What is stored.** The selection, filters, search, validated field values, the page URL and the user
data for the audit log are kept in a single `payload` column encrypted with `APP_KEY`. When the run ends
(with any status), `payload` is erased. Rotating `APP_KEY` between queuing and running (without
`APP_PREVIOUS_KEYS`) fails the run.

### Queue settings

The queue connection's `retry_after` (`config/queue.php`) must exceed the worker's `--timeout` and the
`$timeout` of every background action. Otherwise the queue hands the job out again while it still runs.
The second delivery does nothing, but the queue holds the job twice. When an action's `$timeout` is not
below `retry_after`, queuing logs a warning.

```php
// config/queue.php
'connections' => [
    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 900, // above --timeout=600 and every background action's $timeout
    ],
],
```

```bash
php artisan queue:work --timeout=600
```

### What `handle()` gets on the queue

- **No session:** `session()`, the `Session` facade and `$request->session()` in `handle()` (and in
  policies and scopes) return a separate empty in-memory session created for this run. It is not shared
  with other runs in the same worker and is gone after the job. The rebuilt `request()` has no cookies,
  files, headers of the original request, or route (`$request->route()` is `null`). If `canSee()`,
  `canRun()` or `fields()` depend on the session or headers, they get empty values on the queue.
- **Field values** are stored the way they survive JSON: dates arrive as ISO-8601 strings
  (`Carbon::parse($fields->published_at)`), enums as their value (`value` or `name`), `NAN` and `INF` as
  `null`. Any other object in a field value throws `LogicException` already when queuing.
- **`File` and `Image` fields** in actions are still not supported (see
  [Actions](07-actions.md#files-in-actions)).

### Limits

| What | Key | Default | What the user sees |
| --- | --- | --- | --- |
| Active runs (`queued` + `running`) per user | `actions.queued_max_active` | `3` | "Too many background tasks. Wait for the previous ones to finish (at most 3)." |
| Records for "all matching" | `actions.queued_max_all` | `10000` | 422, as for a synchronous action |

`actions.max_all` (1000) does not apply to background actions. A list of selected ids is capped the same
way as for a synchronous action.

The active-run limit is atomic: counting and creating the run happen under a per-user cache lock, so
parallel requests cannot get past it. This needs a cache store with locks (`database`, `redis`, `file`,
`memcached` and so on); without one the count runs unlocked. Stale runs do not count towards the limit.

### Cancelling

The "Cancel" button in "Background tasks":

- a run still on the queue is cancelled at once: the worker will not run it;
- a running run stops after the current chunk. The chunk completes (its transaction is not interrupted),
  then processing stops.

A cancelled run has the status `cancelled` and the message "Processed :done of :total records.". When
the run has already finished, the server answers 409. A stale run is cancelled at once. A run that waits
on the queue for more than a minute is marked "Waiting for a queue worker": check that `queue:work` is
running.

### Stale runs

When the worker is gone (the process was killed, the job lost), the run does not stay active forever:

| Status | Counts as stale when |
| --- | --- |
| `running` | there was no heartbeat (`updated_at`, touched after every chunk) for the action's `$timeout` plus 60 s; without `$timeout`, for `actions.stale_after` (3600 s) plus 60 s |
| `queued` | it has waited on the queue for longer than `actions.queued_stale_after` (86,400 s) |

A stale run is finished once with the status `failed` ("An error occurred while running the action." or
"Completed for :done of :total records, then an error occurred."), its owner is notified and `payload` is
erased. The check happens when the active-run limit is counted, when `status` is polled, in the run list
and run view, and in `larsadmin:prune-notifications`. If the worker is still alive and writes progress,
the run is finished without it: the next chunk does not start.

### Results

| `handle()` returned | Status and level | What the user gets |
| --- | --- | --- |
| `null` or `message()` | `completed`, success | the action's message or "Action completed.", plus "N records skipped (not allowed)" |
| `danger()` | `completed`, error | the action's message |
| `redirect($url)` | `completed`, success | an "Open" link in the notification |
| `download($url, $filename)` | `completed`, success | a "Download posts.csv" link |
| `file($disk, $path, $filename)` | `completed`, success | a download of the file, for the run's owner only |
| no record matched | `completed`, warning | "No records matched." |
| no record passed authorization | `failed` | "You are not allowed to run this action." |
| an exception | `failed` | "An error occurred while running the action." or "Completed for :done of :total records, then an error occurred." |
| cancelled | `cancelled`, warning | "Processed :done of :total records." |
| "all matching" exceeded `queued_max_all` in the worker | `failed` | "Too many records selected…" |
| [stale](#stale-runs) | `failed` | as for an exception |

The completion notification is titled "“:action” finished" (or "failed", "was cancelled") and is sent
in the language the user started the action in. Its link is built from the run when read: once the file
is deleted, the link disappears. When the user's model does not use `Notifiable`, no notification is
sent and the run is visible in "Background tasks" only.

### `ActionResponse::file()`

```php
public static function file(string $disk, string $path, ?string $filename = null): ActionResponse
```

The panel serves the file from the disk as a download (`Content-Disposition: attachment`,
`application/octet-stream`, `X-Content-Type-Options: nosniff`). The disk must exist in
`filesystems.disks` and the path must be relative without `..`, otherwise `InvalidArgumentException` is
thrown. `$filename` defaults to the file name in the path; control characters, `/` and `\` are removed
from it.

- **In a background action** the panel copies the file after `handle()` to
  `larsadmin/runs/{run}/{filename}` on the `files.disk` disk (`LARSADMIN_FILES_DISK`, default `local`) and
  serves that copy: `GET {path}/runs/{run}/download`, to the run's owner only, while the copy exists.
  [`larsadmin:prune-notifications`](#pruning) deletes only the copy, together with the run. The panel
  never touches your file: clean up a temporary export yourself (with a scheduled cleanup of its
  directory, say). When copying fails, the error goes to `report()` and the run finishes without a link.
- **In a synchronous action** the browser gets a `GET {path}/downloads/{token}` link. The token is
  encrypted, bound to the user and valid for `files.url_ttl` minutes (30). The file stays on the disk:
  clean it up yourself. This link also works while notifications are off.

Do not pass paths built from user input to `file()`: the panel checks only the shape of the path, not
whether the user may have that particular file. **Give files unique names** (`Str::ulid()`, say): with a
fixed name or one precise to the second, runs of different users write to the same file, and a user can
get someone else's export.

> **CSV and formulas.** If the export will be opened in Excel or LibreOffice, a value starting with `=`,
> `+`, `-`, `@`, a tab or CR runs as a formula. Prefix such a value with `'`:
>
> ```php
> $cell = preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
> ```

### The `sync` driver

With `QUEUE_CONNECTION=sync` (handy in development) the job runs within the same request. The run
finishes before the response, so the user gets the result at once, as from a synchronous action (a
message, a redirect or a download), and the notification is created as well. Timeouts and cancelling do
not apply with `sync`.

### Audit log

When the [audit log](17-audit-log.md) is on, a background action is recorded like a synchronous one: one
entry per processed record. The user, IP address and user agent are taken from the moment the action
was started, not from the worker. All chunks of a run share one `batch_id`, and each entry's `meta` holds
the run id (`run`). Whether to write the log is decided when the run executes.

### The `ActionRunFinished` event

Once a run reaches a final status, `Maxcxam\Larsadmin\Events\ActionRunFinished` is dispatched. Its `run`
property is the `Maxcxam\Larsadmin\Actions\Queued\ActionRun` model (read-only, `payload` already
erased):

```php
use Illuminate\Support\Facades\Event;
use Maxcxam\Larsadmin\Actions\Queued\ActionRun;
use Maxcxam\Larsadmin\Events\ActionRunFinished;

Event::listen(function (ActionRunFinished $event) {
    if ($event->run->status === ActionRun::FAILED) {
        logger()->warning('Larsadmin run failed', $event->run->only('id', 'entity', 'action', 'user_id', 'message'));
    }
});
```

An exception in a listener goes to `report()` and does not change the run's status.

## Realtime

Without any setup the bell polls the server. When the application already uses Reverb or Pusher, the
panel receives signals instantly.

**When it is on.** `notifications.realtime` is `auto` (the default): realtime works when notifications
are enabled and the default broadcasting connection has the `reverb` or `pusher` driver and a non-empty
`key`. `LARSADMIN_REALTIME=false` turns it off, `true` forces it on (with another driver realtime stays
off and a warning is logged once).

**Isolation from the application.**

- The panel opens its own connection on its own pages only (`/{path}/...`) and closes it on logout. The
  application's pages never get the panel's connection.
- The panel never reads or changes the application's `window.Echo` and `window.Pusher`.
- `laravel-echo` and `pusher-js` are bundled in a separate file, `dist/chunks/echo-*.js`, which the
  browser loads only when realtime is on. You do not install them in the application.

**Channel.** Each user has a private channel `private-larsa_inbox.{token}`, where `token` is an
HMAC-SHA256 with `APP_KEY` of a purpose label (`larsadmin.inbox`), the user's type and key (40
characters). The channel name does not reveal the user's id, and equal keys of different models never
collide. Rotating `APP_KEY` changes every token: open tabs lose realtime until they reload, polling keeps
working.

- Panel channels are authorized **only** by the panel's endpoint `POST {path}/broadcasting/auth` (the
  panel guard's session, CSRF, `viewLarsadmin`). It refuses any channel without the panel prefix with 403.
- The application's standard `/broadcasting/auth` does not authorize `larsa_*` channels, not even for
  their owner. The package does not register or change the application's channels.
- `LARSADMIN_CHANNEL_PREFIX` sets the prefix (default `larsa_`): lowercase Latin letters, digits and `_`,
  starting with a letter, ending with `_`, up to 32 characters. Anything else throws
  `InvalidArgumentException`. Change the prefix when several applications share one Reverb or Pusher
  app, and **do not use it for the application's own channels**.

**Signals.** The `.larsadmin.signal` event carries only ids and progress numbers:
`{ "kind": "notification", "id": "…" }` or
`{ "kind": "run", "id": "…", "status": "running", "processed": 400, "total": 1200 }`. There are no titles,
texts or links in a signal: the client fetches them from the panel. So a third-party service (Pusher)
sees ids only. Signals are sent at once (`ShouldBroadcastNow`), without the queue; progress at most once
per second. When the broadcaster is unreachable, the error goes to `report()` and the notification and
the run are not affected. The client counts as connected only once the channel subscription succeeds. When the subscription
fails or the connection drops, the bell keeps working by regular polling.

### Example: Reverb

```bash
composer require laravel/reverb
php artisan reverb:install
```

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=ws.example.com
REVERB_PORT=443
REVERB_SCHEME=https
```

```bash
php artisan reverb:start
```

The browser connects to `broadcasting.connections.reverb.options` (`REVERB_HOST`, `REVERB_PORT`,
`REVERB_SCHEME`). An internal host (`reverb`, `localhost`, a private IP address, names ending in `.local`
or `.internal`) is never sent to the browser: the client then gets no host at all. So whenever
`REVERB_HOST` is internal (Reverb behind an nginx proxy, say), set the public address for the panel
separately:

```dotenv
LARSADMIN_REALTIME_HOST=ws.example.com
LARSADMIN_REALTIME_PORT=443
LARSADMIN_REALTIME_SCHEME=https
```

For Pusher, set `BROADCAST_CONNECTION=pusher` and the `PUSHER_*` keys as for any Laravel application;
the cluster and TLS come from `broadcasting.connections.pusher.options`. The secret and `app_id` are
never sent to the browser.

### CSP

If the application sets a Content-Security-Policy for the panel's pages:

- `script-src 'self'` is enough: the client file lives in `/vendor/larsadmin/chunks/` on the same origin;
- `connect-src` must include the WebSocket address: `wss://ws.example.com:443` (Reverb) or
  `wss://ws-{cluster}.pusher.com` (Pusher). pusher-js's HTTP fallback transports and stats are off, so
  `sockjs.pusher.com` and `stats.pusher.com` are not needed.

## Configuration

`config/larsadmin.php`:

```php
'actions' => [
    'max_all' => 1000,
    'queued_max_all' => 10000,
    'queued_max_active' => 3,
    'stale_after' => 3600,
    'queued_stale_after' => 86400,
    'queue' => env('LARSADMIN_QUEUE'),
    'queue_connection' => env('LARSADMIN_QUEUE_CONNECTION'),
    'runs_table' => 'larsadmin_action_runs',
    'runs_connection' => env('LARSADMIN_RUNS_CONNECTION'),
],

'notifications' => [
    'enabled' => env('LARSADMIN_NOTIFICATIONS', false),
    'poll_interval' => 60,
    'per_page' => 15,
    'retention_days' => 30,
    'throttle' => 120,
    'realtime' => env('LARSADMIN_REALTIME', 'auto'),
    'channel_prefix' => env('LARSADMIN_CHANNEL_PREFIX', 'larsa_'),
    'realtime_client' => [
        'host' => env('LARSADMIN_REALTIME_HOST'),
        'port' => env('LARSADMIN_REALTIME_PORT'),
        'scheme' => env('LARSADMIN_REALTIME_SCHEME'),
    ],
],
```

| Key | Environment variable | Default | Description |
| --- | --- | --- | --- |
| `notifications.enabled` | `LARSADMIN_NOTIFICATIONS` | `false` | Turns on the bell and background actions. Off: the new routes answer 404, `$queued` actions run synchronously |
| `notifications.poll_interval` | | `60` | Polling interval in seconds: `0` (only on navigation) or 15..3600 |
| `notifications.per_page` | | `15` | Notifications per bell page; 5..50 |
| `notifications.retention_days` | | `30` | Days to keep for `larsadmin:prune-notifications`; `null` keeps everything |
| `notifications.throttle` | | `120` | Requests per minute per user to the bell, run and `broadcasting/auth` endpoints (limiter `larsadmin-notifications`); `null` disables it |
| `notifications.realtime` | `LARSADMIN_REALTIME` | `auto` | `auto`, `true` or `false` (see [Realtime](#realtime)) |
| `notifications.channel_prefix` | `LARSADMIN_CHANNEL_PREFIX` | `larsa_` | Prefix of the panel's channels |
| `notifications.realtime_client.*` | `LARSADMIN_REALTIME_HOST`, `_PORT`, `_SCHEME` | `null` | The public WebSocket address for the browser, when it differs from the broadcasting config |
| `actions.queued_max_all` | | `10000` | Maximum number of records for a background action on "all matching" records |
| `actions.queued_max_active` | | `3` | Active background runs per user |
| `actions.stale_after` | | `3600` | Seconds without a heartbeat after which a `running` run without `$timeout` counts as stale (plus 60 s) |
| `actions.queued_stale_after` | | `86400` | Seconds on the queue after which a `queued` run counts as stale |
| `actions.queue` | `LARSADMIN_QUEUE` | `null` | Default queue for background actions |
| `actions.queue_connection` | `LARSADMIN_QUEUE_CONNECTION` | `null` | Default queue connection |
| `actions.runs_table` | | `larsadmin_action_runs` | The runs table. Change it before running the migration |
| `actions.runs_connection` | `LARSADMIN_RUNS_CONNECTION` | `null` | Database connection of the runs table |

A config published earlier without these keys works with the defaults, that is with the feature off.

### Migrations

| Table | Source |
| --- | --- |
| `notifications` | Laravel's standard table: `php artisan make:notifications-table`. The package does not ship it; if you already have it, there is nothing to do |
| `larsadmin_action_runs` | the package migration: `php artisan vendor:publish --tag=larsadmin-migrations` |

The migration takes the runs table and connection from `actions.runs_table` and `actions.runs_connection`,
so set them before `migrate`.

## Pruning

```bash
php artisan larsadmin:prune-notifications           # older than notifications.retention_days (30 days)
php artisan larsadmin:prune-notifications --days=7  # older than 7 days
```

The command first finishes [stale](#stale-runs) runs (notifying their owners), then deletes, in batches
of 1000:

- panel notifications (type `larsadmin`), read or not. The application's other notifications are left
  alone;
- background action runs created before the cutoff, including stale ones still `queued` or `running`;
- the panel's copies of those runs' `ActionResponse::file()` results (`larsadmin/runs/{run}/…`). Files
  outside a run's directory are never deleted.

`--days` must be an integer of at least 1. If `retention_days` is `null` and `--days` is not given, the
command deletes nothing. The command also works while notifications are off; it skips a missing table
with a warning.

The package does not register a schedule itself. Add the command to the application's scheduler:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('larsadmin:prune-notifications')->daily();
```

## Security

- **Own data only.** Every bell request is limited to the current user's notifications of type
  `larsadmin`; another user's, an unknown or a non-panel notification answers 404. Runs likewise:
  another user's run answers 404, and administrators do not see other users' runs. A 404 never contains
  a model class name.
- **Isolation in the worker.** Each run gets its own empty session; the rebuilt request's host comes from
  `app.url`, not from the `Host` header; the active-run limit is atomic.
- **Re-authorization** in the background worker as the user, fail closed (see
  [How a run works](#how-a-run-works)).
- **Encrypted values.** Action field values (including `Password`) and the selection are stored
  encrypted and erased when the run ends. A run's JSON never contains `payload` or the file's disk and
  path.
- **No content in signals.** Only ids and progress go through broadcasting.
- **XSS and redirects.** Texts are shown as plain text, levels and icons come from allow-lists, links are
  checked when created and when read.
- **CSRF and caching.** Changes (`POST`, `DELETE`) are protected by the `web` group's CSRF check; every
  JSON response has `Cache-Control: no-store, private`.
- **Personal data.** Notifications and run messages may contain personal data. Choose `retention_days`
  according to your privacy requirements.

## HTTP endpoints

All routes run in the `auth_middleware` group and answer 404 while notifications are off (except
`downloads/{token}`).

| Route | Name | Response |
| --- | --- | --- |
| `GET {path}/api/notifications?cursor=…` | `larsadmin.api.notifications` | `{ items, unread, nextCursor, timezone }` |
| `GET {path}/api/notifications/status` | `larsadmin.api.notifications.status` | `{ unread, latest, activeRuns }` |
| `POST {path}/api/notifications/read` | `larsadmin.api.notifications.read-all` | `{ unread: 0 }` |
| `POST {path}/api/notifications/{id}/read` | `larsadmin.api.notifications.read` | `{ unread }` |
| `DELETE {path}/api/notifications/{id}` | `larsadmin.api.notifications.destroy` | `{ unread }` |
| `GET {path}/api/runs` | `larsadmin.api.runs` | `{ runs }`: active ones and those finished in 24 hours, up to 10 |
| `GET {path}/api/runs/{run}` | `larsadmin.api.runs.show` | the run |
| `POST {path}/api/runs/{run}/cancel` | `larsadmin.api.runs.cancel` | the run; 409 when already finished |
| `POST {path}/broadcasting/auth` | `larsadmin.broadcasting.auth` | the channel signature; 404 while realtime is off |
| `GET {path}/runs/{run}/download` | `larsadmin.runs.download` | the `file()` of a background action, to its owner only |
| `GET {path}/downloads/{token}` | `larsadmin.downloads.show` | the `file()` of a synchronous action |

The first nine routes use the `larsadmin-notifications` limiter, the downloads the `larsadmin-files`
limiter. A guest gets 401, a user without `viewLarsadmin` 403, an invalid `cursor` 422.

```json
{
  "items": [
    {
      "id": "9b2c4e1a-…",
      "title": "“Export CSV” finished",
      "body": "Action completed.",
      "level": "success",
      "icon": "download",
      "url": "/admin/runs/01jc…/download",
      "urlLabel": "Download posts.csv",
      "download": true,
      "read": false,
      "createdAt": "2026-11-02T14:05:11+02:00",
      "run": "01jc…"
    }
  ],
  "unread": 3,
  "nextCursor": null,
  "timezone": "Europe/Kyiv"
}
```

The frontend gets the bell's settings from the shared Inertia prop `larsadmin.notifications`: `null` (no
bell) or `{ unread, poll, urls, realtime }`. `unread` is `null` when the table is missing; `realtime`
holds public values only (driver, key, host, port, channel), never the secret.

## Performance

| Operation | Extra |
| --- | --- |
| A panel page with notifications on | 1 `COUNT` of unread notifications |
| Polling `status` | 1 `COUNT` of unread, 1 `MAX(created_at)`, 1 `COUNT` of active runs |
| Queuing a background action | 1 `COUNT` of active runs, 1 `COUNT` of the selection, 1 `INSERT` and the dispatch |
| Each chunk in the worker | as in a synchronous action, plus 1 progress `UPDATE` and 1 `SELECT` of the cancel flag |
| Notifications off | nothing |

The runs table has indexes for a user's runs and for pruning. The realtime client does not grow the main
`app.js`: its file loads separately and only when realtime is on.

## Limitations

- The bell shows only notifications of type `larsadmin`. The application's other notifications are not
  visible in the panel.
- Only the user model of the panel's guard with `Notifiable` sees notifications.
- Administrators can neither see nor cancel other users' background runs.
- `File` and `Image` fields in actions, background ones included, are not supported.
- A background action is never retried automatically. Run it again by hand when it failed.
- Timeouts and cancelling do not apply with the `sync` driver.
- The session, cookies and headers of the original request are not available in a background action's
  `handle()`.
- The timeout used to detect a stale run is read from the encrypted `payload`, not from a column of its
  own.
- A stale run is detected only when something checks it (the limit, polling, the run list,
  `larsadmin:prune-notifications`). Schedule `larsadmin:prune-notifications` daily.

Next: [Extensions: the public API](19-extensions.md).
