# Larsadmin

An admin panel for Laravel, built with Inertia and Vue 3: describe each Eloquent model once as
an **Entity** and get lists, forms, filters, actions, metrics and dashboards.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maxcxam/larsadmin.svg)](https://packagist.org/packages/maxcxam/larsadmin)
[![Total Downloads](https://img.shields.io/packagist/dt/maxcxam/larsadmin.svg)](https://packagist.org/packages/maxcxam/larsadmin)
[![Tests](https://github.com/maxcxam/larsadmin/actions/workflows/tests.yml/badge.svg)](https://github.com/maxcxam/larsadmin/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/maxcxam/larsadmin.svg)](LICENSE)

**Website:** [larsadmin.dev](https://larsadmin.dev) · [Documentation](https://larsadmin.dev/en/docs) · [Live demo](https://larsadmin.dev/demo)

## Features

- **Entities**: one class per model with fields, search (including `relation.column`), sorting,
  pagination and row scoping (`indexQuery`, `detailQuery`, `relatableQuery`).
- **Fields**: `ID`, `Text`, `Textarea`, `Number`, `Boolean`, `Date`, `DateTime`, `Password`, `Select`
  (enums too), with per-context and per-record visibility, read-only rules and validation.
- **Files and images**: `File` and `Image` fields, one file or several (`multiple()`), public and
  private disks including S3, signed re-authorized links for private files, deletion of replaced files.
- **Global search**: a Ctrl/⌘+K command palette across the Entities that opt in, over their `$search`
  columns or through Laravel Scout, limited to what policies and `indexQuery()` let the user see.
- **Audit log** (opt-in): who changed what through the panel, with "before → after" values per field,
  written in the same transaction as the change; masking of sensitive fields, a history panel on each
  record and an "Audit log" page behind its own gate, retention with `larsadmin:prune-audit`.
- **Notifications and background actions** (opt-in): a bell in the header with the user's panel
  notifications, sent by your code with `LarsadminNotification`; actions marked `$queued` run on the
  Laravel queue as the user who started them, with progress, cancelling, a result notification and
  owner-only file downloads (`ActionResponse::file()`); stale runs of a lost worker are failed. Polls by default, realtime through Reverb or
  Pusher when configured, with the client bundled (nothing to install).
- **Relations**: `BelongsTo` (searchable), `HasMany` and `BelongsToMany` panels with attach/detach.
- **Field blocks**: group form and detail fields into panels (one or two columns, collapsible) and tabs.
- **Filters**: select, boolean and date range filters, plus your own.
- **Actions**: bulk, standalone and per-row ("⋯" menu) actions with modal fields (extension fields
  included), confirmation, batching and per-record availability.
- **Metrics and dashboards**: Value, Trend, Partition and Progress cards, scoped to what the user may
  see, on dashboards or above an entity list.
- **Security by default**: panel gate, model policies that fail closed, server-built record URLs.
- **UI**: GitHub-like interface with light, dark and system themes, column picker, responsive layout.
- **i18n**: Ukrainian (default) and English, with a language switcher.
- **Extensions** (public API since 1.5): Composer packages add their own Vue fields, dashboard cards
  (static or with data from an authorized, cached endpoint), filters and sidebar pages, their own routes
  and translations, and can override the UI of selected built-in components. Installed with
  `composer require` alone; fail closed when an extension is missing or broken. Scaffolds, a Vite preset
  and test helpers for authors; since 1.6 record abilities for fields (`authorizedTo()`), extension fields
  in action modals and the public `FileSafety` for extensions that store or serve files.
- **AI assistants** (since 1.6, opt-in): 15 skills and a guideline for Laravel Boost or
  `php artisan larsadmin:ai`, and a local MCP server (`php artisan larsadmin:mcp`, needs the optional
  `laravel/mcp`) with guide search, read-only metadata of your entities and the generators. Never runs in
  production.
- **Generators** for entities, actions, filters, metrics, dashboards and extensions.

The frontend ships prebuilt: your application does not need Node.js, also with extensions installed.

Inspired by the developer experience of Laravel Nova: an Entity plays the role of a Nova Resource.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require maxcxam/larsadmin
php artisan larsadmin:install
```

`larsadmin:install` publishes `config/larsadmin.php`, the assets (`public/vendor/larsadmin`) and
`app/Providers/LarsadminServiceProvider.php` (registered in `bootstrap/providers.php`), publishes the
package migrations (audit log, background action runs), and copies `lang/uk/validation.php` if your app
does not have one yet. The audit log stays off until you run the migration and enable it (see the
[audit log guide](docs/guide/en/17-audit-log.md#quick-start)). Notifications and background actions are
off too (see the [notifications guide](docs/guide/en/18-notifications.md#quick-start)).

The panel lives at `/admin`. After updating the package, refresh the assets:

```bash
composer update maxcxam/larsadmin
php artisan larsadmin:publish
```

## Quick start

### An entity

```bash
php artisan larsadmin:entity Post --model=Post
```

Classes in `app/Larsadmin` are registered automatically.

```php
namespace App\Larsadmin;

use App\Larsadmin\Actions\Publish;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Filters\SelectFilter;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;
    public static string $title = 'title';
    public static array $search = ['id', 'title'];

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Title', 'title')->sortable()->rules('required', 'max:255'),
            BelongsTo::make('Author', 'author', User::class)->searchable()->nullable(),
            Select::make('Status', 'status')->rules('required')->options([
                'draft' => 'Draft',
                'live' => 'Live',
            ]),
            Textarea::make('Body', 'body')->nullable(),
            DateTime::make('Published at', 'published_at')->nullable()->sortable(),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            SelectFilter::make('Status', 'status')->withOptions(['draft' => 'Draft', 'live' => 'Live']),
        ];
    }

    public function actions(Request $request): array
    {
        return [
            Publish::make()->showInline(), // bulk menu, record page and the row "⋯" menu
        ];
    }
}
```

`BelongsTo` points at the related **entity** (`App\Larsadmin\User` here), not the model.

### An action

```bash
php artisan larsadmin:action Publish
```

```php
namespace App\Larsadmin\Actions;

use Illuminate\Support\Collection;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Actions\ActionFields;
use Maxcxam\Larsadmin\Actions\ActionResponse;

class Publish extends Action
{
    public function handle(ActionFields $fields, Collection $models): ?ActionResponse
    {
        $models->each->update(['status' => 'live', 'published_at' => now()]);

        return ActionResponse::message("Published: {$models->count()}");
    }
}
```

By default an action may run on the records the user can `update`; `canRun()` replaces that check.

### A metric and a dashboard

```bash
php artisan larsadmin:value NewPosts
php artisan larsadmin:dashboard Overview
```

```php
namespace App\Larsadmin\Metrics;

use App\Larsadmin\Post;
use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\ValueResult;
use Maxcxam\Larsadmin\Metrics\Value;

class NewPosts extends Value
{
    public function calculate(MetricRequest $request): ValueResult
    {
        // An Entity class: counts only the rows the user may see (indexQuery + viewAny).
        return $this->count($request, Post::class)->allowZeroResult(); // show 0 as a number
    }
}
```

```php
namespace App\Larsadmin\Dashboards;

use App\Larsadmin\Metrics\NewPosts;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Dashboard;

class Overview extends Dashboard
{
    public function cards(Request $request): array
    {
        return [
            NewPosts::make()->help('Posts created in the selected period.'),
        ];
    }
}
```

Register the dashboard in `app/Providers/LarsadminServiceProvider.php`; the first one the user may see
becomes the home page:

```php
protected function dashboards(): array
{
    return [
        new \App\Larsadmin\Dashboards\Overview,
    ];
}
```

> Metric helpers given an Entity class (or `$this->scoped(Post::class, fn ($query) => ...)`) are scoped
> by the entity's `indexQuery()` and need `viewAny`. A **model class or a query builder is not scoped**
> and aggregates every row it can reach: use it for global numbers only and hide the card with
> `canSee()` when needed.

### An extension

```bash
php artisan larsadmin:extension acme/larsadmin-rating
composer config repositories.acme-larsadmin-rating path packages/acme/larsadmin-rating
composer require acme/larsadmin-rating:@dev
cd packages/acme/larsadmin-rating && npm install && npm run build
```

The package registers itself in its service provider and ships its built files:

```php
Larsadmin::extension('acme/larsadmin-rating', __DIR__.'/../dist')->translations('acme-rating');
```

```js
// resources/js/extension.js
import { defineExtension } from 'larsadmin'
import RatingInput from './fields/RatingInput.vue'
import RatingDisplay from './fields/RatingDisplay.vue'

export default defineExtension({
    name: 'acme/larsadmin-rating',
    requires: '^1.6',
    fields: { 'acme/rating': { form: RatingInput, display: RatingDisplay } },
})
```

A field class with `public string $component = 'acme/rating'` is then used like any other field. Extension
code is trusted code that runs with the administrator's rights; the server still authorizes everything. See
the [extensions guide](docs/guide/en/19-extensions.md).

## AI assistants

The package ships skills and a guideline for AI coding assistants (Claude Code, Cursor and others) in
`resources/boost/`, and a local MCP server. Both are optional and meant for development.

```bash
# With Laravel Boost 2.x: picks up the larsadmin guideline and skills
php artisan boost:install

# Without Boost: copy the skills to .claude/skills and add the MCP server to .mcp.json
composer require laravel/mcp --dev
php artisan larsadmin:ai --skills --mcp
```

The `larsadmin` MCP server (`php artisan larsadmin:mcp`, stdio) offers `search-docs`, `read-docs`,
`application-info`, `list-entities`, `describe-entity`, `list-dashboards`, `list-extensions` and `generate`.
It returns metadata only (never record data or secrets) and writes files only through `generate` with
`write: true`. It runs only with `laravel/mcp` installed, by default only when `APP_ENV=local`
(`LARSADMIN_MCP=true` also allows other non-production environments) and never in production. See the
[AI assistants guide](docs/guide/en/20-ai-assistants.md).

## Configuration

`config/larsadmin.php`:

| Key | Env | Default | Purpose |
| --- | --- | --- | --- |
| `name` | `LARSADMIN_NAME` | `APP_NAME` + ` Admin` | Panel title |
| `domain` | `LARSADMIN_DOMAIN` | `null` | Serve the panel on a domain |
| `path` | `LARSADMIN_PATH` | `admin` | URI prefix |
| `guard` | `LARSADMIN_GUARD` | `null` | Authentication guard |
| `locale` | `LARSADMIN_LOCALE` | `uk` | Panel language (`null` keeps the app locale) |
| `locales` | | `uk`, `en` | Languages offered by the switcher |
| `timezone` | `LARSADMIN_TIMEZONE` | `null` (`app.timezone`) | Timezone metric periods are aligned to |
| `metrics.cache_store` | | `null` | Cache store for metrics using `cacheFor()` |
| `middleware`, `auth_middleware` | | | Middleware of all / authenticated panel routes |
| `allow_without_policy` | | `true` | Allow models that have no policy |
| `allow_local` | `LARSADMIN_ALLOW_LOCAL` | `true` | Skip the gate when `APP_ENV=local` |
| `actions.max_all` | | `1000` | Limit for "run on all matching records" |
| `actions.queued_max_all`, `actions.queued_max_active` | | `10000`, `3` | Background action limits: "all matching" records, active runs per user |
| `actions.stale_after`, `actions.queued_stale_after` | | `3600`, `86400` | Seconds after which a running run without `$timeout` (no heartbeat, plus 60 s) or a queued run counts as stale and is failed |
| `actions.queue`, `actions.queue_connection` | `LARSADMIN_QUEUE`, `LARSADMIN_QUEUE_CONNECTION` | `null` | Default queue and connection of background actions |
| `search.*` | | `limit` 5, `min_length` 2, `throttle` 60 | Global search; see the [search guide](docs/guide/en/16-search.md#configuration) |
| `audit.enabled` | `LARSADMIN_AUDIT` | `false` | Turn the audit log on (publish and run the migration first) |
| `audit.connection` | `LARSADMIN_AUDIT_CONNECTION` | `null` | Database connection of the audit log |
| `audit.*` | | `retention_days` 90, `store_ip` / `store_user_agent` `true`, `max_value_length` 1000, `per_page` 25, `throttle` 60 | Audit log; see the [audit log guide](docs/guide/en/17-audit-log.md#configuration) |
| `notifications.enabled` | `LARSADMIN_NOTIFICATIONS` | `false` | Turn on the bell and background actions (run the migrations first) |
| `notifications.realtime` | `LARSADMIN_REALTIME` | `auto` | Realtime through Reverb/Pusher: `auto`, `true` or `false` |
| `notifications.channel_prefix` | `LARSADMIN_CHANNEL_PREFIX` | `larsa_` | Prefix of the panel's broadcast channels |
| `notifications.*` | | `poll_interval` 60, `per_page` 15, `retention_days` 30, `throttle` 120 | Notifications; see the [notifications guide](docs/guide/en/18-notifications.md#configuration) |
| `extensions.source_maps` | `LARSADMIN_EXTENSION_SOURCE_MAPS` | `false` | Serve extensions' source maps (debugging only) |
| `extensions.max_age` | | `31536000` | Browser cache lifetime of versioned extension files, in seconds |
| `mcp.enabled` | `LARSADMIN_MCP` | `null` | Local MCP server: `null` only with `APP_ENV=local`, `true` any non-production environment, `false` off; never in production |

## Authorization

- **Panel access.** Outside `APP_ENV=local`, only users passing the `viewLarsadmin` gate get in. The gate
  denies everyone until you open it in `app/Providers/LarsadminServiceProvider.php`:

  ```php
  protected function gate(): void
  {
      Gate::define('viewLarsadmin', fn ($user) => in_array($user->email, ['admin@example.com']));
  }
  ```

- **Local environment.** While `APP_ENV=local` and `allow_local` is `true`, **every authenticated user**
  can open the panel. Set `LARSADMIN_ALLOW_LOCAL=false` on any shared or public server.
- **Policies fail closed.** When a model has a policy, it decides everything (`viewAny`, `view`,
  `create`, `update`, `delete`, attach/detach); a missing policy method means "deny". Models without a
  policy are allowed while `allow_without_policy` is `true`.
- **Row scoping.** `indexQuery()` and `detailQuery()` limit what a user sees; records outside them
  answer 404.
- **Audit log.** Reading the log needs the `viewLarsadminAudit` gate, which the package never defines:
  nobody sees the log until you define it (`allow_local` does not apply). The global "Audit log" page
  is not row-scoped: a holder of the gate sees every entry of each Entity they have `viewAny` on, also
  outside their `indexQuery()`.
- **Background actions** re-authorize everything in the queue worker as the user who started them and
  fail closed. Bell notifications and runs are visible to their owner only; the panel's realtime
  channels are authorized only by the panel's own endpoint.

## Localization

The panel speaks Ukrainian (default) and English. Users switch the language in the header; you can
also resolve it yourself with `Larsadmin::resolveLocaleUsing()`. Publish the translations to change
them:

```bash
php artisan vendor:publish --tag=larsadmin-lang
```

## Theming

Light, dark and system themes, switchable in the header and on the login page. The choice is kept in
the browser. The panel's own theme cannot be changed through an API; the `--la-*` design tokens and the
`larsadmin-*` component classes are a stable contract for extensions since 1.5. See the
[theming guide](docs/guide/en/09-theming.md).

## Documentation

Full guides, in English. The Ukrainian originals are in [`docs/guide/`](docs/guide/); every page links to
its translation and to the same page on [larsadmin.dev/en/docs](https://larsadmin.dev/en/docs), which has
the guides of the latest release with search ([українською](https://larsadmin.dev/uk/docs)).

| Guide | Topics |
| --- | --- |
| [01. Installation](docs/guide/en/01-installation.md) | requirements, publish tags, config, commands |
| [02. Entities](docs/guide/en/02-entities.md) | Entity API, queries, generator |
| [03. Fields](docs/guide/en/03-fields.md) | field types, visibility, validation, list columns |
| [04. Relations](docs/guide/en/04-relations.md) | BelongsTo, HasMany, BelongsToMany |
| [05. Authorization](docs/guide/en/05-authorization.md) | panel gate, policies, row scoping |
| [06. Filters](docs/guide/en/06-filters.md) | built-in and custom filters |
| [07. Actions](docs/guide/en/07-actions.md) | bulk and row actions, responses, background actions, generator |
| [08. Localization](docs/guide/en/08-i18n.md) | languages, translations, switcher |
| [09. Theming](docs/guide/en/09-theming.md) | tokens, light/dark/system, CSS contract for extensions |
| [10. Extending](docs/guide/en/10-extending.md) | extension points in PHP, the component registry, built-in keys |
| [11. List UI](docs/guide/en/11-ui.md) | column picker, responsive layout |
| [12. Field blocks](docs/guide/en/12-layout.md) | panels, tabs, two columns |
| [13. Metrics](docs/guide/en/13-metrics.md) | Value, Trend, Partition, Progress, scoping, caching |
| [14. Dashboards](docs/guide/en/14-dashboards.md) | dashboards, home page, entity cards |
| [15. Files and images](docs/guide/en/15-files.md) | File and Image fields, disks, private files, pruning |
| [16. Global search](docs/guide/en/16-search.md) | Ctrl/⌘+K palette, opt-in, subtitles, Laravel Scout, authorization |
| [17. Audit log](docs/guide/en/17-audit-log.md) | enabling, what is recorded, masking, gate, history UI, pruning, `AuditRecorded` |
| [18. Notifications and background actions](docs/guide/en/18-notifications.md) | the bell, `LarsadminNotification`, queued actions, `ActionResponse::file()`, realtime (Reverb/Pusher), pruning |
| [19. Extensions: the public API](docs/guide/en/19-extensions.md) | scaffolds, `Larsadmin::extension()`, `defineExtension`, custom fields, `DataCard`, tools, extension routes, overrides, fail closed, CSP, versioning, testing |
| [20. AI assistants](docs/guide/en/20-ai-assistants.md) | skills, Laravel Boost, `larsadmin:ai`, the MCP server and its tools, security model |
| [21. Testing](docs/guide/en/21-testing.md) | testing entities, policies, actions, filters, metrics and extensions, `InteractsWithLarsadmin` |

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Security

Please do not report vulnerabilities in public issues. Use GitHub private vulnerability reporting
as described in [SECURITY.md](SECURITY.md).

## License

Larsadmin is open-source software licensed under the [MIT License](LICENSE) since version 1.0.2.
Versions 1.0.0 and 1.0.1 were released under GPL-3.0-only.

Larsadmin is an independent project and is not affiliated with, sponsored or endorsed by Laravel
Holdings Inc. or Laravel Nova. Laravel and Nova are trademarks of their respective owners.
