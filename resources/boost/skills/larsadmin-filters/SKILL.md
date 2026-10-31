---
name: larsadmin-filters
description: "Larsadmin filters: Filter classes, BooleanFilter, SelectFilter and DateRangeFilter, options and defaults, and the filter generator; a filter narrows a list but is never a security boundary. Use when adding or changing a filter on an entity list in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin filters

## When to use

- Add a dropdown, yes/no or date-range filter to an Entity list.
- Write a custom filter class with its own query logic.
- Debug a filter that "does nothing" (invalid values are silently ignored).

Not for access control: rows a user must not see are removed in `indexQuery()` / `detailQuery()`
(skill `larsadmin-authorization-security`). Not for the search box (skill `larsadmin-entities`) or the
panel-wide search (skill `larsadmin-global-search`).

## Quick start

Built-in filters need no class. Return them from `Entity::filters()`:

```php
namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Filters\BooleanFilter;
use Maxcxam\Larsadmin\Filters\DateRangeFilter;
use Maxcxam\Larsadmin\Filters\SelectFilter;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;

    public function filters(Request $request): array
    {
        return [
            SelectFilter::make('Status', 'status')->withOptions([
                'draft' => 'Draft',
                'live' => 'Live',
            ]),
            BooleanFilter::make('Featured', 'featured'),
            DateRangeFilter::make('Published', 'published_at', 'published-range'),
        ];
    }
}
```

`make(?string $label, ?string $column, ?string $key)`: the label goes through `__()`, the column is
qualified with the model's table, the key (query string name) defaults to the column with `.` replaced
by `_`. State lives in the URL: `?filters[status]=live`.

A custom filter class:

```bash
php artisan larsadmin:filter StatusFilter        # app/Larsadmin/Filters/StatusFilter.php
php artisan larsadmin:filter StatusFilter --force  # overwrite
```

With the MCP server connected, `generate` with `kind: "filter"` returns the same file as a plan first;
call it again with `write: true` to write it.

## Recipes

### Select filter with dynamic options and a default

```php
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\SelectFilter;

$filter = SelectFilter::make('Category', 'category_id', 'category')
    ->withOptions(fn (Request $request) => \App\Models\Category::query()->pluck('name', 'id')->all())
    ->defaultTo('1');
```

- The default applies only when the key is missing. An explicit empty value (`filters[category]=`)
  clears the filter, and the default is then not applied.
- `BooleanFilter::defaultTo(?bool)`, `SelectFilter::defaultTo(string|int|null)`.

### Date range

`DateRangeFilter` reads `filters[key][from]=Y-m-d&filters[key][to]=Y-m-d` and applies the half-open
interval `>= from` and `< to + 1 day`. Either bound may be omitted.

### Filter only some users see

```php
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\SelectFilter;

$filter = SelectFilter::make('Internal', 'status', 'internal')
    ->withOptions(['review' => 'In review'])
    ->canSee(fn (Request $request) => $request->user()->can('moderate'));
```

A hidden filter is not applied at all, not even its default. It does not hide rows from anyone.

### Custom filter class

```php
namespace App\Larsadmin\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\Filter;

class StatusFilter extends Filter
{
    public string $component = 'select-filter';

    public function name(): string
    {
        return __('Status');
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        return $query->where('status', $value);
    }

    public function options(Request $request): array
    {
        return ['draft' => 'Draft', 'live' => 'Live'];
    }
}
```

Register it with `StatusFilter::make()` in `filters()`. The key defaults to the kebab-case class name
(`status-filter`). For other value shapes override `normalize($request, $raw)` (return `null` for "not
active") and keep `apply()` working on the normalized value.

### Filter with its own UI

Set `$component` to an extension key (`vendor/name`) and pass extra props from
`serialize(Request $request, mixed $value)` (merge with `parent::serialize()`). The Vue side is covered
by skill `larsadmin-extending`.

### Check what an Entity already has

With the MCP server, `describe-entity` (`entity: "posts"`) lists the Entity's filters, actions and cards
without running your closures. Read it before adding a filter with a duplicate key.

## API cheat sheet

| API | Notes |
| --- | --- |
| `Maxcxam\Larsadmin\Filters\SelectFilter` | `withOptions(array\|Closure)`, `defaultTo(string\|int\|null)`; value must be an option key, max 255 chars |
| `Maxcxam\Larsadmin\Filters\BooleanFilter` | value `1` / `0`; `defaultTo(?bool)` |
| `Maxcxam\Larsadmin\Filters\DateRangeFilter` | `[from, to + 1 day)`; either bound optional |
| `Maxcxam\Larsadmin\Filters\Filter` | base class: `apply()` (required), `name()`, `key()`, `options()`, `default()`, `normalize()`, `serialize()`, `canSee()`, `$component` |
| `$component` | `select-filter`, `boolean-filter`, `date-range-filter` or `vendor/name` |
| `Entity::filters(Request $request): array` | where filters are registered |
| `larsadmin:filter Name [--force]` | generates `App\Larsadmin\Filters\Name` (a select filter stub) |

Where filters apply: the index list, `HasMany` / `BelongsToMany` panels, and actions run with
`resources: 'all'` (skill `larsadmin-actions`).

## Security rules

- MUST scope rows (own records, tenant, soft-deleted) in `indexQuery()` / `detailQuery()`, never with a
  filter. A user can remove or change any filter value in the query string.
- NEVER rely on `canSee()` or `defaultTo()` of a filter to hide data: a hidden filter simply stops
  applying.
- MUST treat `$value` in `apply()` as user input: use the query builder with bindings, never
  `whereRaw()` with string concatenation, and never use the value as a column name.
- MUST keep `options()` free of data the user may not see (option labels are sent to the browser).

## Pitfalls

- Invalid values, unknown keys and hidden filters are silently ignored: no exception, the list is
  just unfiltered. Check the key in the URL and the option keys.
- Two filters with the same key: give one an explicit third `make()` argument.
- `orWhere` inside `apply()` is safe (each filter runs in its own nested `where (...)` group), but
  still prefer explicit grouping for readability.
- Select option keys arrive as strings from the query string; compare loosely or cast in
  `normalize()`.
- The generator stub has an empty options list; fill it in before use.

## Reference

- Guide: `vendor/maxcxam/larsadmin/docs/guide/en/06-filters.md` (MCP: `read-docs` with
  `page: "06-filters"`; find sections with `search-docs`).
- Custom filter components: `vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md#filters`.
- Related skills: `larsadmin-entities`, `larsadmin-authorization-security`, `larsadmin-actions`.
