# 01. Installation
🇺🇦 [Українська](../01-installation.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/en/docs/installation)

## Requirements

| Component | Version |
| --- | --- |
| PHP | 8.3+ (CI tests 8.3, 8.4, 8.5) |
| Laravel | 12 or 13 |
| Inertia (server side) | `inertiajs/inertia-laravel` ^3.0 (installed with the package) |
| `laravel/framework` | ^12.0 or ^13.0 (a required dependency of the package) |

The panel frontend ships prebuilt (`dist/`), so your application does not need Node.js.
The panel uses its own client bundle and disables Inertia SSR for its routes.

## Installing

```bash
composer require maxcxam/larsadmin
php artisan larsadmin:install
```

`larsadmin:install` does the following, in order:

1. publishes the config (`larsadmin-config`) to `config/larsadmin.php`;
2. publishes the assets (`larsadmin-assets`) to `public/vendor/larsadmin`, overwriting existing files;
3. publishes the service provider (`larsadmin-provider`) to `app/Providers/LarsadminServiceProvider.php`
   and adds it to `bootstrap/providers.php`;
4. publishes the package migrations (`larsadmin-migrations`) to `database/migrations`: the audit log and
   the background action runs;
5. copies `lang/uk/validation.php`, **only if that file does not exist yet** (an existing file is left untouched).

The migration is not run: the audit log is off by default. To turn it on, run `php artisan migrate`,
define the `viewLarsadminAudit` gate and set `LARSADMIN_AUDIT=true` (see
[Audit log](17-audit-log.md#quick-start)).

Notifications and background actions are off as well. To turn them on, create the `notifications` table
(`php artisan make:notifications-table`), run `php artisan migrate`, set `LARSADMIN_NOTIFICATIONS=true`,
run a queue worker and schedule `larsadmin:prune-notifications` (see
[Notifications and background actions](18-notifications.md#quick-start)).

The panel is then available at `/admin` (see the `path` key below).

### AI assistants (optional)

The package ships a guideline, 15 skills and a local MCP server for AI assistants (Claude Code, Cursor and
others). With Laravel Boost 2.x they are installed by `php artisan boost:install`, without it by
`php artisan larsadmin:ai`. The MCP server needs `composer require laravel/mcp --dev`; it runs locally only
and never in production. See [AI assistants](20-ai-assistants.md).

## Updating the package

```bash
composer update maxcxam/larsadmin
php artisan larsadmin:publish
```

`larsadmin:publish` publishes the config (an existing file is overwritten only with `--force`) and always
overwrites the assets. The assets are more than `app.js` and `app.css`: since 1.5 `app.js` is only a small
entry module, while the panel code, Vue and Inertia live in `chunks/` (`shared-*`, `vue-*`, `inertia-*`, plus
the bell and the realtime client, which load on demand), and the modules for extensions in `runtime/`. Publish
the whole directory, not single files.

Security fixes ship as patch versions of the latest 1.x release, so keep the package up to date.
Report vulnerabilities privately, not in public issues: see [SECURITY.md](../../../SECURITY.md).

## Publish tags

| Tag | Destination |
| --- | --- |
| `larsadmin-config` | `config/larsadmin.php` |
| `larsadmin-assets` (also `laravel-assets`) | `public/vendor/larsadmin` |
| `larsadmin-lang` | `lang/vendor/larsadmin` |
| `larsadmin-provider` | `app/Providers/LarsadminServiceProvider.php` |
| `larsadmin-migrations` | `database/migrations` (the audit log and background action runs tables) |

```bash
php artisan vendor:publish --tag=larsadmin-lang
```

`larsadmin:install` does not publish the `larsadmin-lang` tag. Publish it when you want to change the
translations (see [Localization](08-i18n.md)).

## Artisan commands

| Command | Purpose |
| --- | --- |
| `larsadmin:install` | first-time installation |
| `larsadmin:publish [--force]` | update the config and assets |
| `larsadmin:entity {name} [--model=] [--force]` | create an Entity in `app/Larsadmin` |
| `larsadmin:action {name} [--destructive] [--force]` | create an Action in `app/Larsadmin/Actions` |
| `larsadmin:filter {name} [--force]` | create a Filter in `app/Larsadmin/Filters` |
| `larsadmin:dashboard {name} [--force]` | create a Dashboard in `app/Larsadmin/Dashboards` ([Dashboards](14-dashboards.md)) |
| `larsadmin:value {name} [--force]` | create a Value metric in `app/Larsadmin/Metrics` ([Metrics](13-metrics.md)) |
| `larsadmin:trend {name} [--force]` | create a Trend metric in `app/Larsadmin/Metrics` |
| `larsadmin:partition {name} [--force]` | create a Partition metric in `app/Larsadmin/Metrics` |
| `larsadmin:progress {name} [--force]` | create a Progress metric in `app/Larsadmin/Metrics` |
| `larsadmin:extension {name} [--path=packages] [--field=] [--card=] [--force]` | create an extension package in `PATH/VENDOR/PACKAGE` ([Extensions](19-extensions.md#quick-start)) |
| `larsadmin:field {name} [--extension=] [--force]` | create a custom field: a PHP class and Vue form and display components |
| `larsadmin:card {name} [--extension=] [--data] [--force]` | create a custom card (`Card`, or with `--data` a `DataCard`) and its Vue component |
| `larsadmin:tool {name} [--extension=] [--force]` | create a sidebar page (`Tool`) and its Vue component |
| `larsadmin:ai [--skills] [--mcp] [--claude-md] [--path=.claude/skills] [--force]` | install the skills, the MCP server entry in `.mcp.json` and the guideline in `CLAUDE.md` without Laravel Boost ([AI assistants](20-ai-assistants.md#installing-without-boost-larsadminai)) |
| `larsadmin:mcp` | start the MCP server for AI assistants (stdio; needs `laravel/mcp`; never in production) ([AI assistants](20-ai-assistants.md#the-mcp-server)) |
| `larsadmin:prune-audit [--days=]` | delete audit log entries older than `audit.retention_days` (90 days) ([Audit log](17-audit-log.md#pruning)) |
| `larsadmin:prune-notifications [--days=]` | delete panel notifications, background action runs and their files older than `notifications.retention_days` (30 days) ([Notifications](18-notifications.md#pruning)) |

## Configuration (`config/larsadmin.php`)

| Key | Environment variable | Default | Description |
| --- | --- | --- | --- |
| `name` | `LARSADMIN_NAME` | `APP_NAME` + ` Admin` (without `APP_NAME`: `Laravel Admin`) | Name in the header and the browser tab title |
| `domain` | `LARSADMIN_DOMAIN` | `null` | Domain the panel is served on |
| `path` | `LARSADMIN_PATH` | `admin` | URI prefix |
| `guard` | `LARSADMIN_GUARD` | `null` (the default guard) | Authentication guard of the panel |
| `locale` | `LARSADMIN_LOCALE` | `uk` | Panel language; `null` keeps the application locale |
| `locales` | | `['uk' => 'Українська', 'en' => 'English']` | Languages the user can switch between (`code => label`) |
| `timezone` | `LARSADMIN_TIMEZONE` | `null` (`app.timezone`) | Time zone that metric periods are calculated in ([Metrics](13-metrics.md#timezone)) |
| `metrics.cache_store` | | `null` (the default store) | Cache store for metrics that use `cacheFor()` |
| `metrics.throttle` | | `120` | Requests per minute to the metric endpoints, per user (guests: per IP), limiter `larsadmin-metrics`; `null` disables it |
| `middleware` | | `['web', HandleInertiaRequests::class]` | Middleware for all panel routes, including the login page |
| `auth_middleware` | | `[Authenticate::class, Authorize::class]` | Middleware for routes behind the login screen |
| `allow_without_policy` | | `true` | Models without a policy: `true` allows, `false` denies |
| `allow_local` | `LARSADMIN_ALLOW_LOCAL` | `true` | With `APP_ENV=local`, let users into the panel without the `viewLarsadmin` gate |
| `actions.max_all` | | `1000` | Maximum number of records for an action run on "all matching" records; more fails validation with 422 |
| `actions.queued_max_all`, `actions.queued_max_active` | | `10000`, `3` | Background action limits: records for "all matching" and active runs per user |
| `actions.stale_after`, `actions.queued_stale_after` | | `3600`, `86400` | Seconds after which a run without a heartbeat (without `$timeout`, plus 60 s) or a queued run counts as stale ([Notifications](18-notifications.md#stale-runs)) |
| `actions.queue`, `actions.queue_connection` | `LARSADMIN_QUEUE`, `LARSADMIN_QUEUE_CONNECTION` | `null` | Default queue and queue connection for background actions |
| `audit.enabled` | `LARSADMIN_AUDIT` | `false` | Turns the audit log on ([Audit log](17-audit-log.md#configuration)) |
| `audit.connection` | `LARSADMIN_AUDIT_CONNECTION` | `null` | Database connection of the log |
| `audit.*` | | `retention_days` 90, `max_value_length` 1000, `per_page` 25, `throttle` 60 | Table, storing the IP and user agent, pruning; see [Audit log](17-audit-log.md#configuration) |
| `notifications.enabled` | `LARSADMIN_NOTIFICATIONS` | `false` | Turns on the bell and background actions ([Notifications](18-notifications.md#configuration)) |
| `notifications.realtime` | `LARSADMIN_REALTIME` | `auto` | Realtime through Reverb/Pusher: `auto`, `true`, `false` |
| `notifications.channel_prefix` | `LARSADMIN_CHANNEL_PREFIX` | `larsa_` | Prefix of the panel's broadcast channels |
| `notifications.*` | | `poll_interval` 60, `per_page` 15, `retention_days` 30, `throttle` 120 | Polling, bell page size, pruning; see [Notifications](18-notifications.md#configuration) |
| `extensions.source_maps` | `LARSADMIN_EXTENSION_SOURCE_MAPS` | `false` | Serve the extensions' source maps (`*.map`); debugging only ([Extensions](19-extensions.md#http-endpoints)) |
| `extensions.max_age` | | `31536000` | Browser cache lifetime of versioned extension files, in seconds |
| `mcp.enabled` | `LARSADMIN_MCP` | `null` | The MCP server: `null` only with `APP_ENV=local`, `true` also in other non-production environments, `false` off; it never runs in production ([AI assistants](20-ai-assistants.md#when-the-server-runs)) |

> **Warning.** When `allow_local` is `true` and `APP_ENV=local`, **every authenticated user** gets
> into the panel. Do not run shared or public servers with `APP_ENV=local`, or set
> `LARSADMIN_ALLOW_LOCAL=false`.

Example production `.env`:

```dotenv
LARSADMIN_NAME="Моя адмінка"
LARSADMIN_PATH=backoffice
LARSADMIN_ALLOW_LOCAL=false
```

## Login

The panel has its own login page (`/admin/login`) with rate limiting: 5 attempts per minute
per email + IP pair and 30 per minute per IP.

## Content Security Policy

If the application sets a CSP, turn on nonces with the standard `Vite::useCspNonce()` in a middleware that
runs before panel pages render: the theme inline script, the extensions' import map, the `app.js` tag and the
`modulepreload` tags get a `nonce` attribute. Without a nonce a strict CSP blocks the import map and extensions do not load (the panel
works). Example: [Extensions: CSP and nonces](19-extensions.md#csp-and-nonces).

## Route cache

If your application uses `php artisan route:cache`, rebuild the cache after changing `path` or `domain`.
The package does not register its routes while the route cache is active.

Next: [Entities](02-entities.md).

## Developing the package

In the root of the package repository:

| Command | Purpose |
| --- | --- |
| `composer test` | Pest + Testbench |
| `composer format` | Pint |
| `npm test` | frontend tests (vitest) |
| `npm run i18n` | generates `resources/js/i18n/uk.json` from `lang/uk/ui.php` |
| `npm run build` | builds `dist/` |

`resources/js/i18n/uk.json` must be **committed**: run `npm run i18n` after changing `lang/uk/ui.php`.
`dist/` is committed too. CI checks that `dist/` is up to date
(`git diff --exit-code dist` after the build).

`tests/Ai` checks the skills (`resources/boost/skills`): every guide page, `larsadmin:*` command, config key,
`LARSADMIN_*` variable and public class must be mentioned in a skill, and everything the skills mention must
exist. So **new or changed public API updates its skill in the same PR**. Every guide page must exist both in
`docs/guide/` and in `docs/guide/en/`.
