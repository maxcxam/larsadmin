# 06. Filters
🇺🇦 [Українська](../06-filters.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/en/docs/filters)

Filters narrow down an Entity's list of records. Their state is kept in the query string:
`?filters[<key>]=<value>`. Filters apply on index, in the `HasMany`/`BelongsToMany` panels, and to actions
run with `resources: 'all'` ([Actions](07-actions.md)).

Filters are returned by the `Entity::filters(Request $request): array` method:

```php
use Maxcxam\Larsadmin\Filters\BooleanFilter;
use Maxcxam\Larsadmin\Filters\DateRangeFilter;
use Maxcxam\Larsadmin\Filters\SelectFilter;

public function filters(Request $request): array
{
    return [
        SelectFilter::make('Статус', 'status')->withOptions([
            'draft' => 'Чернетка',
            'live' => 'Опубліковано',
        ]),
        BooleanFilter::make('Опубліковано', 'published'),
        DateRangeFilter::make('Дата публікації', 'published_at', 'published-range'),
    ];
}
```

## Built-in filters

All three are created with `Filter::make(?string $label, ?string $column, ?string $key)`:

- `$label` is the label in the UI (translated with `__()`);
- `$column` is the model column (qualified with the model's table);
- `$key` is the key in the query string. Defaults to `$column` with `.` replaced by `_`.

| Filter | Value in the request | Extras |
| --- | --- | --- |
| `SelectFilter` | one of the `options` keys | `withOptions(array\|Closure)`, `defaultTo(string\|int\|null)` |
| `BooleanFilter` | `1` or `0` (Yes/No) | `defaultTo(?bool)` |
| `DateRangeFilter` | `filters[key][from]=Y-m-d&filters[key][to]=Y-m-d` | half-open interval `[from, to + 1 day)`: `>= from` and `< to + 1 day`; either bound can be omitted |

`withOptions()` accepts a `value => label` array or a closure `fn (Request $request): array`:

```php
SelectFilter::make('Перегляди', 'views', 'v')->withOptions(fn () => [10 => 'Десять', 20 => 'Двадцять']);
```

### Default values

When the filter's key is missing from the query string, its `defaultTo()` value is applied. An explicit
empty value (`filters[status]=`) clears the filter, and the default is then not applied. This is how the
UI clears a filter that has a default value.

```php
SelectFilter::make('Статус', 'status')->withOptions([...])->defaultTo('live');
```

### Hidden filters

`canSee(Closure)` hides a filter; neither its value nor its default value is applied:

```php
SelectFilter::make('Внутрішній', 'status', 'internal')
    ->withOptions(['review' => 'На перевірці'])
    ->canSee(fn (Request $request) => $request->user()->can('moderate'));
```

## Filters are UX, not a security boundary

Filters help the user, but they **do not restrict access to data**. A filter hidden with `canSee` stops
applying together with its default value, and the user can remove or change the value in the query
string at any time. Always scope rows (own records, tenant, and so on) in `indexQuery` / `detailQuery`
([Authorization](05-authorization.md)).

## Value safety

- Unknown keys, disallowed filters and invalid values are **silently ignored** (no exception is thrown).
  For a select, the value must be one of the `options` keys and at most 255 characters long.
- Each filter is applied in its own `where (...)` group, so it cannot escape the Entity's `indexQuery`,
  even if its `apply()` uses `orWhere`.

## Custom filter

```bash
php artisan larsadmin:filter StatusFilter
```

The `--force` option overwrites an existing class.
The class is created as `App\Larsadmin\Filters\StatusFilter`. The generator writes a stub with an empty
options list for you to fill in; once edited, it can look like this:

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
        return [
            'draft' => 'Draft',
            'live' => 'Live',
        ];
    }
}
```

To use it: `StatusFilter::make()`.

Methods of the `Maxcxam\Larsadmin\Filters\Filter` base class:

| Method | Purpose |
| --- | --- |
| `apply($request, $query, $value): Builder` | **required**; receives the already normalized value |
| `name()` | the label; derived from the class name by default |
| `key()` | the key in the request; the kebab-case class name by default (`StatusFilter` gives `status-filter`) |
| `options($request)` | the `value => label` options |
| `default()` | the value used when the key is missing from the request |
| `normalize($request, $raw)` | converts the raw value; `null` means "filter not active" |
| `canSee(Closure)` | visibility condition |
| `$component` | the frontend component: `select-filter`, `boolean-filter`, `date-range-filter` or an extension key `vendor/name` |

The generator stub creates a select filter. For other value logic, override `normalize()` and `apply()`.
A custom frontend component for a new filter type comes from an extension (see
[Extensions: filters](19-extensions.md#filters)), and the stable hook `serialize(Request $request, mixed $value)`
gives the component extra props (the result of `parent::serialize()` with every base key, plus yours); the
UI of the built-in filters can be
[overridden](19-extensions.md#overriding-built-in-components).

Next: [Actions](07-actions.md).
