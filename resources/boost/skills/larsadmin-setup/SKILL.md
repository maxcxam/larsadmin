---
name: larsadmin-setup
description: "Larsadmin installation and configuration: the install and publish commands, the application service provider, registering entities, dashboards and tools, the panel name, path, domain, guard and login, every config/larsadmin.php key and LARSADMIN_* environment variable, CSP nonce, route caching, package upgrades and the AI assistant tooling. Use when installing or upgrading maxcxam/larsadmin, changing the panel configuration or environment variables, or wiring the panel into a Laravel app."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin setup

## When to use

- Installing `maxcxam/larsadmin` into a Laravel 12/13 app (PHP 8.3+), or upgrading it.
- Changing the panel URL, domain, name, guard, locale or timezone, or any other `config/larsadmin.php` key.
- Registering entities, dashboards or tools in the application provider.
- Setting up the AI tooling: the skills, the MCP server and the guidelines import.

For who may open the panel (the `viewLarsadmin` gate, policies) use `larsadmin-authorization-security`.
For the first Entity use `larsadmin-entities`.

## Quick start

```bash
composer require maxcxam/larsadmin
php artisan larsadmin:install
```

`larsadmin:install` publishes, in order: the config (tag `larsadmin-config`) to `config/larsadmin.php`, the
assets (`larsadmin-assets`) to `public/vendor/larsadmin`, the provider (`larsadmin-provider`) to
`app/Providers/LarsadminServiceProvider.php` (and adds it to `bootstrap/providers.php`), the migrations
(`larsadmin-migrations`), and `lang/uk/validation.php` only when that file is missing. It does not run
`migrate`. The panel is then served at `/admin`.

The published provider extends `Maxcxam\Larsadmin\LarsadminApplicationServiceProvider`. Its `gate()`
closes the panel to everyone outside `APP_ENV=local`; open it before you deploy:

```php
namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;

class LarsadminServiceProvider extends LarsadminApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewLarsadmin', fn ($user) => $user->is_admin === true);
    }
}
```

Then generate the first Entity: `php artisan larsadmin:entity Post --model=Post` (see `larsadmin-entities`).

## Recipes

### Register entities, dashboards and tools

The base provider's `boot()` calls `gate()`, `authorization()`, `entities()` and
`Larsadmin::dashboards($this->dashboards())`. Every class in `app/Larsadmin` (subdirectories included)
is registered by `entities()` through `Larsadmin::entitiesIn(app_path('Larsadmin'))`. Override the hooks
for anything else:

```php
namespace App\Providers;

use App\Larsadmin\Dashboards\Sales;
use App\Larsadmin\Tools\Backups;
use Maxcxam\Larsadmin\Dashboards\Main;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;

class LarsadminServiceProvider extends LarsadminApplicationServiceProvider
{
    protected function entities(): void
    {
        parent::entities();                                  // app/Larsadmin
        Larsadmin::entitiesIn(app_path('Domain/Admin'));     // a directory inside app/
        Larsadmin::entities([\App\Admin\LegacyUser::class]); // explicit classes
    }

    protected function dashboards(): array
    {
        return [new Main, new Sales]; // menu order; the first visible one is the home page
    }

    public function boot(): void
    {
        parent::boot();
        Larsadmin::tools([Backups::class]);
    }
}
```

Dashboards are covered by `larsadmin-metrics-dashboards`, tools by `larsadmin-extending`.

### Change the URL, name, guard and language

Prefer environment variables over editing the published config:

```dotenv
LARSADMIN_NAME="Back office"
LARSADMIN_PATH=backoffice
LARSADMIN_GUARD=admin
LARSADMIN_LOCALE=en
LARSADMIN_ALLOW_LOCAL=false
```

The default panel locale is `uk`; set `LARSADMIN_LOCALE=en` (or `null` in the config to follow the app
locale) for an English panel. After changing `path` or `domain` with a route cache, run
`php artisan route:cache` again: the package does not register its routes while the cache is active.

### Upgrade the package

```bash
composer update maxcxam/larsadmin
php artisan larsadmin:publish          # assets always; config only with --force
```

Publish the whole `public/vendor/larsadmin` directory (it holds `chunks/` and `runtime/`, not only
`app.js`). Publish the translations with `php artisan vendor:publish --tag=larsadmin-lang`.

### Turn on optional subsystems

The audit log, the notification bell and background actions are off by default. Each needs a migration and
an environment flag (`LARSADMIN_AUDIT`, `LARSADMIN_NOTIFICATIONS`). Follow `larsadmin-audit-log` and
`larsadmin-notifications-realtime`.

### Content Security Policy

With a strict CSP call `Vite::useCspNonce()` in a middleware that runs before panel pages render. The theme
script, the extensions' import map, `app.js` and the `modulepreload` tags then carry the nonce. Without it
extensions do not load (the panel itself works).

### AI assistant tooling

- With Laravel Boost 2.x: `php artisan boost:install` picks up the package guidelines and skills.
- Without Boost: `php artisan larsadmin:ai` (interactive), or
  `php artisan larsadmin:ai --skills --mcp --claude-md`. `--skills` copies the skills into `--path`
  (default `.claude/skills`) and skips folders changed by hand unless `--force`; `--mcp` merges a
  `larsadmin` entry (`php artisan larsadmin:mcp`) into `.mcp.json`; `--claude-md` appends
  `@vendor/maxcxam/larsadmin/resources/boost/guidelines/core.md` to `CLAUDE.md` once.
  `--no-interaction` means `--skills --mcp`.
- `php artisan larsadmin:mcp` starts the stdio MCP server. It needs `composer require laravel/mcp --dev`
  and runs only where `larsadmin.mcp.enabled` allows (see Security rules).
- MCP tools: `search-docs`, `read-docs`, `application-info`, `list-entities`, `describe-entity`,
  `list-dashboards`, `list-extensions`, `generate`. Call `application-info` first to learn the panel path,
  guard, enabled features and where the provider lives.

## API cheat sheet

| Item | Purpose |
| --- | --- |
| `larsadmin:install` | first install (config, assets, provider, migrations) |
| `larsadmin:publish [--force]` | after an upgrade: assets, config with `--force` |
| `larsadmin:ai [--skills] [--mcp] [--claude-md] [--path=] [--force]` | AI tooling without Boost |
| `larsadmin:mcp` | start the local MCP server (stdio) |
| Publish tags | `larsadmin-config`, `larsadmin-assets` (also `laravel-assets`), `larsadmin-lang`, `larsadmin-provider`, `larsadmin-migrations` |
| `Maxcxam\Larsadmin\LarsadminServiceProvider` | the package provider (auto-discovered); do not extend it |
| `Maxcxam\Larsadmin\LarsadminApplicationServiceProvider` | base of the app provider: `gate()`, `authorization()`, `entities()`, `dashboards()` |

`Maxcxam\Larsadmin\Larsadmin` facade (static methods):

| Method | Purpose |
| --- | --- |
| `Larsadmin::auth(?Closure)` | replace the panel access check (see `larsadmin-authorization-security`) |
| `Larsadmin::check($request)` | run the access check for a request |
| `Larsadmin::entities([...])`, `Larsadmin::entitiesIn($dir)` | register Entity classes |
| `Larsadmin::registeredEntities()` | the registered Entity classes |
| `Larsadmin::entityForKey('posts')`, `Larsadmin::entityForModel(Post::class)` | find an Entity class, or `null` |
| `Larsadmin::dashboards([...])`, `Larsadmin::registeredDashboards()` | register / read dashboards (replaces earlier ones) |
| `Larsadmin::tools([...])` | add sidebar tools |
| `Larsadmin::name()`, `Larsadmin::path()`, `Larsadmin::guard()` | panel name, path with a leading `/`, guard or `null` |
| `Larsadmin::asset('app.css')` | versioned URL of a published asset |
| `Larsadmin::auditEnabled()`, `Larsadmin::notificationsEnabled()` | whether the audit log / bell are on |

Every config key: [references/config.md](references/config.md). Every environment variable:
[references/env.md](references/env.md).

## Security rules

- MUST define `viewLarsadmin` in `gate()` before any non-local deployment; the base gate denies everyone.
- MUST set `LARSADMIN_ALLOW_LOCAL=false` on any shared or reachable server. With `allow_local` true and
  `APP_ENV=local` every signed-in user enters the panel.
- NEVER run a public server with `APP_ENV=local`.
- MUST set `larsadmin.allow_without_policy` to `false` in production and write a policy per managed model.
- NEVER set `LARSADMIN_MCP=true` on an internet-reachable host. The MCP server never runs in production
  (`APP_ENV` `production`, `prod` or `live`, any case);
  `null` (default) allows only `APP_ENV=local`; `false` turns it off.
- NEVER commit `.env` values or remove `Authenticate`/`Authorize` from `larsadmin.auth_middleware`.

## Pitfalls

- The panel speaks Ukrainian by default (`locale` = `uk`); set `LARSADMIN_LOCALE=en` for English.
- Copying only `app.js`/`app.css` after an upgrade breaks the panel; publish the whole directory.
- A stale route cache keeps the old `path`/`domain`.
- `Larsadmin::dashboards()` replaces; `Larsadmin::entities()` and `Larsadmin::tools()` add.
- `Larsadmin::entitiesIn()` only scans directories inside `app/` (class names come from the app namespace).
- Overriding `authorization()` with `Larsadmin::auth()` drops both `allow_local` and the gate.
- `larsadmin:mcp` exits with an error when `laravel/mcp` is missing or the gate is closed; read stderr.
  After changing `LARSADMIN_MCP` (or any variable) with a cached config, run `php artisan config:clear`.
- `boost:install` only offers packages in the app's own `require`; skills need Boost 2.x.

## Reference

- Installation, commands, publish tags, config: [vendor/maxcxam/larsadmin/docs/guide/en/01-installation.md](vendor/maxcxam/larsadmin/docs/guide/en/01-installation.md)
  (MCP: `read-docs page=01-installation`).
- AI assistants (Boost, `larsadmin:ai`, MCP server and tools): [vendor/maxcxam/larsadmin/docs/guide/en/20-ai-assistants.md](vendor/maxcxam/larsadmin/docs/guide/en/20-ai-assistants.md)
  (MCP: `read-docs page=20-ai-assistants`).
- Authorization: `larsadmin-authorization-security`.
