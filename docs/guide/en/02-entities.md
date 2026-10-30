# 02. Entities
🇺🇦 [Українська](../02-entities.md)

An Entity describes how a model appears in the panel: its fields, what to search by, and which records
the user can see. The class extends `Maxcxam\Larsadmin\Entity`.

If you know Nova, an Entity corresponds to a Resource.

## Generator

```bash
php artisan larsadmin:entity Post --model=Post
```

- The class is created as `App\Larsadmin\Post`.
- Without `--model`, the model is taken from the class name (`Post` gives `App\Models\Post`).
- `--force` overwrites an existing class.

Classes in `app/Larsadmin` (including subdirectories) are registered automatically in the `boot()`
method of the `App\Providers\LarsadminServiceProvider` provider. To register them manually:

```php
use Maxcxam\Larsadmin\Larsadmin;

Larsadmin::entities([\App\Larsadmin\Post::class]);
Larsadmin::entitiesIn(app_path('Domain/Admin')); // a directory inside app/
```

## A minimal Entity

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
    public static ?string $group = 'Контент';
    public static ?string $label = 'Категорії';
    public static ?string $singularLabel = 'Категорія';

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Назва', 'name')->sortable()->rules('required', 'max:255'),
        ];
    }
}
```

`fields()` is the only required method.

## Static properties

| Property | Type, default | Description |
| --- | --- | --- |
| `$model` | `class-string<Model>`, required | The Eloquent model |
| `$title` | `string`, `'id'` | Attribute used as the record's title (read with `data_get`) |
| `$search` | `array`, `['id']` | What to search (see below). An empty array disables search |
| `$with` | `array`, `[]` | Relations always eager-loaded on index and detail |
| `$label` | `?string`, `null` | Plural label for navigation and headings; translated with `__()`. When `null`, the plural of the class name is used |
| `$singularLabel` | `?string`, `null` | Singular label; translated with `__()`. When `null`, it is derived from the class name |
| `$group` | `?string`, `null` | Navigation group |
| `$sort` | `array`, `[]` | Default sort order when the request has no valid `sort`: `['published_at' => 'desc']` |
| `$perPage` | `int`, `25` | Default page size |
| `$perPageOptions` | `array`, `[25, 50, 100]` | Allowed page sizes (`?perPage=`). Any other value is replaced with `$perPage` |
| `$displayInNavigation` | `bool`, `true` | Show the Entity in the side navigation |
| `$globallySearchable` | `bool`, `false` | Take part in the global search (Ctrl/⌘+K), see [Global search](16-search.md) |
| `$globalSearchLimit` | `?int`, `null` | Results in the global search; `null` uses `larsadmin.search.limit` (5), clamped to 1..20 |
| `$subtitle` | `?string`, `null` | Attribute of the global search result's second line (a column or a one-level `relation.attribute`) |

Relations that fields need (for example, `BelongsTo`) are loaded automatically. You only need `$with`
for extra relations, say, when `$title` points to `author.name`.

### Default sort order

```php
public static array $sort = ['published_at' => 'desc'];
```

Keys are field attributes, values are `asc` or `desc`. Each attribute must belong to a `->sortable()`
field that the user can see (`canSee`); otherwise an `InvalidArgumentException` is thrown. A `?sort=`
in the request takes precedence; the record key is always the final tiebreaker. The UI receives the
value as `query.defaultSort` and shows it as the current sort.

### URI key

`Entity::uriKey()` builds the URL segment: the plural of the class name in kebab-case (`BlogPost` gives
`blog-posts`, URL `/admin/entities/blog-posts`). Override the static method to change the key.

### Search

Entries of `$search`:

| Entry | Behaviour |
| --- | --- |
| `'title'` | `LIKE` on the column |
| `'id'` (the model's key name) | equality; for an integer key, only a numeric term is used |
| `'author.name'` | `relation.column`: one level deep, via `orWhereHas` |

The term is trimmed and cut to 100 characters.

On SQLite, `LIKE` ignores case for Latin letters only, so "огляд" does not find "Огляд". MySQL and
PostgreSQL collations handle Cyrillic case.

For `relation.column`, the search only covers records that the related Entity's `indexQuery` lets
through (when the relation matches a registered Entity), so hidden related rows are not revealed.

Columns of fields hidden from the user with `canSee` are not searched.

The global search uses the same columns when the Entity enables `$globallySearchable` and the model does
not use Laravel Scout. See [Global search](16-search.md).

### Record URLs

A serialized record contains `urls` (`show`, `edit`, `destroy`), built on the server. A value is
`null` when the action is not allowed or the record key is unsafe for a path. The `$entity->url('show')`
method returns a relative URL or `null`.

## Scoping rows: `indexQuery`, `detailQuery`, `relatableQuery`

Three static hooks decide which rows the user sees.

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

public static function indexQuery(Request $request, Builder $query): Builder
{
    return $query->where('status', 'live');
}
```

| Method | Used for | Default |
| --- | --- | --- |
| `indexQuery($request, $query)` | the list (and the `HasMany`/`BelongsToMany` panels) | returns `$query` unchanged |
| `detailQuery($request, $query)` | a single record: show, edit, update, delete, actions on selected records | calls `indexQuery` |
| `relatableQuery($request, $query)` | records that can be selected in `BelongsTo`/`BelongsToMany` and that validation accepts | calls `indexQuery` |

`indexQuery` should match the rows the user is allowed to see; `detailQuery` and `relatableQuery`
inherit it. Override them only to loosen or tighten the restriction. A record outside `detailQuery`
returns 404.

## Actions and filters

Optional instance methods (both return an empty array by default):

```php
public function actions(Request $request): array { return [new MarkLive]; }
public function filters(Request $request): array { return [SelectFilter::make('Статус', 'status')->withOptions([...])]; }
```

See [Filters](06-filters.md) and [Actions](07-actions.md).

## Cards above the list

`cards()` returns the metrics shown above the Entity's list table (`[]` by default):

```php
public function cards(Request $request): array
{
    return [NewPosts::make(), PostsByStatus::make()];
}
```

See [Dashboards](14-dashboards.md#cards-above-an-entity-list).

## Field blocks

`fields()` can return not only fields but also `Panel` and `Tabs` blocks. See [Field blocks](12-layout.md).

## Record title

`$entity->title()` returns `(string) data_get($record, static::$title)`. This is the record's label in
headings, breadcrumbs, relation pickers and global search results.

`$entity->subtitle()` returns the `$subtitle` value (or `null`) and is only used as the second line of a
global search result. For `$title`/`$subtitle` the search checks `canSee` and access to the related
record; an overridden `title()`/`subtitle()` is used as is, so your code owns its visibility. See
[Global search](16-search.md#visibility-of-the-title-and-subtitle).

## Authorization

An Entity checks the model policy: `viewAny`, `create`, `view`, `update`, `delete`. Details in
[Authorization](05-authorization.md).

Next: [Fields](03-fields.md).
