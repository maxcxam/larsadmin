# 16. Global search
🇺🇦 [Українська](../16-search.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/en/docs/search)

The global search finds records across several Entities from any page of the panel. It opens with the
"Search…" button in the header or the **Ctrl+K** hotkey (**⌘K** on macOS) and shows the results
grouped by Entity, in sidebar order.

Entities take part in the search only when they opt in. Records are found with plain SQL over the
`$search` columns or, when the model uses Laravel Scout, through the search engine. Either way, records
only come from `indexQuery()` and are checked against the policies. `canSee` applies on the SQL path (to
the search columns) and to the result's title and subtitle. On the Scout path, matching uses the engine's
index (`toSearchableArray()`), so a field hidden with `canSee` can still make a record match: keep such
fields out of the index (see [Turning Scout off for an Entity](#turning-scout-off-for-an-entity)).

No extra Composer or npm dependencies are needed. Scout is optional.

## Quick start

```php
class Post extends Entity
{
    public static string $model = \App\Models\Post::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title', 'category.name'];

    public static bool $globallySearchable = true;

    public static ?string $subtitle = 'category.name';
}
```

That is all: the header gets a search button, and Ctrl/⌘+K opens the palette. A result shows the
record's title (`$title`) and subtitle (`$subtitle`) and links to the record page.

The button and the hotkey only appear when the current user may list at least one Entity with
`$globallySearchable = true`.

## Entity settings

| Member | Type, default | Description |
| --- | --- | --- |
| `$globallySearchable` | `bool`, `false` | Whether the Entity takes part in the global search |
| `$globalSearchLimit` | `?int`, `null` | How many records to show for the Entity. `null` uses `larsadmin.search.limit` (5). The value is clamped to 1..20 |
| `$subtitle` | `?string`, `null` | Attribute of the result's second line: a column or a one-level `relation.attribute` (see [visibility](#visibility-of-the-title-and-subtitle)). `null` turns the subtitle off |
| `subtitle()` | `?string` | The result's second line. Override it to build the line yourself. An empty string counts as no subtitle |
| `usesScout()` | `bool` | Whether to search through Laravel Scout instead of the `$search` columns (see [Laravel Scout](#laravel-scout)) |
| `scoutQuery($request, $search)` | `\Laravel\Scout\Builder` | Hook to customize the Scout engine query |

The result's title is built from `$title` ([Record title](02-entities.md#record-title)). An empty
title is shown as `#<id>`.

### Visibility of the title and subtitle

The `$subtitle` subtitle is `null` when:

- its field is hidden with `canSee` (judged by the first path segment, so `category.name` follows the
  `category` field);
- it is a `relation.attribute` and the related model has no registered Entity;
- the user lacks `viewAny` on the related Entity;
- `attribute` is hidden with `canSee` on the related Entity;
- the related record is outside the related Entity's `detailQuery()`;
- the relation is to-many;
- the path goes deeper than one relation (`a.b.c`);
- the value is an array or an object.

Enums are shown as `->value` (backed) or `->name`.

A `relation.attribute` `$title` follows the same rules but becomes `''` instead of `null`, and the
palette then shows `#<id>`. A plain-column title is unchanged. A one-level relation used in `$title` or
`$subtitle` is eager-loaded automatically and never lazy-loaded; it does not need `$with`.

An overridden `title()` or `subtitle()` is used as is: your code is responsible for the visibility of
what it returns.

A custom subtitle:

```php
public function subtitle(): ?string
{
    return $this->record->email.' · '.$this->record->created_at?->format('d.m.Y');
}
```

An Entity hidden from the menu (`$displayInNavigation = false`) is still searched when it has
`$globallySearchable = true`.

## What is searched

Without Scout, the search uses the same logic as the search box of the list page
([Search](02-entities.md#search)):

- `$search` columns: `LIKE` contains, with `%`, `_` and `!` escaped; `ilike` (case-insensitive) on
  PostgreSQL;
- the model key: equality; for an integer key, only a numeric term is used;
- `relation.column`: only among the records the related Entity's `indexQuery()` lets through;
- columns of fields hidden from the user with `canSee` are not searched.

Records only come from the Entity's `indexQuery()`. The Entity's filters (`filters()`) and `$sort` are
not applied.

Result order: records whose title starts with the term first, then the newest by key (`desc`). The
title order only applies when `$title` is a plain column from `$search`, is not the key and is not
hidden by `canSee`. Otherwise records are ordered by key only.

An Entity returns nothing when no `$search` column applies to the term: all columns are hidden by
`canSee`, or the only `$search` entry is an integer key and the term is not numeric. This way the
search never returns every row of the table.

An Entity with `$globallySearchable = true` but an empty `$search` and no Scout is silently skipped.

### The term

- The term is trimmed and may not be longer than 100 characters.
- Terms of at least `larsadmin.search.min_length` characters (2) are searched. A digits-only term is
  searched from one character, so a record can be found by its ID.
- On the SQL path, a digits-only term shorter than `min_length` matches the key only, and only when the
  key is in `$search` and not hidden with `canSee`. A Scout Entity passes such a term to the engine as
  usual.
- A shorter term sends no database queries: the response has empty groups.

### "View all"

Below the results of each Entity with a non-empty `$search` there is a "View all — Label" row. It
opens the Entity's list with the same term in its search box (`?search=`). An Entity without `$search`
(Scout only) has no such row. The list search is always SQL, so for a Scout Entity the list may differ
from the palette results.

## Configuration

`config/larsadmin.php`:

```php
'search' => [
    'limit' => 5,
    'min_length' => 2,
    'throttle' => 60,
    'scout_candidates' => 50,
    'time_budget_ms' => 3000,
],
```

| Key | Default | Description |
| --- | --- | --- |
| `search.limit` | `5` | Results per Entity; `$globalSearchLimit` takes precedence. Clamped to 1..20 |
| `search.min_length` | `2` | The shortest term that is searched (at least 1). A digits-only term is always searched |
| `search.throttle` | `60` | Requests per minute per user (the `larsadmin-search` limiter); `null` turns the limit off |
| `search.scout_candidates` | `50` | How many keys to take from the Scout engine before rows are scoped. Clamped to 1..500, but at least the limit + 1 |
| `search.time_budget_ms` | `3000` | Time budget of one search in milliseconds; `null` turns the budget off |

A config published before this section existed works with the defaults.

## Laravel Scout

When an Entity's model uses the `Laravel\Scout\Searchable` trait and `laravel/scout` is installed, the
global search for that Entity goes through the Scout engine automatically. larsadmin does not depend on
Scout: it only lists it under `suggest` (`laravel/scout` ^11.0).

```php
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'team_id' => $this->team_id,
        ];
    }
}
```

How the Scout path works:

1. The engine returns up to `search.scout_candidates` candidate keys in its relevance order.
2. The rows are always loaded from the database through the Entity's `indexQuery()` by those keys. A
   key outside the user's scope, or no longer in the database, is dropped.
3. The engine order is kept. Each record is then checked against the `view` policy, as on the SQL path.

`$search` and `canSee` do not affect matching on the Scout path: what is searched is decided by the
model's `toSearchableArray()` (ADR-021). The result's title and subtitle follow the
[visibility rules](#visibility-of-the-title-and-subtitle), as on the SQL path. Keep fields hidden with
`canSee` out of `toSearchableArray()`.

### Turning Scout off for an Entity

When the index holds data some users must not see (for example, a field hidden with `canSee`), searching
the engine lets them test whether a record with a given value exists, even though the value itself is
not shown. Switch such Entities back to the SQL path:

```php
public static function usesScout(): bool
{
    return false;
}
```

Or keep sensitive fields out of `toSearchableArray()`.

### Pre-filtering in the engine: `scoutQuery()`

The engine knows nothing about `indexQuery()`. When `indexQuery()` drops most candidates (typical for
multi-tenant apps), there may be fewer results than the limit even though matching records exist.
Filter the candidates in the engine already:

```php
use Illuminate\Http\Request;

/**
 * @param  \Laravel\Scout\Builder  $search
 * @return \Laravel\Scout\Builder
 */
public static function scoutQuery(Request $request, $search)
{
    return $search->where('team_id', $request->user()->team_id);
}
```

Row scoping through `indexQuery()` is applied afterwards in any case, so `scoutQuery()` only improves the
choice of candidates and does not replace `indexQuery()`. The parameters have no native types on
purpose: this way the code also works in projects without Scout. Raising `search.scout_candidates` is
another way to soften the problem.

### Engine errors

When the engine is unavailable or throws, the Entity is listed under "Could not search: …" and the
exception goes to `report()`. There is no SQL fallback. The other Entities answer as usual.

## Authorization

The global search shows nothing the user would not see in the Entity's list:

1. The endpoint runs in the `auth_middleware` group: a guest gets 401, a user without panel access
   (`viewLarsadmin`) gets 403.
2. An Entity without `$globallySearchable` or without the `viewAny` permission is silently skipped and
   not mentioned in the response. A policy without a `viewAny` method denies (fail closed); a model
   without a policy follows `allow_without_policy`.
3. Rows only come from `indexQuery()`, on both the SQL and the Scout path.
4. Columns of fields hidden with `canSee` are neither searched nor used for ordering (SQL path). On the
   Scout path, the engine's index decides what matches.
5. Each result is checked against the `view` policy; records without permission are not shown.
6. A title or subtitle from a field hidden with `canSee`, or from a related record the user may not see,
   is not shown (see [Visibility of the title and subtitle](#visibility-of-the-title-and-subtitle)).
7. Records whose key cannot be safely put into a URL path are not shown.
8. The link leads to the regular record page, which checks `detailQuery()` and `view` again.

An error in one Entity does not break the response: the Entity goes to `failed` and the exception to
`report()`. An authorization exception or `abort(403)` / `abort(404)` in `indexQuery()` means "the
Entity is not available": it is silently skipped without an entry in `failed`. Other HTTP errors (for
example `abort(500)`) put it in `failed`. An exception during the `viewAny` check also skips the Entity
without mentioning it in the response.

Results are not cached, and the response carries `Cache-Control: no-store, private`.

See also [Authorization](05-authorization.md).

## Interface and keyboard

- **Open:** the "Search…" button in the header (an icon on narrow screens) or Ctrl+K / ⌘K. The hotkey
  also works with a non-Latin keyboard layout. The palette does not open on top of another modal.
  Pressing Ctrl/⌘+K again in the open palette selects the query text.
- **Select:** ↑/↓ cycle through all results and "View all" rows; hovering with the mouse also makes a
  row active.
- **Open a result:** Enter or click. Ctrl+Enter (⌘+Enter), a middle click or Ctrl/⌘+click open it in
  a new tab. On macOS the key hint shows ⌘ instead of Ctrl.
- **Close:** Esc or a click outside the palette; focus returns to where it was.

The request is sent 250 ms after the last keystroke; a previous unfinished request is cancelled. The
match in the title is highlighted. The palette only shows same-origin links (relative panel paths) and
drops any other URL. There is no query history and no recent records: the empty palette
only shows a hint.

When the search ran out of its time budget, the palette says that not all sections were searched. When
some Entities failed, the palette names them. A failed request, including a rate limit (429), can be
retried with the "Try again" button.

The palette is a modal dialog with a combobox and a listbox (WCAG 2.1 AA): the number of results is
announced to screen readers after each response.

## HTTP endpoint

The palette calls a JSON endpoint that you can also use directly:

```
GET /admin/api/search?search=kyiv
```

Route `larsadmin.api.search`, limiter `larsadmin-search`.

```json
{
  "search": "kyiv",
  "groups": [
    {
      "entity": "posts",
      "label": "Posts",
      "results": [
        { "id": 12, "title": "Kyiv in autumn", "subtitle": "Travel", "url": "/admin/entities/posts/12" }
      ],
      "more": true,
      "indexUrl": "/admin/entities/posts?search=kyiv"
    }
  ],
  "failed": [{ "entity": "users", "label": "Users" }],
  "partial": false
}
```

- `groups`: only Entities with results, in sidebar order.
- `more`: more records matched than the limit (counted before the `view` check).
- `indexUrl`: the "View all" link, or `null` when the Entity has no `$search`.
- `failed`: Entities whose search ended with an error.
- `partial`: the time budget ran out and some Entities were not searched.

| Situation | Code |
| --- | --- |
| Guest | 401 |
| No panel access | 403 |
| `search` missing, empty, an array or longer than 100 characters | 422 |
| `search.throttle` exceeded | 429 |
| No available Entity, or the term is too short | 200 with empty `groups` |

The frontend learns whether to show the search from the shared Inertia prop `larsadmin.search`: `null`
when no Entity with search is available to the user, otherwise `{ minLength, maxLength }`.

## Performance

Each search runs a separate query for every available Entity with `$globallySearchable` (plus
subqueries for `relation.column`, `$with` eager loads and `view` checks). Entities are searched one
after another.

- Index the `$search` columns. `LIKE '%term%'` cannot use a regular B-tree index, so Scout is the
  better fit for large tables.
- The `search.time_budget_ms` budget is checked before each Entity: once it runs out, the remaining
  Entities are skipped and the response has `partial: true`. The budget does not interrupt a single slow
  query: limit query run time in the database itself (statement timeout).
- Enable `$globallySearchable` only where the search is really needed.

## Limitations

- For Scout Entities, the search box of the list page stays SQL over `$search`.
- No full-text search or ranking on the SQL path: the term is matched as a whole substring.
- No search over dashboards, metrics or actions, no commands in the palette, no query history, no
  result icons and no pagination in the palette.
- The hotkey is Ctrl/⌘+K only and cannot be changed.
- On SQLite, `LIKE` ignores case for Latin letters only: "огляд" does not find "Огляд" (the same holds
  for the list search and the Scout `database` engine). MySQL and PostgreSQL collations handle it.
- Scout's `database` engine does not escape `%` and `_` in the term, so they act as `LIKE` wildcards
  (SECS-3). The SQL path escapes them.

Next: [Audit log](17-audit-log.md).
