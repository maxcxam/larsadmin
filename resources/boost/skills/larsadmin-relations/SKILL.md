---
name: larsadmin-relations
description: "Larsadmin relation fields: BelongsTo, HasMany and BelongsToMany, relatable search and scoping of the records a user may pick, attaching and detaching with the Attached and Detached events. Use when showing or editing Eloquent relationships in the admin panel of a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin relations

## When to use

- Picking a parent record on a form (`belongsTo`).
- Listing child records on a detail page (`hasMany`).
- Attaching and detaching many-to-many records (`belongsToMany`), with per-record permissions.
- Narrowing which records a user may pick, or reacting to attach/detach.

## Quick start

Both sides need a registered Entity. The attribute is the **relation method name** on the model; the third
argument is the related Entity class.

```php
namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\BelongsToMany;
use Maxcxam\Larsadmin\Fields\HasMany;
use Maxcxam\Larsadmin\Fields\ID;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            BelongsTo::make('Author', 'author', User::class)->searchable()->sortable(),
            BelongsTo::make('Category', 'category', Category::class)->nullable(),
            BelongsToMany::make('Tags', 'tags', Tag::class)->searchable()->perPage(10),
            HasMany::make('Comments', 'comments', Comment::class)->perPage(10),
        ];
    }
}
```

`User`, `Category`, `Tag` and `Comment` here are Entity classes in `App\Larsadmin`, not models. Without
the third argument the Entity is looked up by the relation's model; none found, or a model method that
returns another relation type, throws `LogicException`. Check the wiring with MCP
`describe-entity {entity: "posts"}`.

## Recipes

### Narrow the options of a picker

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\BelongsTo;

return [
    BelongsTo::make('Author', 'author', \App\Larsadmin\User::class)
        ->relatableQueryUsing(function (Request $request, Builder $query, ?Model $record) {
            $query->where('is_active', true);
        })
        ->relatableLimit(50),
];
```

Options and validation always pass the related Entity's `relatableQuery()` (its `indexQuery()` by default)
and its `viewAny`; `relatableQueryUsing()` narrows further. An already saved value that fell outside the
options stays valid. To narrow for every picker of an entity, override `relatableQuery()` on it
(`larsadmin-entities`).

### "Create child" from a HasMany panel

The panel's create button opens the child form with `viaEntity`, `viaId`, `viaField`, prefilling and locking
the inverse `BelongsTo`. The child Entity needs that `BelongsTo` field (on the create form, not
`readonly`), for example `Category` has `HasMany::make('Posts', 'posts', Post::class)` and `Post` has
`BelongsTo::make('Category', 'category', Category::class)`. The `via*` parameters are validated and grant
nothing by themselves.

### Attach permissions (BelongsToMany)

Checked on the **parent** model's policy, with the related model's class basename:

```php
namespace App\Policies;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;

class PostPolicy
{
    public function attachAnyTag(User $user, Post $post): bool
    {
        return $user->can('update', $post);
    }

    public function attachTag(User $user, Post $post, Tag $tag): bool
    {
        return ! $tag->archived;
    }

    public function detachTag(User $user, Post $post, Tag $tag): bool
    {
        return $user->is_admin;
    }
}
```

A missing `attachAny{Model}`/`attach{Model}`/`detach{Model}` method falls back to `update` on the parent.
No parent policy: `larsadmin.allow_without_policy` decides. The related Entity must also allow `viewAny`
(else 403). Attaching an already attached record fails validation.

### React to attach and detach

```php
use Illuminate\Support\Facades\Event;
use Maxcxam\Larsadmin\Events\Attached;
use Maxcxam\Larsadmin\Events\Detached;

Event::listen(Attached::class, function (Attached $event) {
    logger()->info("{$event->relation} attached", [
        'parent' => $event->parent->getKey(),
        'related' => $event->related->getKey(),
        'user' => $event->user?->getAuthIdentifier(),
    ]);
});

Event::listen(Detached::class, fn (Detached $event) => cache()->forget("post:{$event->parent->getKey()}"));
```

Both are dispatched after a successful change, inside the same transaction; a listener that throws rolls
the change back.

## API cheat sheet

| Field | Model relation | Shown | Methods |
| --- | --- | --- | --- |
| `Maxcxam\Larsadmin\Fields\BelongsTo` | `belongsTo()` | index, detail, forms | `searchable()`, `relatableQueryUsing()`, `relatableLimit(1..100)` (25), `nullable()`, `sortable()` (by the related `$title`) |
| `Maxcxam\Larsadmin\Fields\HasMany` | `hasMany()` | detail panel | `perPage(1..100)` (10) |
| `Maxcxam\Larsadmin\Fields\BelongsToMany` | `belongsToMany()` | detail panel with attach/detach | `perPage()`, `searchable()`, `relatableQueryUsing()`, `relatableLimit()` |

All take `make(string $label, ?string $relation = null, ?string $entity = null)`. `BelongsTo` writes the
relation's foreign key and is required unless `nullable()`.

Events `Maxcxam\Larsadmin\Events\Attached` and `Maxcxam\Larsadmin\Events\Detached`: readonly `parent`,
`relation` (method name), `related`, `user` (nullable).

Endpoints: `POST {path}/entities/{entity}/{id}/attach/{field}` (field `related`),
`DELETE {path}/entities/{entity}/{id}/attach/{field}/{relatedId}`.

## Security rules

- MUST scope the related Entity's `indexQuery()`/`relatableQuery()`; pickers and validation rely on it, not
  on the UI.
- MUST define `attach{Model}`/`detach{Model}` when attaching needs stricter rules than `update` on the
  parent.
- NEVER use `relatableQueryUsing()` as the only tenant boundary; it narrows one field, while the Entity
  scope covers search, panels and validation everywhere.
- NEVER trust `viaEntity`/`viaId`/`viaField` in your own code; read the prefilled `BelongsTo` value.
- MUST keep `Attached`/`Detached` listeners free of side effects that cannot roll back (they run in the
  transaction).

## Pitfalls

- Passing the model class instead of the Entity class as the third argument.
- The attribute is the relation method (`author`), not the foreign key (`author_id`).
- `HasMany`/`BelongsToMany` inside a `Panel` or `Tabs` throw `LogicException`; keep them at the top level.
- `HasMany` create prefill needs the inverse `BelongsTo` on the child entity.
- Related records outside the related `detailQuery()` show as "hidden", with no title or link.
- `$search = ['author.email']` only finds rows the related Entity's `indexQuery()` allows.

## Reference

- Guide: [vendor/maxcxam/larsadmin/docs/guide/en/04-relations.md](vendor/maxcxam/larsadmin/docs/guide/en/04-relations.md)
  (MCP: `read-docs page=04-relations`).
- Policies and fallback rules: `larsadmin-authorization-security`.
