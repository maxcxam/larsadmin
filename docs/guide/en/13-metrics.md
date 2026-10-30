# 13. Metrics
🇺🇦 [Українська](../13-metrics.md)

A metric is a card with a number or a chart that the panel shows on a [dashboard](14-dashboards.md) or
above an Entity list. Each card loads its data with a separate JSON request, so a slow or broken metric
does not block the page or the other cards.

| Type | Base class | What it shows | `calculate()` result |
| --- | --- | --- | --- |
| Value | `Maxcxam\Larsadmin\Metrics\Value` | one number for a period and the change against the previous one | `ValueResult` |
| Trend | `Maxcxam\Larsadmin\Metrics\Trend` | a line chart by day, week or month | `TrendResult` |
| Partition | `Maxcxam\Larsadmin\Metrics\Partition` | a donut chart by the values of a column | `PartitionResult` |
| Progress | `Maxcxam\Larsadmin\Metrics\Progress` | progress towards a target | `ProgressResult` |

The results live in the `Maxcxam\Larsadmin\Metrics\Results` namespace.

## Generators

```bash
php artisan larsadmin:value NewPosts
php artisan larsadmin:trend PostsPerDay
php artisan larsadmin:partition PostsByStatus
php artisan larsadmin:progress MonthlyPublishingGoal
```

Classes are created in `app/Larsadmin/Metrics` (`App\Larsadmin\Metrics\NewPosts`). Nested names work:
`Reports/Revenue` gives `App\Larsadmin\Metrics\Reports\Revenue`. `--force` overwrites an existing
class. Metric classes in `app/Larsadmin` are not registered as Entities.

> The generated class counts `App\Models\User` (a model class, **with no access restrictions**) so
> that the card works right away; the line is marked with a `// SECURITY: unscoped` comment. Replace
> the source with your Entity (see "Data source and security"). The Value stub already contains
> `->allowZeroResult()`, the Trend stub the `$unit` property.

## Your first metric

```php
namespace App\Larsadmin\Metrics;

use App\Larsadmin\Post;
use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\ValueResult;
use Maxcxam\Larsadmin\Metrics\Value;

class NewPosts extends Value
{
    public function name(): string
    {
        return 'Нові пости';
    }

    public function ranges(): array
    {
        return $this->labelled([7, 30, 90, 'MTD']);
    }

    public function calculate(MetricRequest $request): ValueResult
    {
        // Entity: only the rows the user can see are counted; 0 is shown as a number, not "No data"
        return $this->count($request, Post::class)->allowZeroResult();
    }
}
```

Add the card to `cards()` of a dashboard or an Entity:

```php
public function cards(Request $request): array
{
    return [
        NewPosts::make()->help('Дописи, створені за період.'),
    ];
}
```

## Card

Methods shared by all cards (class `Maxcxam\Larsadmin\Metrics\Card`):

| Method | Default | Description |
| --- | --- | --- |
| `make(...$args)` | | creates an instance (the arguments go to the constructor) |
| `name()` | the class name in Title Case, through `__()` | the card title; override the method |
| `uriKey()` | the class name in kebab-case | the card key in the URL. It must be unique within a dashboard or Entity: two cards with the same key (visible or not) throw `InvalidArgumentException` |
| `width(string)` | `'1/3'` | `'1/3'`, `'1/2'`, `'2/3'` or `'full'`. Any other value throws `InvalidArgumentException` |
| `help(string)` | | a hint: a "?" icon next to the title |
| `canSee(Closure)` | | `fn (Request $request): bool`. A hidden card is not shown, and its endpoint responds with 404 |

The width applies on large screens (a six-column grid). On a tablet, `1/3` and `1/2` cards take half a
row, `2/3` and `full` cards the whole row; on a phone, every card is full width.

## Ranges

`ranges()` returns the available periods as `key => label`. The user switches the period in a select on
the card (the select is shown when there is more than one period). `defaultRange()` returns the default
period: the first key of `ranges()`. An unknown or invalid `range` in the request is replaced with the
default period.

| Key | Current period | Compared with (Value) |
| --- | --- | --- |
| `N` (integer) | from the start of the day `N − 1` days ago until now | the same `N` days before it |
| `'TODAY'` | from the start of today | yesterday, the same span of time since midnight |
| `'MTD'` | from the start of the month | the previous month, the same span from its start |
| `'QTD'` | from the start of the quarter | the previous quarter, likewise |
| `'YTD'` | from the start of the year | the previous year, likewise |
| `'ALL'` | no date constraint | not compared |

Periods are half-open, `[start, end)`: a row exactly on a boundary falls into one period only, and the
previous period never reaches into the current one.

`$this->labelled([...])` builds `ranges()` with translated labels (the `larsadmin::metrics` file):
`TODAY`, `MTD`, `QTD`, `YTD`, `ALL` have their own labels, and an integer is labelled with the number
of days in the correct plural form ("14 days", "21 days"). You can also set the labels yourself:

```php
public function ranges(): array
{
    return [7 => 'Тиждень', 30 => 'Місяць', 'MTD' => 'Цей місяць'];
}

public function defaultRange(): int
{
    return 30;
}
```

Defaults:

| Type | `ranges()` |
| --- | --- |
| Value, Progress | `30, 60, 365, TODAY, MTD, QTD, YTD` |
| Trend | **the number of points** in `$unit` units: days `7, 30, 90`, weeks `4, 12, 26`, months `3, 6, 12` (see "Trend") |
| Partition | empty: the chart always counts all rows |

### Timezone

Period boundaries ("today", "month to date", the chart points) are computed in the panel timezone:

1. `Larsadmin::timezoneUsing(fn (Request $request) => ...)`, for example the timezone from the user
   profile;
2. `config('larsadmin.timezone')` (`LARSADMIN_TIMEZONE`);
3. `config('app.timezone')`.

Unknown timezone identifiers are skipped. Dates in the database are compared in `app.timezone`.

```php
// app/Providers/LarsadminServiceProvider.php, boot()
Larsadmin::timezoneUsing(fn (Request $request) => $request->user()?->timezone);
```

## Data source and security

The helpers (`count`, `sum`, `countByDays` and so on) take a `$model` source. **It decides which rows
end up in the number.**

| Source | Restriction |
| --- | --- |
| an Entity class: `Post::class` from `App\Larsadmin` | the rows of that Entity's `indexQuery()`; without the `viewAny` permission the card shows "You do not have access to this data." (403 response) |
| `$this->scoped(Post::class, fn (Builder $q) => ...)` or `EntitySource::make(Post::class, fn ...)` | the same as the Entity, plus your constraint |
| a model class: `\App\Models\Post::class` | **no restrictions at all** |
| an Eloquent builder (`Post::query()->where(...)`) or `DB::table(...)` | **no restrictions at all** |

> **Warning.** A model class and a builder count **every row** the query can reach, without
> `indexQuery()` and without policies. A user whom `indexQuery()` shows only their own records will
> see a number for the whole table in such a metric. Use them for global figures only, and hide the
> card with `canSee()` if the number is sensitive.

Narrow an Entity with `scoped()`, not through the model:

```php
use Illuminate\Database\Eloquent\Builder;

public function calculate(MetricRequest $request): ValueResult
{
    $live = $this->scoped(Post::class, fn (Builder $query) => $query->where('status', 'live'));

    return $this->sum($request, $live, 'views', 'published_at');
}
```

The `scoped()` constraint is added as a nested `where (...)`, so an `orWhere` inside it cannot escape
`indexQuery()`. `scoped()` (a metric method) and `EntitySource::make()` (class
`Maxcxam\Larsadmin\Metrics\EntitySource`) are equivalent; for a class that is not an Entity they throw
`InvalidArgumentException`.

More about sources:

- The model's global scopes (for example, `SoftDeletes`) apply to Eloquent sources.
- The default date column is the model's `created_at` (`getCreatedAtColumn()`). `DB::table()` has no
  model, so you must pass the date column explicitly, otherwise `InvalidArgumentException`.
- An `AuthorizationException` thrown in `calculate()` gives a 403 response and the "no access" state.

## Value

```php
protected function count(MetricRequest $request, $model, ?string $dateColumn = null): ValueResult
protected function sum(MetricRequest $request, $model, string $column, ?string $dateColumn = null): ValueResult
protected function average(MetricRequest $request, $model, string $column, ?string $dateColumn = null): ValueResult
protected function min(MetricRequest $request, $model, string $column, ?string $dateColumn = null): ValueResult
protected function max(MetricRequest $request, $model, string $column, ?string $dateColumn = null): ValueResult
protected function result(int|float|null $value): ValueResult   // your own value
```

The helpers compute the current period and the previous one (see the ranges table) and return both
numbers. The card shows the change in percent:

| State | What is shown |
| --- | --- |
| the value went up / down | "Up N%" / "Down N%" with an arrow |
| the change rounds to 0.0% | "No change" |
| the previous value is `0` | "No data for the previous period" |
| there is no previous period (`ALL`, `result()` without `previous()`) | the number only |
| the value is `null` (for example, `average` with no rows) | "No data" |
| the value is `0` without `allowZeroResult()` | "No data", no comparison |

`ValueResult` methods:

| Method | Description |
| --- | --- |
| `previous(int\|float\|null)` | the value of the previous period, for your own calculations |
| `allowZeroResult(bool $allow = true)` | show `0` as a real result (with the comparison). Without it, `0` is treated as no data. Counters such as "New posts" usually need it |
| formatting | see "Formatting" |

```php
public function calculate(MetricRequest $request): ValueResult
{
    $value = Order::query()->where('paid', true)->sum('total'); // model: no access restrictions

    return $this->result($value)->currency('UAH');
}
```

## Trend

```php
class NewUsersPerWeek extends Trend
{
    protected string $unit = 'week'; // unit of the default ranges: day, week or month

    public function calculate(MetricRequest $request): TrendResult
    {
        return $this->countByWeeks($request, User::class)->showSumValue();
    }
}
```

The `$unit` property (`'day'` by default) determines only the default ranges and their labels: days
`7, 30, 90`, weeks `4, 12, 26`, months `3, 6, 12` ("12 weeks"). The unit of the points is set by the
helper (`countByWeeks()` counts by week), so keep `$unit` in line with it. Define custom Trend ranges
as integers; `$this->bucketRanges([4, 8, 13])` labels them in `$unit` units. A string range key
(`'MTD'` and the like) in a Trend throws `InvalidArgumentException`. An unknown `$unit` also throws
`InvalidArgumentException`.

Helpers by time unit and aggregate:

| | by days | by weeks | by months |
| --- | --- | --- | --- |
| count | `countByDays($r, $model, ?$dateColumn)` | `countByWeeks(...)` | `countByMonths(...)` |
| sum | `sumByDays($r, $model, $column, ?$dateColumn)` | `sumByWeeks(...)` | `sumByMonths(...)` |
| average | `averageByDays(...)` | `averageByWeeks(...)` | `averageByMonths(...)` |
| minimum | `minByDays(...)` | `minByWeeks(...)` | `minByMonths(...)` |
| maximum | `maxByDays(...)` | `maxByWeeks(...)` | `maxByMonths(...)` |

There is also the general `aggregate($request, $model, string $unit, string $function, ?string $column = null, ?string $dateColumn = null)`
with `$unit` = `day|week|month` and `$function` = `count|sum|avg|average|min|max`.

- The range is the number of points: `30` gives 30 days (weeks, months), the last one being the
  current, not yet finished one. Weeks start on Monday. At most 366 points.
- Point boundaries are computed in the panel timezone and are correct across daylight saving time
  changes.
- Points without rows have `0` for `count`/`sum` and an empty value for `average`/`min`/`max`.
- The server formats the point labels in the panel language (`D MMM` for days and weeks, `MMM YYYY`
  for months).

The headline number above the chart is enabled explicitly:

| `TrendResult` method | Number above the chart |
| --- | --- |
| `showSumValue()` | the sum of all points; only for `count`/`sum`, throws `LogicException` for `average`/`min`/`max` |
| `showLatestValue()` | the value of the last (current) point |
| nothing | the chart only |

```php
public function calculate(MetricRequest $request): TrendResult
{
    $live = $this->scoped(Post::class, fn (Builder $query) => $query->where('status', 'live'));

    return $this->countByDays($request, $live, 'published_at')->showSumValue();
}
```

## Partition

```php
protected function count(MetricRequest $request, $model, string $groupBy): PartitionResult
protected function sum(MetricRequest $request, $model, string $column, string $groupBy): PartitionResult
protected function average(MetricRequest $request, $model, string $column, string $groupBy): PartitionResult
protected function min(MetricRequest $request, $model, string $column, string $groupBy): PartitionResult
protected function max(MetricRequest $request, $model, string $column, string $groupBy): PartitionResult
```

Rows are grouped by the `$groupBy` column; slices go from the largest. The range is not taken into
account.

| `PartitionResult` method | Description |
| --- | --- |
| `label(Closure)` | the slice label: `fn (string $key): string`. The key is always a string, `''` for `NULL` |
| `labelsFrom(string\|Builder $model, string $titleColumn = 'name', ?string $missing = null)` | labels from a related model, by primary key (see below) |
| `booleanLabels(?string $yes = null, ?string $no = null)` | "Yes"/"No" labels for the `'1'`/`'0'` keys of a boolean column (`''` gives "None") |
| `colors(array)` | CSS colors by key: `['live' => '#16a34a']`. Accepted: `#hex`, `rgb()`/`rgba()`, `hsl()`/`hsla()`, `oklch()` and `var(--token)`; other values, and slices without a color, take the theme palette |
| `limit(int $limit = 8)` | the maximum number of slices. For `count`/`sum` the rest is collected into an "Other" slice; for `average`/`min`/`max` the rest is dropped. Less than 1 throws `InvalidArgumentException` |

`label()`, `labelsFrom()` and `booleanLabels()` replace each other: the last call wins. Without them, a
slice is labelled with its key, and the `NULL` group with "None".

- **Boolean columns.** The values `true`/`false` become the keys `'1'`/`'0'` on any database. If the
  model has a `bool`/`boolean` cast for the column, `booleanLabels()` is applied automatically.
- **`average`/`min`/`max`.** Slice values do not add up, so the card shows neither a total nor
  percentages (in the JSON, `"additive": false`, `"total": null`).

```php
public function calculate(MetricRequest $request): PartitionResult
{
    return $this->count($request, Post::class, 'status')
        ->label(fn (string $status) => ['draft' => 'Чернетка', 'live' => 'Опубліковано'][$status] ?? $status)
        ->colors(['live' => '#16a34a', 'draft' => '#94a3b8']);
}
```

### Labels from a related model

When you group by a foreign key, `labelsFrom()` substitutes the name of the related record:

```php
return $this->count($request, Post::class, 'category_id')
    ->labelsFrom(Category::class, 'name', 'Без категорії')
    ->limit(6);
```

- The names are loaded with a single `whereIn` query, and only for the slices shown (after `limit()`).
- The `NULL` key gets `$missing` ("None" by default); a key without a record gets `#<key>`.
- You can pass a builder and your own name column: `labelsFrom(Category::query()->where('active', true), 'title')`.
- The related model is read **without** `indexQuery()` and policies, but only for keys that are already
  in the user's result. So it reveals the names of the groups the user already sees in the numbers.

### Custom values

```php
use Maxcxam\Larsadmin\Metrics\Results\PartitionResult;

$values = ['Підтверджені' => 12, 'Непідтверджені' => 3]; // key => value, largest first
arsort($values);

return new PartitionResult($values);
```

## Progress

```php
protected function count(MetricRequest $request, $model, int|float $target, ?string $dateColumn = null): ProgressResult
protected function sum(MetricRequest $request, $model, string $column, int|float $target, ?string $dateColumn = null): ProgressResult
protected function result(int|float|null $value, int|float $target): ProgressResult
```

Computes the value for the current period (without a comparison) and shows it as a percentage of
`$target`. `avoid()` means "lower is better" and inverts the colors (for example, for the number of
complaints).

```php
class MonthlyPublishingGoal extends Progress
{
    public function ranges(): array
    {
        return $this->labelled(['MTD']);
    }

    public function calculate(MetricRequest $request): ProgressResult
    {
        $live = $this->scoped(Post::class, fn (Builder $query) => $query->where('status', 'live'));

        return $this->count($request, $live, 80, 'published_at');
    }
}
```

## Formatting

The browser formats numbers with `Intl.NumberFormat` in the panel language. An empty value is shown
as "—".

| Method | Value | Trend | Partition | Progress | Description |
| --- | --- | --- | --- | --- | --- |
| `currency(string $iso)` | yes | yes | yes | yes | an ISO 4217 currency: `->currency('UAH')` |
| `format(array)` | yes | yes | yes | yes | `Intl.NumberFormat` options: `['maximumFractionDigits' => 1]`, `['notation' => 'compact']`, `['style' => 'percent']` |
| `prefix(string)`, `suffix(string)` | yes | yes | **no** | yes | text before and after the number: `->suffix(' шт.')`. `PartitionResult` has no such methods; use `currency()` or `format()` |

```php
return $this->sum($request, $live, 'views', 'published_at')->format(['notation' => 'compact']);
```

## Caching

By default a metric is computed on every request. To cache it, override `cacheFor()`:

```php
public function cacheFor(): int
{
    return 300; // seconds; also DateInterval or DateTimeInterface
}
```

- The cache key contains the card owner (dashboard or Entity), the metric class and `uriKey()`, the
  range, the timezone, the language, the guard and the **user** (model class + identifier), plus your
  own parts (see below). One user's result never reaches another user.
- Store: `config('larsadmin.metrics.cache_store')`; `null` means the default store.
- `null`, `0` or a negative value disables the cache. An access denial (403) is not cached.
- A cached number is stale until it expires. Keep the lifetime short for figures users act on.

### What is checked on a cache hit

Authorization is never cached:

- the `canSee()` of the dashboard and of the card runs on every request;
- for every Entity source the metric used (`Post::class`, `scoped(...)`), `viewAny` is checked again;
  if the permission was revoked, the response is 403.

**`calculate()` is not called on a cache hit.** So your own access checks inside `calculate()` (for
example, `abort_unless(...)` or `AuthorizationException`) do not run for a cached result. Move them
into the card's `canSee()`.

### What else the result depends on

If `calculate()` depends on anything other than the user and the range (the current team or tenant,
role, subdomain, session), add it to the key, otherwise a user gets a number from someone else's scope:

```php
// for one metric
public function cacheKeyParts(MetricRequest $request): array
{
    return [$request->request->user()->current_team_id];
}

// for all metrics, in boot() of the application service provider
Larsadmin::metricCacheScopeUsing(fn (Request $request, Metric $metric) => tenant()?->getKey());
```

`cacheKeyParts()` returns an array (`[]` by default); the `metricCacheScopeUsing()` callback returns
any value. Both must be JSON-encodable. `null` disables the callback.

## Rate limiting

The metric endpoints are limited by the named `larsadmin-metrics` limiter: `larsadmin.metrics.throttle`
requests per minute (`120` by default) per user, per IP for guests. `null` removes the limit. Each card
makes a separate request, and changing the range makes one more, so large dashboards may need a higher
limit.

## `MetricRequest`

`calculate()` receives a `Maxcxam\Larsadmin\Metrics\MetricRequest`:

| Property | Description |
| --- | --- |
| `$request->request` | the current `Illuminate\Http\Request` |
| `$request->range` | the validated range key (or `null`) |
| `$request->timezone` | the panel timezone |
| `$request->now` | the current moment (`CarbonImmutable`) in that timezone |

```php
[$from, $to] = [$request->now->startOfWeek(), $request->now];
```

## Endpoints

Cards load their data from `GET {path}/api/dashboards/{dashboard}/metrics/{metric}?range=` and
`GET {path}/api/entities/{entity}/metrics/{metric}?range=`. Both require access to the panel.

| Situation | Response |
| --- | --- |
| the dashboard or card is not visible (`canSee`), the card is not a metric | 404 |
| no `viewAny` for the Entity whose cards are requested | 403 |
| an Entity source without `viewAny` (also on a cache hit), `AuthorizationException` in `calculate()` | 403 |
| the `larsadmin-metrics` limit is exceeded | 429 |

## Known limitations

- Metric SQL is tested in CI on SQLite only. On SQLite, date-only (`DATE`) columns in trends land in
  the previous day's point; use `DATETIME`/`TIMESTAMP`.
- Each card is loaded with a separate HTTP request.

Next: [Dashboards](14-dashboards.md).
