# Larsadmin security checklist

Walk this list before shipping any admin feature. Each section names the guide that is the source of
truth; read it (MCP `read-docs page=NN-name`) when a rule needs detail. Area skills repeat only their own
rules; this file collects all of them.

## Panel access (guide 05, guide 01)

- [ ] `viewLarsadmin` is defined in `gate()` with a real condition (role, permission, flag).
- [ ] `LARSADMIN_ALLOW_LOCAL=false` on every server other than a personal dev machine; no public server
      runs with `APP_ENV=local`.
- [ ] If `Larsadmin::auth()` replaces the check, it denies guests and non-admins by itself.
- [ ] `larsadmin.auth_middleware` still contains `Authenticate` and `Authorize`.
- [ ] `LARSADMIN_MCP` is unset or `false` on shared hosts (the MCP server never runs in production).

## Policies and scope (guides 02, 04, 05)

- [ ] Every model behind an Entity has a policy with `viewAny`, `view`, `create`, `update`, `delete`.
- [ ] `larsadmin.allow_without_policy` is `false` in production.
- [ ] Ownership and tenant rules live in `indexQuery()`; `detailQuery()` and `relatableQuery()` are not
      looser than `indexQuery()`.
- [ ] Scope reads the user, not the session or request headers (queued actions have neither).
- [ ] `BelongsToMany` panels: `attach{Model}`/`detach{Model}` exist where `update` on the parent is too
      broad; the related Entity has `viewAny`.
- [ ] Overridden `title()`/`subtitle()` reveal nothing the viewer may not see.

## Fields (guides 03, 12)

- [ ] Field-level access uses `canSee()`/`readonly()`, not visibility in the UI alone.
- [ ] Secrets are marked `->sensitive()` (accessor-decrypted attributes, JSON columns with tokens).
- [ ] Nothing secret goes into `withMeta()`, `default()`, `displayUsing()` or `resolveUsing()` output.
- [ ] `Field::authorizedTo()` / `inAction()` only shape output; the server checks the request.

## Filters (guide 06)

- [ ] Filters are treated as UX, never as an access boundary; a user can drop or change any filter value.
- [ ] Custom `apply()` code uses the given builder only (it is wrapped in its own `where (...)` group).

## Actions (guide 07, guide 18)

- [ ] `canSee()` hides the action (404 when called); `canRun()` replaces the default `update` check per
      record; a standalone action's `canRun()` handles `null` (default needs `create`).
- [ ] `handle()` does not trust ids from the request beyond the records it receives.
- [ ] Queued actions: `canSee()`, `canRun()`, `fields()`, policies and `indexQuery()` do not depend on
      the session or headers; the queue's `retry_after` exceeds `$timeout`.
- [ ] No `File`/`Image` (or multipart) fields in action `fields()` (throws `LogicException`).
- [ ] Action fields holding secrets are `Password` or `->sensitive()` (masked in the audit log).

## Metrics and dashboards (guides 13, 14)

- [ ] Per-user numbers use an Entity source (`Post::class` from `App\Larsadmin`, `scoped()` or
      `EntitySource::make()`), which applies `viewAny` and `indexQuery()`.
- [ ] A raw model class, `Model::query()` or `DB::table()` source is used only for global figures and the
      card is hidden with `canSee()` when the number is sensitive.
- [ ] A dashboard's `canSee()` is not relied on to protect its cards' data.
- [ ] Cached metrics vary by everything the result depends on (see guide 13, "Caching").

## Files (guide 15)

- [ ] Every `File`/`Image` field restricts types with `->types([...])`; the blocked-extension list is a
      backstop only.
- [ ] Files that must not be public sit on a private disk (signed URLs, checked per request).
- [ ] Public disks: the web server adds `X-Content-Type-Options: nosniff`, `Content-Security-Policy:
      sandbox` and `Content-Disposition: attachment` for non-images under `/storage`.

## Global search (guide 16)

- [ ] Only entities meant to be searched set `$globallySearchable`; each needs `viewAny`.
- [ ] With Laravel Scout, `toSearchableArray()` contains no field hidden with `canSee`, or the entity
      turns Scout off with `usesScout()` returning `false`.

## Audit log (guide 17)

- [ ] `viewLarsadminAudit` is granted only to people who may see every record of the entities they can
      view: the "Audit log" page is not row-scoped. `allow_local` does not open it.
- [ ] Sensitive fields are masked; retention (`larsadmin.audit.retention_days`) matches privacy rules and
      `larsadmin:prune-audit` is scheduled.

## Notifications and realtime (guide 18)

- [ ] The `larsa_` channel prefix (`LARSADMIN_CHANNEL_PREFIX`) is not used for the app's own channels.
- [ ] Notification texts carry no secrets; retention suits the privacy rules.

## Extensions and custom routes (guide 19)

- [ ] Only trusted extensions are installed (their PHP and JS run with full rights).
- [ ] Every extension route that touches a record loads it with `Larsadmin::findRecord()` (scope plus
      policy) and fields with `Larsadmin::findField()`.
- [ ] Writes through custom routes go through `Larsadmin::audited()` when the audit log is on.
- [ ] With a strict CSP, `Vite::useCspNonce()` runs before panel pages render.
