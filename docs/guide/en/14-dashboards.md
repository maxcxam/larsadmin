# 14. Dashboards
🇺🇦 [Українська](../14-dashboards.md)

A dashboard is a page with [metric](13-metrics.md) cards. The first dashboard the user can see opens on
the panel home page (`/admin`). The others have their own addresses, `/admin/dashboards/{uriKey}`,
and items in the side menu.

## Generator

```bash
php artisan larsadmin:dashboard Content
```

The class is created as `App\Larsadmin\Dashboards\Content`. `--force` overwrites an existing class.
Dashboard classes in `app/Larsadmin` are not registered as Entities, and they are not added
automatically either: you have to add the dashboard to `dashboards()` (see below).

## The dashboard class

```php
namespace App\Larsadmin\Dashboards;

use App\Larsadmin\Metrics\NewPosts;
use App\Larsadmin\Metrics\PostsByStatus;
use App\Larsadmin\Metrics\PostsPerDay;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Dashboard;

class Content extends Dashboard
{
    public function name(): string
    {
        return 'Аналітика контенту';
    }

    public function cards(Request $request): array
    {
        return [
            NewPosts::make()->help('Дописи, створені за період.'),
            PostsByStatus::make(),
            PostsPerDay::make()->width('full'),
        ];
    }
}
```

| Class member | Default | Description |
| --- | --- | --- |
| `cards(Request): array` | required | the cards, in display order |
| `name()` | the class name in Title Case, through `__()` | the menu label and the page title |
| `uriKey()` | the class name in kebab-case | the URL segment: `/admin/dashboards/content` |
| `$displayInNavigation` | `true` | show in the side menu. `false` keeps the dashboard reachable by its address |
| `canSee(Closure)` | | `fn (Request $request): bool`, who can see the dashboard |
| `make(...$args)` | | creates an instance |

Card sizes and hints are set on the cards themselves: `width()`, `help()`, `canSee()`
([Metrics](13-metrics.md#card)).

## Registration

Dashboards are returned by the `dashboards()` method of the application provider
`App\Providers\LarsadminServiceProvider` (created by `larsadmin:install`). The order in the array is
the order in the menu.

```php
use App\Larsadmin\Dashboards\Content;
use App\Larsadmin\Dashboards\Overview;

protected function dashboards(): array
{
    return [
        Overview::class,                // class or instance
        Content::make()->canSee(fn (Request $request) => $request->user()->can('viewAnalytics')),
    ];
}
```

By default it contains `new Main`: the built-in `Maxcxam\Larsadmin\Dashboards\Main` dashboard, named
"Dashboard" (the `larsadmin::ui.dashboard` key; "Головна" in Ukrainian), with the `uriKey` `main` and
no cards. You can also call `Larsadmin::dashboards([...])` directly: the call replaces the dashboards
registered earlier. An empty list means a single `Main`.

Array elements: a `Dashboard` instance or its class name (instantiated with `make()`). Anything else,
as well as two dashboards with the same `uriKey()`, throws `InvalidArgumentException`. Likewise, two
cards with the same `uriKey()` in one dashboard or Entity throw `InvalidArgumentException`.

## Home page

- `/admin` shows **the first registered dashboard the user can see** (`canSee`).
- If the dashboard has no visible cards (like `Main`), the page shows the Entity list, as before
  dashboards existed.
- If no dashboard is visible, `/admin` also shows the Entity list.

## Side menu

- **One** visible dashboard: the menu has a single item with its name, linking to `/admin`.
- **Several** visible: the menu shows a "Dashboards" group with all visible dashboards that have
  `$displayInNavigation = true`. The home dashboard's item links to `/admin`, the others to
  `/admin/dashboards/{uriKey}`.

## Access

| Check | Effect |
| --- | --- |
| panel access (the `viewLarsadmin` gate, [Authorization](05-authorization.md)) | without it, all dashboard pages and endpoints are unavailable |
| the dashboard's `canSee()` | an invisible dashboard responds with 404, both on its page and on its metric endpoints |
| the card's `canSee()` | an invisible card does not appear on the page; its endpoint responds with 404 |
| the metric source | an Entity source requires `viewAny` and is limited by `indexQuery()`; a model class or builder is **not restricted** ([Metrics](13-metrics.md#data-source-and-security)) |

The dashboard's `canSee()` does not check access to the cards' data. Protect sensitive numbers at the
metric level: with an Entity source or the card's `canSee()`.

## Cards above an Entity list

`Entity::cards()` shows metrics above the list table of that Entity:

```php
// app/Larsadmin/Post.php
public function cards(Request $request): array
{
    return [
        NewPosts::make(),
        PostsByStatus::make(),
        MonthlyPublishingGoal::make(),
    ];
}
```

- The cards are shown only on the list page, not on the record detail page.
- The data comes from `GET {path}/api/entities/{entity}/metrics/{metric}`. Without `viewAny` for the
  Entity the endpoint responds with 403, an invisible card with 404.
- The cards are not limited by the list's current filters and search: the metric counts its own source.
- The same metric classes can be used on dashboards too. The cache (`cacheFor()`) is kept separately
  for each card owner.

## Custom cards

Besides metrics, both `Dashboard::cards()` and `Entity::cards()` accept extension cards (since 1.5): static
`Card` subclasses and `DataCard`s. A data card goes through the same checks as a metric (dashboard
visibility or the entity's `viewAny`, `canSee()`), and its parameters are validated by `rules()`.
`withMeta()` gives any card extra props:

```php
public function cards(Request $request): array
{
    return [
        NewPosts::make(),
        TopRated::make()->width('1/2'),                       // an extension DataCard
        Welcome::make()->withMeta(['links' => $this->links()]), // a static card
    ];
}
```

See [Extensions: cards](19-extensions.md#cards).

## Timezone

The ranges of all metrics are computed in the panel timezone: `Larsadmin::timezoneUsing()`, then
`larsadmin.timezone`, then `app.timezone`. See [Metrics](13-metrics.md#timezone).

Next: [Files and images](15-files.md).
