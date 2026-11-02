# 04. Relations
🇺🇦 [Українська](../04-relations.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/en/docs/relations)

Three fields work with Eloquent relations:

| Field | Relation on the model | Where it is shown |
| --- | --- | --- |
| `BelongsTo` | `belongsTo()` | in the list, on the detail page and on forms (record picker) |
| `HasMany` | `hasMany()` | a panel on the detail page |
| `BelongsToMany` | `belongsToMany()` | a panel on the detail page with attach and detach |

All three accept `make(string $name, ?string $attribute = null, ?string $entity = null)`.
The attribute is the **name of the relation method** on the model (`author`, `posts`, `tags`). The third
argument is the class of the related Entity. When omitted, the Entity is looked up among the registered
ones by the relation's model; if none is found, a `LogicException` is thrown. The model method must return
the matching relation type, otherwise a `LogicException` is thrown as well.

```php
BelongsTo::make('Автор', 'author', User::class)->searchable()->sortable()->nullable(),
HasMany::make('Дописи', 'posts', Post::class)->perPage(10),
BelongsToMany::make('Теги', 'tags', Tag::class)->searchable()->perPage(10),
```

## BelongsTo

Picks a related record on the form. The relation's foreign key (`$relation->getForeignKeyName()`) is
written to the database.

| Method | Description |
| --- | --- |
| `searchable(bool = true)` | server-side search among the options |
| `relatableQueryUsing(callable)` | narrows the allowed options |
| `relatableLimit(int)` | number of options per picker page, 1–100, 25 by default |
| `nullable()` | the relation is optional |
| `sortable()` | sorts by the related Entity's `$title` |

Without `nullable()` the field is required (the `required` rule).

### Which records can be selected

Options and validation go through the related Entity's `relatableQuery` (its `indexQuery` by default) and
its `viewAny` ability. To narrow them further at the field level:

```php
BelongsTo::make('Автор', 'author', User::class)
    ->relatableQueryUsing(function (Request $request, Builder $query, ?Model $record) {
        return $query->where('is_active', true); // returning the Builder is optional
    });
```

If an already saved record has fallen outside the allowed options, the unchanged value stays valid: the
form can still be saved.

### Hidden related record

If the related record exists but falls outside the related Entity's `detailQuery` for the current request,
the panel shows neither its title nor a link, only a note that the record is hidden. The check runs as one
query per page, not one per row.

A link to the related record appears only if the user may view it (`view`).

### The `via*` parameters

The "Create" button in a `HasMany` panel opens the related Entity's create form with the `viaEntity`,
`viaId` and `viaField` parameters. The matching `BelongsTo` is then prefilled with the parent record and
locked, and after saving the user returns to the parent's page. The parameters are validated: `viaField`
must match the field's attribute, `viaEntity` must be the related Entity, and the `viaId` record must be an
allowed option (`relatableQuery` and `viewAny`). Invalid parameters are ignored and grant nothing on their own.

## HasMany

A panel listing the child records on the detail page. The list uses the related Entity's columns, sorting,
search, filters and its `indexQuery`.

| Method | Description |
| --- | --- |
| `perPage(int)` | panel page size, 1–100, 10 by default |

The create button is shown when the related Entity allows `create`. For the panel to prefill the parent,
the related Entity needs a `BelongsTo` field for the inverse relation (available on the create form and not
`readonly`). For example, `Category` has `HasMany::make('Дописи', 'posts', Post::class)`, and `Post` has
`BelongsTo::make('Категорія', 'category', Category::class)`.

The panel is not shown on index or forms, and it is neither filled nor validated.

## BelongsToMany

A panel of attached records with attach and detach (pivot). It has the same `perPage()`, `searchable()`,
`relatableQueryUsing()` and `relatableLimit()`.

- Attach: `POST {path}/entities/{entity}/{id}/attach/{field}` with the `related` field.
  It uses `syncWithoutDetaching`; attaching the same record again fails validation with the
  "This record is already attached." error.
- Detach: `DELETE {path}/entities/{entity}/{id}/attach/{field}/{relatedId}`.
- The picker options exclude records that are already attached.

### Attach permissions

Permissions are checked against the **parent model's** policy, using ability names that contain the
related model's name in `StudlyCase` (by `class_basename`):

| Ability | Arguments | What it checks |
| --- | --- | --- |
| `attachAny{Model}` | `$user, $parent` | whether to show the button and open the picker |
| `attach{Model}` | `$user, $parent, $related` | whether a specific record can be attached |
| `detach{Model}` | `$user, $parent, $related` | whether a specific record can be detached |

Example policy `app/Policies/PostPolicy.php` (related model `Tag`):

```php
public function attachAnyTag(User $user, Post $post): bool { return true; }

public function attachTag(User $user, Post $post, Tag $tag): bool { return true; }

public function detachTag(User $user, Post $post, Tag $tag): bool { return true; }
```

Fallback rules:

- If the `attachAnyTag` method (or `attachTag` / `detachTag`) is **not defined** in the policy, the
  parent record's `update` ability is checked.
- If the parent model **has no policy**, `larsadmin.allow_without_policy` decides.
- In addition, the related Entity must allow `viewAny`; otherwise the response is 403.

### Events

After a successful attach or detach (in the same transaction), these events are dispatched:

| Event | Properties |
| --- | --- |
| `Maxcxam\Larsadmin\Events\Attached` | `parent`, `relation`, `related`, `user` |
| `Maxcxam\Larsadmin\Events\Detached` | `parent`, `relation`, `related`, `user` |

```php
Event::listen(Attached::class, fn (Attached $e) => logger()->info("{$e->relation} attached", [
    'parent' => $e->parent->getKey(),
    'related' => $e->related->getKey(),
]));
```

## Relation search and record URLs

- Search columns such as `'author.email'` (in the Entity's `$search`) only search records that the
  **related** Entity's `indexQuery` lets through, when the relation matches a registered Entity. Hidden
  rows cannot be guessed by searching.
- Every serialized record has server-built `urls`: `show`, `edit`, `destroy` (rows of a `BelongsToMany`
  panel also have `detach`). A `null` value means the action is unavailable: the user lacks the
  permission, or the record key contains characters unsafe for a path (`/`, `\`, `.`, `..`, control
  characters). The client only uses these URLs.
- After saving with the `via*` parameters, the user returns to the parent's page. If the parent can be
  selected but not viewed, the redirect goes to the record's own page or to the list.

## Summary: what the Entity controls

| Question | Where it is configured |
| --- | --- |
| Which records are visible in the panel | `indexQuery` / `detailQuery` of the related Entity |
| Which records can be selected | `relatableQuery` of the related Entity and the field's `relatableQueryUsing()` |
| Who can attach/detach | the parent model's policy |

Next: [Authorization](05-authorization.md).
