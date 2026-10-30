# 01. Installation
🇺🇦 [Українська](../01-installation.md)

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
4. copies `lang/uk/validation.php`, **only if that file does not exist yet** (an existing file is left untouched).

The panel is then available at `/admin` (see the `path` key below).

## Updating the package

```bash
composer update maxcxam/larsadmin
php artisan larsadmin:publish
```

`larsadmin:publish` publishes the config (an existing file is overwritten only with `--force`) and always
overwrites the assets.

## Publish tags

| Tag | Destination |
| --- | --- |
| `larsadmin-config` | `config/larsadmin.php` |
| `larsadmin-assets` (also `laravel-assets`) | `public/vendor/larsadmin` |
| `larsadmin-lang` | `lang/vendor/larsadmin` |
| `larsadmin-provider` | `app/Providers/LarsadminServiceProvider.php` |

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
