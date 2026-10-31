---
name: larsadmin-notifications-realtime
description: "Larsadmin notifications and realtime: sending LarsadminNotification to panel users, the notification bell, polling, broadcasting with Reverb or Pusher, channels and pruning old notifications. Use when notifying admin users or enabling live updates in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin notifications and realtime

## When to use

- Turn on the bell in the panel header and send notifications to panel users from application code.
- Enable background (queued) actions: they need this feature on (the action side is in `larsadmin-actions`).
- Push bell updates instantly through Reverb or Pusher instead of polling.
- Prune old notifications and finished action runs.

## Quick start

1. Create Laravel's `notifications` table (skip if you have it) and publish the panel migrations:

   ```bash
   php artisan make:notifications-table
   php artisan vendor:publish --tag=larsadmin-migrations
   php artisan migrate
   ```

2. The panel user model uses the `Notifiable` trait (the default `App\Models\User` does).
3. In `.env`: `LARSADMIN_NOTIFICATIONS=true` and a real queue, e.g. `QUEUE_CONNECTION=database`.
4. Run a worker: `php artisan queue:work --timeout=600` (the queue connection's `retry_after` must exceed
   `--timeout` and every background action's `$timeout`).
5. Schedule pruning in `routes/console.php`:

   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('larsadmin:prune-notifications')->daily();
   ```

The bell now shows for users of the panel guard. Without realtime it polls every
`larsadmin.notifications.poll_interval` seconds while the tab is visible.

## Recipes

### Send a notification

```php
use App\Models\User;
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

- Only notifiables with `Notifiable` of the panel guard's user model see it in the bell.
- `url()` accepts only same-application links: a path starting with a single `/`, or an absolute
  `http(s)` URL on the request host, the `APP_URL` host or `larsadmin.domain`. Anything else throws
  `InvalidArgumentException`. Pass `false` as the third `route()` argument for a relative path.
- Title and body are plain text (no HTML). Translate them yourself (`HasLocalePreference`, `->locale()`).
- The class does not queue itself; storing is one `INSERT`. Add `ShouldQueue` in a subclass to queue it.

### Your own notification class (extra channels)

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

Keep `database` in `via()` and do not override `databaseType()` or `toArray()`: the bell finds and renders
notifications of type `larsadmin` by them.

### Background actions

Mark an action `public bool $queued = true;` (or `->queued()` when registering it). It runs on the queue only
while notifications are enabled; otherwise it runs synchronously. Progress, cancelling and the result show in
the bell's "Background tasks". Defaults for all background actions: `LARSADMIN_QUEUE`,
`LARSADMIN_QUEUE_CONNECTION`; the runs table connection: `LARSADMIN_RUNS_CONNECTION`. Details, limits and
`ActionRunFinished`: `larsadmin-actions`.

### Realtime with Reverb

```bash
composer require laravel/reverb
php artisan reverb:install
php artisan reverb:start
```

With `BROADCAST_CONNECTION=reverb` (or `pusher`) and a non-empty key, `LARSADMIN_REALTIME=auto` (default) turns
realtime on. The client (`laravel-echo`, `pusher-js`) is bundled in the panel; install nothing in the app.
When `REVERB_HOST` is internal (`localhost`, `reverb`, a private IP, `.local`, `.internal`), the panel never
sends it to the browser; set the public address:

```dotenv
LARSADMIN_REALTIME_HOST=ws.example.com
LARSADMIN_REALTIME_PORT=443
LARSADMIN_REALTIME_SCHEME=https
```

With a CSP, add the WebSocket address to `connect-src` (e.g. `wss://ws.example.com:443` or
`wss://ws-{cluster}.pusher.com`).

### Share one Reverb or Pusher app between applications

Set a distinct `LARSADMIN_CHANNEL_PREFIX` (default `larsa_`; lowercase letters, digits and `_`, starts with a
letter, ends with `_`, at most 32 characters). Never reuse the prefix for the application's own channels.

## API cheat sheet

| `LarsadminNotification` method | Notes |
|---|---|
| `make(string $title)` | trimmed, cut to 200 characters; empty throws |
| `title()`, `body(?string)` | body up to 2000 characters, plain text |
| `level(string)`, `info()`, `success()`, `warning()`, `danger()` | other levels throw |
| `icon(?string)` | `bell`, `check`, `alert`, `info`, `download`, `file`, `history` or `null` |
| `url(?string $url, ?string $label = null)` | same-application links only |
| `sendTo($notifiables)` | `Notification::send($notifiables, $this)` |

| Config key (env) | Default |
|---|---|
| `larsadmin.notifications.enabled` (`LARSADMIN_NOTIFICATIONS`) | `false`; off = routes 404, queued actions run synchronously |
| `larsadmin.notifications.poll_interval` | `60` seconds; `0` = only on navigation, else 15..3600 |
| `larsadmin.notifications.per_page` | `15` (5..50) |
| `larsadmin.notifications.retention_days` | `30`; `null` keeps everything |
| `larsadmin.notifications.throttle` | `120` requests per minute (limiter `larsadmin-notifications`) |
| `larsadmin.notifications.realtime` (`LARSADMIN_REALTIME`) | `auto`, `true` or `false` |
| `larsadmin.notifications.channel_prefix` (`LARSADMIN_CHANNEL_PREFIX`) | `larsa_` |
| `larsadmin.notifications.realtime_client.host` / `.port` / `.scheme` | public WebSocket address, `null` |

`php artisan larsadmin:prune-notifications [--days=N]` first finishes stale runs, then deletes panel
notifications, runs older than the cutoff and the panel's copies of their result files. Application
notifications of other types are never touched.

Each user listens on a private channel `private-larsa_inbox.{token}` (an HMAC of the user, not the id),
authorized only by the panel endpoint `POST {path}/broadcasting/auth`. Signals carry ids and progress
numbers only, never titles or texts.

## Security rules

- MUST keep links in notifications inside the application; never build `url()` from untrusted input.
- MUST treat notification texts as personal data: choose `retention_days` per your privacy rules and
  schedule `larsadmin:prune-notifications`.
- MUST set `LARSADMIN_REALTIME_HOST` to a public address instead of exposing internal hosts.
- NEVER put secrets or record data you would not show to the recipient into a notification body.
- NEVER authorize `larsa_*` channels in the application's `routes/channels.php`; the panel's endpoint owns them.
- NEVER use the panel's channel prefix for your own channels.

## Pitfalls

- The bell shows only type `larsadmin` notifications of the panel guard's user model; other notifications
  and other guards never appear.
- With the feature on and tables missing, pages work but the bell has no counter, and queuing a background
  action throws ("run the larsadmin migrations ... make:notifications-table").
- `QUEUE_CONNECTION=sync` runs background actions within the request: no timeouts, no cancelling.
- `retry_after` below a worker's `--timeout` hands the job out twice (the second delivery does nothing).
- Rotating `APP_KEY` changes channel tokens: open tabs fall back to polling until reloaded.
- `LARSADMIN_REALTIME=true` with a driver other than `reverb`/`pusher` keeps realtime off and logs a warning.

## Reference

- Guide: [vendor/maxcxam/larsadmin/docs/guide/en/18-notifications.md](vendor/maxcxam/larsadmin/docs/guide/en/18-notifications.md)
  (the bell, background actions, stale runs, realtime, HTTP endpoints).
- Background actions: `larsadmin-actions`. UI of the bell: `larsadmin-ui-i18n-theming`.
- MCP (when connected): `read-docs page=18-notifications section=realtime`, `search-docs query="channel prefix"`.
