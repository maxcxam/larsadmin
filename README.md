# Larsadmin

An admin panel for Laravel, built with Inertia and Vue 3: describe each Eloquent model once as
an **Entity** and get lists, forms, filters, actions, metrics and dashboards.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maxcxam/larsadmin.svg)](https://packagist.org/packages/maxcxam/larsadmin)
[![Total Downloads](https://img.shields.io/packagist/dt/maxcxam/larsadmin.svg)](https://packagist.org/packages/maxcxam/larsadmin)
[![Tests](https://github.com/maxcxam/larsadmin/actions/workflows/tests.yml/badge.svg)](https://github.com/maxcxam/larsadmin/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/maxcxam/larsadmin.svg)](LICENSE)

## Features

- **Entities**: one class per model with fields, search (including `relation.column`), sorting,
  pagination and row scoping (`indexQuery`, `detailQuery`, `relatableQuery`).
- **Fields**: `ID`, `Text`, `Textarea`, `Number`, `Boolean`, `Date`, `DateTime`, `Password`, `Select`
  (enums too), with per-context and per-record visibility, read-only rules and validation.
- **Files and images**: `File` and `Image` fields, one file or several (`multiple()`), public and
  private disks including S3, signed re-authorized links for private files, deletion of replaced files.
- **Global search**: a Ctrl/⌘+K command palette across the Entities that opt in, over their `$search`
  columns or through Laravel Scout, limited to what policies and `indexQuery()` let the user see.
- **Relations**: `BelongsTo` (searchable), `HasMany` and `BelongsToMany` panels with attach/detach.
- **Field blocks**: group form and detail fields into panels (one or two columns, collapsible) and tabs.
- **Filters**: select, boolean and date range filters, plus your own.
- **Actions**: bulk, standalone and per-row ("⋯" menu) actions with modal fields, confirmation, batching
  and per-record availability.
- **Metrics and dashboards**: Value, Trend, Partition and Progress cards, scoped to what the user may
  see, on dashboards or above an entity list.
- **Security by default**: panel gate, model policies that fail closed, server-built record URLs.
- **UI**: GitHub-like interface with light, dark and system themes, column picker, responsive layout.
- **i18n**: Ukrainian (default) and English, with a language switcher.
- **Generators** for entities, actions, filters, metrics and dashboards.

The frontend ships prebuilt: your application does not need Node.js.

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
`app/Providers/LarsadminServiceProvider.php` (registered in `bootstrap/providers.php`), and copies
`lang/uk/validation.php` if your app does not have one yet.

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
| `search.*` | | `limit` 5, `min_length` 2, `throttle` 60 | Global search; see the [search guide](docs/guide/en/16-search.md#configuration) |

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

## Localization

The panel speaks Ukrainian (default) and English. Users switch the language in the header; you can
also resolve it yourself with `Larsadmin::resolveLocaleUsing()`. Publish the translations to change
them:

```bash
php artisan vendor:publish --tag=larsadmin-lang
```

## Theming

Light, dark and system themes, switchable in the header and on the login page. The choice is kept in
the browser. There is no public theming API yet; see the [theming guide](docs/guide/en/09-theming.md) for the design tokens.

## Documentation

Full guides, in English. The Ukrainian originals are in [`docs/guide/`](docs/guide/); every page links to
its translation.

| Guide | Topics |
| --- | --- |
| [01. Installation](docs/guide/en/01-installation.md) | requirements, publish tags, config, commands |
| [02. Entities](docs/guide/en/02-entities.md) | Entity API, queries, generator |
| [03. Fields](docs/guide/en/03-fields.md) | field types, visibility, validation, list columns |
| [04. Relations](docs/guide/en/04-relations.md) | BelongsTo, HasMany, BelongsToMany |
| [05. Authorization](docs/guide/en/05-authorization.md) | panel gate, policies, row scoping |
| [06. Filters](docs/guide/en/06-filters.md) | built-in and custom filters |
| [07. Actions](docs/guide/en/07-actions.md) | bulk and row actions, responses, generator |
| [08. Localization](docs/guide/en/08-i18n.md) | languages, translations, switcher |
| [09. Theming](docs/guide/en/09-theming.md) | tokens, light/dark/system |
| [10. Extending](docs/guide/en/10-extending.md) | what is public and what is planned |
| [11. List UI](docs/guide/en/11-ui.md) | column picker, responsive layout |
| [12. Field blocks](docs/guide/en/12-layout.md) | panels, tabs, two columns |
| [13. Metrics](docs/guide/en/13-metrics.md) | Value, Trend, Partition, Progress, scoping, caching |
| [14. Dashboards](docs/guide/en/14-dashboards.md) | dashboards, home page, entity cards |
| [15. Files and images](docs/guide/en/15-files.md) | File and Image fields, disks, private files, pruning |
| [16. Global search](docs/guide/en/16-search.md) | Ctrl/⌘+K palette, opt-in, subtitles, Laravel Scout, authorization |

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
