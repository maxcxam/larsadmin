---
name: larsadmin-entities
description: "Larsadmin entities: Entity classes for Eloquent models, their static properties, index, detail and relatable queries, record titles and subtitles, per-entity actions, filters and cards, and the entity generator. Use when adding an admin CRUD screen for a model, or changing which records an entity lists or how a record is titled, in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin entities

## When to use

- Adding an admin screen (list, detail, create, edit, delete) for an Eloquent model.
- Changing an entity's labels, navigation group, search columns, default sort or page sizes.
- Restricting which rows an entity lists, shows or offers in relation pickers.
- Attaching actions, filters or metric cards to an entity.

Fields and form layout: `larsadmin-fields-layout`. Relation fields: `larsadmin-relations`. Policies and
security: `larsadmin-authorization-security`.

## Quick start

```bash
php artisan larsadmin:entity Post --model=Post
```

This writes `app/Larsadmin/Post.php` (class `App\Larsadmin\Post`, model `App\Models\Post`; without
`--model` the model comes from the class name; `--force` overwrites). Classes in `app/Larsadmin` are
registered automatically by the application provider. With the MCP server, call
`generate {kind: "entity", name: "Post", options: {model: "Post"}}` first to see the plan, then repeat it
with `write: true`.

```php
namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;

class Category extends Entity
{
    public static string $model = \App\Models\Category::class;
    public static string $title = 'name';
    public static array $search = ['id', 'name', 'slug'];
    public static ?string $group = 'Content';
    public static ?string $label = 'Categories';
    public static ?string $singularLabel = 'Category';

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name', 'name')->sortable()->rules('required', 'max:255'),
        ];
    }
}
```

`fields()` is the only required method. Then write a policy for the model (see
`larsadmin-authorization-security`) and check the result with the MCP `describe-entity {entity: "categories"}`.

## Recipes

### Scope rows to the current user or tenant

```php
namespace App\Larsadmin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;

class OwnPost extends Entity
{
    public static string $model = \App\Models\Post::class;

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('user_id', $request->user()->getKey());
    }

    public function fields(Request $request): array
    {
        return [ID::make()];
    }
}
```

`detailQuery()` (show, edit, update, delete, actions on selected records) and `relatableQuery()`
(relation pickers and their validation) call `indexQuery()` by default. Override them only to loosen or
tighten. A record outside `detailQuery()` answers 404.

### Default sort, page size, eager loads

```php
class Post extends \Maxcxam\Larsadmin\Entity
{
    public static string $model = \App\Models\Post::class;
    public static string $title = 'author.name';
    public static array $with = ['author'];                 // only extra relations, here for $title
    public static array $sort = ['published_at' => 'desc']; // keys: visible ->sortable() fields
    public static int $perPage = 50;
    public static array $perPageOptions = [25, 50, 100];
}
```

Relations needed by fields (`BelongsTo`) are eager-loaded automatically.

### Search columns

`$search` entries: a column (`LIKE`), the key name (equality; numeric terms only for integer keys), or
`relation.column` one level deep (only rows the related entity's `indexQuery()` allows). `[]` disables
search. Global search (Ctrl/Cmd+K) is opt-in with `$globallySearchable` (see `larsadmin-global-search`).

### Actions, filters and cards

```php
namespace App\Larsadmin;

use App\Larsadmin\Actions\MarkLive;
use App\Larsadmin\Metrics\NewPosts;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Filters\SelectFilter;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;

    public function fields(Request $request): array
    {
        return [];
    }

    public function actions(Request $request): array
    {
        return [new MarkLive];
    }

    public function filters(Request $request): array
    {
        return [SelectFilter::make('Status', 'status')->withOptions(['draft' => 'Draft', 'live' => 'Live'])];
    }

    public function cards(Request $request): array
    {
        return [NewPosts::make()]; // metrics above the list
    }
}
```

Details: `larsadmin-actions`, `larsadmin-filters`, `larsadmin-metrics-dashboards`.

### Record title and subtitle

`$title` names the attribute used in headings, breadcrumbs, relation pickers and search results
(`data_get`, so `author.name` works with `$with = ['author']`). `$subtitle` is only the second line of a
global search result. Overriding `title()` or `subtitle()` (instance methods on `$this->record`) skips
the built-in `canSee` and related-record checks: your code owns what it reveals.

### Register an entity outside app/Larsadmin

`Larsadmin::entities([\App\Admin\Post::class])` or `Larsadmin::entitiesIn(app_path('Admin'))` in the
`entities()` hook of `App\Providers\LarsadminServiceProvider` (see `larsadmin-setup`).

## API cheat sheet

`Maxcxam\Larsadmin\Entity` static properties:

| Property | Default | Meaning |
| --- | --- | --- |
| `$model` | required | Eloquent model class |
| `$title` / `$subtitle` | `'id'` / `null` | record title / search second line |
| `$search` | `['id']` | searched columns |
| `$with` | `[]` | always eager-loaded relations |
| `$label`, `$singularLabel` | `null` | translated with `__()`; derived from the class name when `null` |
| `$group` | `null` | navigation group |
| `$sort` | `[]` | default sort `['col' => 'asc'\|'desc']` |
| `$perPage`, `$perPageOptions` | `25`, `[25, 50, 100]` | page sizes |
| `$displayInNavigation` | `true` | show in the sidebar |
| `$globallySearchable`, `$globalSearchLimit` | `false`, `null` | global search |
| `$auditable` | `true` | audit log opt-out (`larsadmin-audit-log`) |

Methods: `fields(Request)` (required), `actions(Request)`, `filters(Request)`, `cards(Request)`,
static `indexQuery/detailQuery/relatableQuery(Request, Builder): Builder`, static `uriKey()` (kebab plural
of the class name, `BlogPost` gives `blog-posts`), `title()`, `subtitle()`, static `usesScout()`,
`url('show'|'edit'|'destroy')`.

## Security rules

- MUST scope rows in `indexQuery()` when users may see only some records; filters do not restrict access.
- MUST have a policy for `$model` (`viewAny`, `view`, `create`, `update`, `delete`); a registered policy
  fails closed on missing methods.
- NEVER loosen `detailQuery()` or `relatableQuery()` beyond what the user may see.
- NEVER expose a hidden or secret attribute through an overridden `title()`/`subtitle()` or `$title`.
- NEVER read tenant scope from the session if the entity has queued actions (the worker session is empty).

## Pitfalls

- A `$sort` key that is not a visible `->sortable()` field throws `InvalidArgumentException`.
- Two entities for the same model: a relation field without the third `$entity` argument gets the first
  registered one (`Larsadmin::entityForModel()`); pass the entity class explicitly.
- `$label` is a plural label; `$singularLabel` the singular one. Both go through `__()`.
- Searching Cyrillic with `LIKE` on SQLite is case-sensitive.
- Changing `uriKey()` changes every panel URL of the entity.

## Reference

- Guide: [vendor/maxcxam/larsadmin/docs/guide/en/02-entities.md](vendor/maxcxam/larsadmin/docs/guide/en/02-entities.md)
  (MCP: `read-docs page=02-entities`).
- MCP introspection: `list-entities`, `describe-entity {entity: "<uriKey or class>"}` (fields, scoping
  overrides, policy, warnings; never record data).
