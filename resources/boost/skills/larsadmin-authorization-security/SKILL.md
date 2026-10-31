---
name: larsadmin-authorization-security
description: "Larsadmin authorization and security: the viewLarsadmin gate and the panel auth callback, model policies that fail closed, row scoping in indexQuery, detailQuery and relatableQuery, and the security checklist for fields, filters, actions, metrics, files, search and the audit log. Use when controlling who can open the panel or view, create, update, delete or act on records, or when reviewing an admin feature for security, in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin authorization and security

## When to use

- Deciding who may open the panel at all.
- Deciding what a user may do with a model (policies) and which rows they see (scoping).
- Reviewing any Larsadmin feature before it ships: this skill holds the full checklist
  ([references/checklist.md](references/checklist.md)); the area skills only repeat their own rules.

## Quick start

Access is checked on two levels:

1. **Panel access**: every route behind the login runs `Authenticate` and `Authorize` (from
   `larsadmin.auth_middleware`). `Authorize` calls `Larsadmin::check($request)`, which runs the callback set
   with `Larsadmin::auth()`. The published `App\Providers\LarsadminServiceProvider` sets it to
   "`allow_local` and `APP_ENV=local`, or the `viewLarsadmin` gate". The base gate denies everyone.
2. **Data access**: the model's policy (`viewAny`, `view`, `create`, `update`, `delete`) plus the Entity's
   `indexQuery()`/`detailQuery()`/`relatableQuery()` scope.

Open the gate:

```php
namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;

class LarsadminServiceProvider extends LarsadminApplicationServiceProvider
{
    protected function gate(): void
    {
        Gate::define('viewLarsadmin', fn ($user) => $user->hasRole('admin'));
        Gate::define('viewLarsadminAudit', fn ($user) => $user->hasRole('auditor'));
    }
}
```

Then set `LARSADMIN_ALLOW_LOCAL=false` everywhere except a personal dev machine.

## Recipes

### Replace the access check entirely

```php
namespace App\Providers;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminApplicationServiceProvider;

class LarsadminServiceProvider extends LarsadminApplicationServiceProvider
{
    protected function authorization(): void
    {
        Larsadmin::auth(fn (Request $request) => $request->user()?->isAdmin() === true);
    }
}
```

The closure replaces the whole check: `allow_local` and `viewLarsadmin` no longer apply. With a separate
admin guard set `LARSADMIN_GUARD`; the gate and `$request->user()` then use that guard.

### A policy per managed model

```php
namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    public function viewAny(User $user): bool { return true; }
    public function view(User $user, Tag $tag): bool { return true; }
    public function create(User $user): bool { return $user->is_editor; }
    public function update(User $user, Tag $tag): bool { return $user->is_editor; }
    public function delete(User $user, Tag $tag): bool { return false; }
}
```

| Panel operation | Ability |
| --- | --- |
| navigation, list, any access to the Entity | `viewAny` |
| create button, page, store | `create` |
| show a record | `view` |
| edit, update; default for actions on a record | `update` |
| delete | `delete` |
| attach / detach (parent policy) | `attachAny{Model}`, `attach{Model}`, `detach{Model}`, else `update` |

With a registered policy a **missing method means denied** (`before()` hooks work as usual). Models without
a policy follow `larsadmin.allow_without_policy` (`true` by default): set it to `false` in production.

### How the policy is found

Larsadmin asks Laravel's gate: `Gate::getPolicyFor($model)`. Any policy the gate knows counts. That means a
policy you register with `Gate::policy()`, or one Laravel finds by its naming convention
(`App\Models\Tag` -> `App\Policies\TagPolicy`). If the gate returns `null`, the entity has no policy.

```php
// app/Providers/AppServiceProvider.php, boot(): needed when the policy does not follow the convention
\Illuminate\Support\Facades\Gate::policy(\App\Models\Tag::class, \App\Policies\Admin\TagPolicy::class);
```

Recommendation: register policies explicitly with `Gate::policy()` when the class lives outside
`App\Policies` or the model outside `App\Models`. Either way, confirm the result with MCP
`describe-entity {entity: "tags"}`. Its `policy.class` must not be `null`, and `warnings` must not say
"No policy is registered".

### Switch `allow_without_policy` off safely

The option has no environment variable. Set it in the published `config/larsadmin.php`.

1. MCP `list-entities` gives every registered entity. Run `describe-entity` on each one and check its
   `policy` field (`list-entities` itself does not show policies).
2. Write or register a policy for each model without one (see above), with all five abilities.
3. Set `'allow_without_policy' => false` and run `php artisan config:clear` if the config is cached.
4. Any entity still without a policy is then **denied everything**. It disappears from the navigation,
   and its pages and API answer 403. `BelongsToMany` attach/detach on such a parent is denied as well.
   `describe-entity` warns "every ability is denied (fail closed)".

### Scope rows

A policy says "allowed at all"; the scope says "which rows". Put tenant and ownership rules in
`indexQuery()`; `detailQuery()` and `relatableQuery()` inherit it. Rows outside `detailQuery()` answer
404; filters, search, metrics with an Entity source and relation panels all start from these queries.

```php
namespace App\Larsadmin;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;

abstract class TenantEntity extends Entity
{
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('team_id', $request->user()->team_id);
    }
}
```

Read the tenant from the user, not the session: queued actions run with an empty session and no headers.

### Review a feature

Run MCP `describe-entity {entity: "..."}`: it reports the policy, scoping overrides and warnings (for
example a model without a policy). Then walk [references/checklist.md](references/checklist.md).

## API cheat sheet

| Item | Meaning |
| --- | --- |
| `viewLarsadmin` gate | panel access (defined in `gate()` of the app provider) |
| `viewLarsadminAudit` gate | audit log access; not defined by the package, so closed until you define it |
| `Larsadmin::auth(?Closure $callback)` | replace the access check: `fn (Request $request): bool` |
| `Larsadmin::check($request)` | run the current check |
| `larsadmin.allow_local` / `LARSADMIN_ALLOW_LOCAL` | skip the gate when `APP_ENV=local` |
| `larsadmin.allow_without_policy` | decision for models without a policy |
| `larsadmin.guard` / `LARSADMIN_GUARD` | panel guard |
| `Entity::indexQuery/detailQuery/relatableQuery` | row scope |
| Field `canSee()`, `readonly()` | field-level access, enforced on the server |
| Action `canSee()`, `canRun()` | action access; default per-record check is `update` |

Login is rate limited (5 per minute per email and IP, 30 per minute per IP).

## Security rules

- MUST define `viewLarsadmin` with a real condition; NEVER return `true` for every user.
- MUST set `LARSADMIN_ALLOW_LOCAL=false` on shared, staging or public servers; NEVER run them with
  `APP_ENV=local`.
- MUST write a policy for every model an Entity manages and set `allow_without_policy` to `false` in
  production.
- MUST scope multi-tenant or ownership data in `indexQuery()`. NEVER rely on filters, hidden columns or
  the UI for access control.
- MUST authorize on the server in every custom route, action and card. NEVER trust ids or flags sent by
  the browser.
- NEVER read scope from the session or request headers in code that queued actions reach.
- MUST keep fields hidden with `canSee` out of Scout's `toSearchableArray()`.
- MUST grant `viewLarsadminAudit` only to people allowed to see every record of the entities they can view
  (the audit page is not row-scoped).
- NEVER use metrics over a raw model class or builder for per-user numbers; use an Entity source.

## Pitfalls

- `Larsadmin::auth()` in `authorization()` silently drops the gate and `allow_local`.
- The default `allow_local` is `true`: a staging server with `APP_ENV=local` lets every signed-in user in.
- A policy registered without `viewAny` hides the whole entity (fail closed), which looks like a bug.
- `detailQuery()` loosened beyond `indexQuery()` exposes rows by direct URL.
- Without the application provider only `allow_local` in `local` works; other environments get 403.

## Reference

- Guide: [vendor/maxcxam/larsadmin/docs/guide/en/05-authorization.md](vendor/maxcxam/larsadmin/docs/guide/en/05-authorization.md)
  (MCP: `read-docs page=05-authorization`).
- Rules from other areas: filters (06), actions (07), metrics (13), files (15), search (16), audit log (17),
  collected in [references/checklist.md](references/checklist.md).
