---
name: larsadmin-global-search
description: "Larsadmin global search: making entities globally searchable, searched columns, result titles and subtitles, limits, the Laravel Scout integration and the search endpoint. Use when enabling or tuning the panel-wide search box in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin global search

## When to use

- Make an Entity appear in the Ctrl+K / ⌘K search palette.
- Change what a result shows (title, subtitle) or how many results an Entity returns.
- Switch an Entity to Laravel Scout, or back to SQL.
- Debug "my Entity is not in the search" or "a user finds records they should not".

The list page search box uses the same `$search` columns (skill `larsadmin-entities`).

## Quick start

```php
namespace App\Larsadmin;

use Maxcxam\Larsadmin\Entity;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title', 'category.name'];

    public static bool $globallySearchable = true;

    public static ?string $subtitle = 'category.name';
}
```

That is all: the header gets a search button once the user may list at least one globally searchable
Entity. Check the current state with the MCP tool `describe-entity` (`entity: "posts"`): its
`statics` show `search`, `title`, `subtitle` and `globallySearchable`.

## Recipes

### Limit results per Entity

```php
namespace App\Larsadmin;

use Maxcxam\Larsadmin\Entity;

class Customer extends Entity
{
    public static string $model = \App\Models\Customer::class;

    public static array $search = ['id', 'email', 'name'];

    public static bool $globallySearchable = true;

    public static ?int $globalSearchLimit = 10; // null: larsadmin.search.limit (5); clamped to 1..20
}
```

### Custom subtitle

```php
namespace App\Larsadmin;

use Maxcxam\Larsadmin\Entity;

class User extends Entity
{
    public static string $model = \App\Models\User::class;

    public function subtitle(): ?string
    {
        // Your code decides visibility here: never return data hidden from this user.
        return $this->record->email.' · '.$this->record->created_at?->format('d.m.Y');
    }
}
```

A `$subtitle` / `$title` of the form `relation.attribute` is one level deep only, eager-loaded
automatically, and empty when the user cannot see the related Entity, record or field.

### Laravel Scout

If the model uses `Laravel\Scout\Searchable` and `laravel/scout` is installed, the Entity searches
through the engine automatically. Candidates are then reloaded through `indexQuery()` and checked
against `view`. Pre-filter in the engine for multi-tenant apps:

```php
namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;

class Invoice extends Entity
{
    public static string $model = \App\Models\Invoice::class;

    public static bool $globallySearchable = true;

    /**
     * @param  \Laravel\Scout\Builder  $search
     * @return \Laravel\Scout\Builder
     */
    public static function scoutQuery(Request $request, $search)
    {
        return $search->where('team_id', $request->user()->team_id);
    }
}
```

Keep the parameters untyped (the code must load without Scout). `scoutQuery()` only improves candidate
choice; `indexQuery()` still applies. Raise `larsadmin.search.scout_candidates` if results are missing.

Back to SQL for an Entity whose index holds data some users must not see:

```php
namespace App\Larsadmin;

use Maxcxam\Larsadmin\Entity;

class Employee extends Entity
{
    public static string $model = \App\Models\Employee::class;

    public static function usesScout(): bool
    {
        return false;
    }
}
```

### Tune the endpoint

`config/larsadmin.php`, section `search`: `larsadmin.search.limit` (5),
`larsadmin.search.min_length` (2; digits-only terms always search, so ids work),
`larsadmin.search.throttle` (60 per minute, limiter `larsadmin-search`),
`larsadmin.search.scout_candidates` (50), `larsadmin.search.time_budget_ms` (3000; `partial: true`
when it runs out).

The JSON endpoint is `GET {path}/api/search?search=term` (route `larsadmin.api.search`), returning
`groups`, `failed` and `partial`.

## API cheat sheet

| Entity member | Default | Notes |
| --- | --- | --- |
| `$globallySearchable` | `false` | opt-in per Entity |
| `$search` | `['id']` | columns, the key, or `relation.column` (scoped by the related `indexQuery()`) |
| `$title` | `'id'` | result title; empty shows `#<id>` |
| `$subtitle` / `subtitle()` | `null` | second line; `subtitle()` overrides |
| `$globalSearchLimit` | `null` | per-Entity limit, 1..20 |
| `usesScout()` | auto | `true` when the model uses Scout's `Searchable` |
| `scoutQuery($request, $search)` | identity | engine-side pre-filter |

Order: titles starting with the term first, then newest by key. Filters and `$sort` do not apply.

## Security rules

- MUST scope rows in `indexQuery()`: the search only returns rows from it, then checks `view` per row.
  A missing policy follows `allow_without_policy`.
- MUST keep fields hidden with `canSee()` out of `toSearchableArray()`, or return `false` from
  `usesScout()`: on the Scout path the engine index decides what matches, so users can probe hidden
  values.
- NEVER return data the user may not see from an overridden `title()` or `subtitle()`; the panel's
  visibility rules apply only to `$title` / `$subtitle`.
- NEVER treat `scoutQuery()` as the access boundary; it is a performance filter.

## Pitfalls

- An Entity with `$globallySearchable = true` but empty `$search` and no Scout is silently skipped.
- No results when every `$search` column is hidden by `canSee()`, or when the only column is an integer
  key and the term is not numeric.
- `LIKE '%term%'` cannot use a B-tree index; prefer Scout for big tables.
- On SQLite `LIKE` is case-insensitive for Latin letters only.
- Scout engine errors list the Entity under `failed`; there is no SQL fallback. The list page search
  stays SQL, so it can differ from the palette.
- Scout's `database` engine treats `%` and `_` in the term as wildcards.

## Reference

- Guide: `vendor/maxcxam/larsadmin/docs/guide/en/16-search.md` (MCP: `read-docs` with
  `page: "16-search"`; e.g. `section: "laravel-scout"`).
- Related skills: `larsadmin-entities` (`$title`, `$search`), `larsadmin-authorization-security`.
