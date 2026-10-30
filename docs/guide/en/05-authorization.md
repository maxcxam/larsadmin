# 05. Authorization
🇺🇦 [Українська](../05-authorization.md)

Access to the panel and to data is checked on two levels:

1. **Who can enter the panel**: the `viewLarsadmin` gate.
2. **What the user can do with models**: Laravel policies, plus scoping of the visible rows.

## Panel access

Every route behind the login screen goes through the `Authenticate` middleware (the user is logged in on
the panel's guard) and the `Authorize` middleware (runs the check below). Otherwise the response is 403
(for JSON requests without `X-Inertia` it is a JSON `{"message": "..."}`).

401 (not logged in) and 403 responses to JSON requests contain a translated message
(`larsadmin::messages.unauthenticated` / `larsadmin::messages.forbidden`) in the panel language.

`Authorize` calls `Larsadmin::check($request)`. The application provider
(`App\Providers\LarsadminServiceProvider`) sets the check up like this:

```php
(config('larsadmin.allow_local', true) && app()->environment('local'))
    || Gate::forUser($request->user(Larsadmin::guard()))->check('viewLarsadmin')
```

### The `viewLarsadmin` gate

The base provider defines a gate that is **closed to everyone**. Open it by overriding the `gate()` method
in `app/Providers/LarsadminServiceProvider.php`:

```php
use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;

class LarsadminServiceProvider extends LarsadminApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewLarsadmin', function ($user) {
            return in_array($user->email, [
                'admin@example.com',
            ]);
        });
    }
}
```

You can put any logic there: a role, a permission, an `is_admin` column.

### `allow_local`

With `APP_ENV=local` and `larsadmin.allow_local = true` (the default), **the gate is not checked**: every
authenticated user gets into the panel. This is convenient for development (the demo application the
package is developed against works this way), but dangerous on shared or public servers.

```dotenv
LARSADMIN_ALLOW_LOCAL=false   # always require the gate
```

### Custom access check

Instead of the gate, you can register any closure by overriding the `authorization()` method in
`app/Providers/LarsadminServiceProvider.php`:

```php
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;

protected function authorization(): void
{
    Larsadmin::auth(fn (Request $request) => $request->user()?->isAdmin() === true);
}
```

Such a closure fully replaces the base provider's check (`allow_local` and the gate no longer apply).
Without the application provider, only `allow_local` in `local` applies; in other environments there is
no access.

### A separate guard

`LARSADMIN_GUARD` (or `guard` in the config) sets the panel's authentication guard. The same guard is
used for the gate and for `request->user()`.

## Model policies

An Entity checks the policy of its model (`$model`) for these abilities:

| Action | Ability | Argument |
| --- | --- | --- |
| Navigation, list, access to the Entity | `viewAny` | the model class |
| Create button and page, `store` | `create` | the model class |
| Viewing a record | `view` | the record |
| Editing, `update` | `update` | the record |
| Deleting | `delete` | the record |

Example `app/Policies/TagPolicy.php`: tags can be viewed and changed, but not deleted.

```php
class TagPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Tag $tag): bool { return true; }
    public function create(User $user): bool { return true; }
    public function update(User $user, Tag $tag): bool { return true; }

    /** Tags may never be deleted from the panel. */
    public function delete(User $user, Tag $tag): bool { return false; }
}
```

The UI shows buttons based on the same checks, but the server always checks again.

### Policies fail closed

If a **policy is registered** for the model, the decision is fully delegated to the Laravel gate:

- a missing policy method means **denied**;
- the policy's `before()` hooks (and `Gate::before`) work as usual.

This also applies to the `attach`/`detach` permissions on relations (see [Relations](04-relations.md)),
except that a missing method there falls back to the `update` ability.

### Models without a policy: `allow_without_policy`

When there is no policy, `larsadmin.allow_without_policy` applies:

| Value | Result |
| --- | --- |
| `true` (default) | everything is allowed |
| `false` | everything is denied |

For production, use `false` and explicit policies for every managed model.

## Scoping rows

A policy answers "is this allowed at all", not "which rows exactly". Rows are scoped by the Entity's static
hooks ([Entities](02-entities.md)):

```php
class OwnPost extends Entity
{
    public static string $model = \App\Models\Post::class;

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('user_id', $request->user()->getKey());
    }
}
```

`indexQuery` applies to the list and to the relation panels. `detailQuery` (equal to `indexQuery` by
default) applies to show, edit, update, delete and actions on selected records. A record outside the
scope returns 404. `relatableQuery` decides what can be selected in relations, and also applies during
validation.

Actions on records additionally check `update` for each record ([Actions](07-actions.md)).

## Checklist

- Restrict the `viewLarsadmin` gate to real conditions; turn `allow_local` off outside development.
- Create policies for the managed models and consider `allow_without_policy => false`.
- Add `indexQuery` if a user may see only some of the rows.
- Do not run public servers with `APP_ENV=local`.
