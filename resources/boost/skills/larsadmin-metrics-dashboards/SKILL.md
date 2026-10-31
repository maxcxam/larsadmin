---
name: larsadmin-metrics-dashboards
description: "Larsadmin metrics and dashboards: Value, Trend, Partition and Progress metrics, their results, ranges, caching and timezones, entity sources and scoping, cards above entity lists, Dashboard classes, the Main dashboard and the metric and dashboard generators. Use when adding a KPI, chart, card or dashboard to the admin panel of a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin metrics and dashboards

## When to use

- Add a number (Value), line chart (Trend), donut (Partition) or goal bar (Progress).
- Add a dashboard page, or cards above an Entity list.
- Cache a metric, set the panel timezone, or fix a metric that shows rows a user must not see.

Custom Vue cards (`Card`, `DataCard`) belong to skill `larsadmin-extending`.

## Quick start

```bash
php artisan larsadmin:value NewPosts                 # App\Larsadmin\Metrics\NewPosts
php artisan larsadmin:trend PostsPerDay
php artisan larsadmin:partition PostsByStatus
php artisan larsadmin:progress MonthlyPublishingGoal
php artisan larsadmin:dashboard Content              # App\Larsadmin\Dashboards\Content
```

`--force` overwrites; `Reports/Revenue` gives a sub-namespace. MCP: `generate` with `kind` `value`,
`trend`, `partition`, `progress` or `dashboard` (plan first, then `write: true`).

The metric stubs count `App\Models\User` with a `// SECURITY: unscoped` comment. Replace that source
with your Entity class before shipping:

```php
namespace App\Larsadmin\Metrics;

use App\Larsadmin\Post;
use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\ValueResult;
use Maxcxam\Larsadmin\Metrics\Value;

class NewPosts extends Value
{
    public function ranges(): array
    {
        return $this->labelled([7, 30, 90, 'MTD']);
    }

    public function calculate(MetricRequest $request): ValueResult
    {
        // An Entity class: only rows of Post::indexQuery(), and viewAny is required.
        return $this->count($request, Post::class)->allowZeroResult();
    }
}
```

Show it: return `NewPosts::make()->help('Posts created in the period.')` from `cards()` of a dashboard
or an Entity.

## Recipes

### Dashboard and registration

```php
namespace App\Larsadmin\Dashboards;

use App\Larsadmin\Metrics\NewPosts;
use App\Larsadmin\Metrics\PostsPerDay;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Dashboard;

class Content extends Dashboard
{
    public function cards(Request $request): array
    {
        return [
            NewPosts::make(),
            PostsPerDay::make()->width('full'),
        ];
    }
}
```

Dashboards are not auto-registered. Add them to `dashboards()` in
`App\Providers\LarsadminServiceProvider` (order = menu order):

```php
namespace App\Providers;

use App\Larsadmin\Dashboards\Content;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Main;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;

class LarsadminServiceProvider extends LarsadminApplicationServiceProvider
{
    protected function dashboards(): array
    {
        return [
            new Main,
            Content::make()->canSee(fn (Request $request) => $request->user()->can('viewAnalytics')),
        ];
    }
}
```

`Maxcxam\Larsadmin\Dashboards\Main` is the built-in empty home dashboard (`uriKey` `main`). The first
dashboard the user can see opens at `/admin`; one without visible cards falls back to the Entity list.
`$displayInNavigation = false` keeps a dashboard reachable by URL only. Check what is registered with
the MCP tool `list-dashboards`.

### Cards above an Entity list

Return metrics from `Entity::cards(Request $request)`. They show on the list page only, ignore the
list's filters and search, and need `viewAny` on the Entity.

### Narrow an Entity source

```php
use App\Larsadmin\Post;
use Illuminate\Database\Eloquent\Builder;
use Maxcxam\Larsadmin\Metrics\EntitySource;

// Inside calculate(): both are equivalent and stay inside Post::indexQuery().
$live = $this->scoped(Post::class, fn (Builder $query) => $query->where('status', 'live'));
$live = EntitySource::make(Post::class, fn (Builder $query) => $query->where('status', 'live'));

return $this->sum($request, $live, 'views', 'published_at');
```

### Trend, Partition, Progress

```php
namespace App\Larsadmin\Metrics;

use App\Larsadmin\Post;
use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\TrendResult;
use Maxcxam\Larsadmin\Metrics\Trend;

class PostsPerWeek extends Trend
{
    protected string $unit = 'week'; // keep in line with the helper below

    public function calculate(MetricRequest $request): TrendResult
    {
        return $this->countByWeeks($request, Post::class)->showSumValue();
    }
}
```

- Trend helpers: `countBy|sumBy|averageBy|minBy|maxBy` + `Days|Weeks|Months`, or
  `aggregate($request, $model, $unit, $function, ...)`. Ranges are integer point counts
  (`$this->bucketRanges([4, 8, 13])`); a string range key throws.
- Partition: `count($request, Post::class, 'status')` returns a `PartitionResult`; style it with
  `label()`, `labelsFrom(Category::class, 'name')`, `booleanLabels()`, `colors()`, `limit()`.
- Progress: `count($request, $source, 80, 'published_at')` returns a `ProgressResult`; `avoid()` for
  "lower is better".
- Formatting on any result: `currency('UAH')`, `format([...])`; `prefix()` / `suffix()` except Partition.

### Caching and timezone

```php
namespace App\Larsadmin\Metrics;

use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Value;

abstract class TeamValue extends Value
{
    public function cacheFor(): int
    {
        return 300; // seconds; null or 0 disables
    }

    public function cacheKeyParts(MetricRequest $request): array
    {
        return [$request->request->user()->current_team_id];
    }
}
```

App-wide, in `boot()` of the application service provider:

```php
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;

Larsadmin::timezoneUsing(fn (Request $request) => $request->user()?->timezone);
Larsadmin::metricCacheScopeUsing(fn (Request $request, mixed $card) => tenant()?->getKey());
```

The timezone falls back to `config('larsadmin.timezone')` (`LARSADMIN_TIMEZONE`), then `app.timezone`.
Type the scope callback's second parameter as `mixed` or `Card`, so data cards are cached too.

## API cheat sheet

| API | Notes |
| --- | --- |
| `Maxcxam\Larsadmin\Metrics\Card` | base of every card: `make()`, `name()`, `uriKey()`, `width('1/3'\|'1/2'\|'2/3'\|'full')`, `help()`, `canSee()`, `withMeta()` |
| `Maxcxam\Larsadmin\Metrics\Metric` | base of metrics: `ranges()`, `defaultRange()`, `cacheFor()`, `cacheKeyParts()`, `labelled()`, `scoped()` |
| `Maxcxam\Larsadmin\Metrics\Value` | `count`, `sum`, `average`, `min`, `max`, `result($value)` |
| `Maxcxam\Larsadmin\Metrics\Trend` | `$unit` (`day`, `week`, `month`), `*ByDays/Weeks/Months`, `aggregate()` |
| `Maxcxam\Larsadmin\Metrics\Partition` | `count`, `sum`, `average`, `min`, `max` with `$groupBy` |
| `Maxcxam\Larsadmin\Metrics\Progress` | `count`, `sum`, `result($value, $target)` |
| `Maxcxam\Larsadmin\Metrics\Results\ValueResult` | `previous()`, `allowZeroResult()` |
| `Maxcxam\Larsadmin\Metrics\Results\TrendResult` | `showSumValue()` (count/sum only), `showLatestValue()` |
| `Maxcxam\Larsadmin\Metrics\Results\PartitionResult` | `label()`, `labelsFrom()`, `booleanLabels()`, `colors()`, `limit()` |
| `Maxcxam\Larsadmin\Metrics\Results\ProgressResult` | `avoid()` |
| `Maxcxam\Larsadmin\Metrics\MetricRequest` | `->request`, `->range`, `->timezone`, `->now` (`CarbonImmutable`) |
| `Maxcxam\Larsadmin\Metrics\EntitySource` | `EntitySource::make(EntityClass, fn (Builder $q) => ...)` |
| `Maxcxam\Larsadmin\Dashboards\Dashboard` | `cards()` required; `name()`, `uriKey()`, `$displayInNavigation`, `canSee()` |
| `Maxcxam\Larsadmin\Dashboards\Main` | built-in home dashboard |
| `Larsadmin::timezoneUsing()`, `Larsadmin::metricCacheScopeUsing()` | panel timezone, extra cache-key scope |

Range keys: integer days, `'TODAY'`, `'MTD'`, `'QTD'`, `'YTD'`, `'ALL'`; periods are half-open.
Config: `larsadmin.metrics.cache_store`, `larsadmin.metrics.throttle` (120 requests per minute).

## Security rules

- MUST use an Entity class (`Post::class` from `App\Larsadmin`), `scoped()` or `EntitySource` as the
  source for anything per-user. A model class, an Eloquent builder or `DB::table()` counts every row,
  ignoring `indexQuery()` and policies.
- NEVER ship a generated metric with the `// SECURITY: unscoped` line still in place.
- MUST put access checks in the card's `canSee()`, not in `calculate()`: `calculate()` does not run on
  a cache hit.
- MUST add tenant, team or role to `cacheKeyParts()` (or `Larsadmin::metricCacheScopeUsing()`) when the
  result depends on more than the user and the range.
- NEVER treat a dashboard's `canSee()` as protecting its cards' data; protect each sensitive card.
- `labelsFrom()` reads the related model without policies (only for keys already in the result); do not
  point it at a model whose names are secret.

## Pitfalls

- Two cards with the same `uriKey()` in one dashboard or Entity throw `InvalidArgumentException`.
- A `0` Value shows "No data" unless `allowZeroResult()` is called.
- `showSumValue()` on an average/min/max trend throws `LogicException`.
- `DB::table()` sources need an explicit date column.
- Trend `$unit` only sets the default ranges; the helper decides the point unit.
- On SQLite, `DATE` columns in trends land in the previous day's point; use `DATETIME`.
- Every card is a separate request; large dashboards may hit `larsadmin.metrics.throttle`.

## Reference

- Metrics: `vendor/maxcxam/larsadmin/docs/guide/en/13-metrics.md` (MCP: `read-docs` with
  `page: "13-metrics"`).
- Dashboards: `vendor/maxcxam/larsadmin/docs/guide/en/14-dashboards.md` (MCP: `read-docs` with
  `page: "14-dashboards"`).
- Custom cards and data cards: skill `larsadmin-extending`. Testing metrics: skill `larsadmin-testing`.
