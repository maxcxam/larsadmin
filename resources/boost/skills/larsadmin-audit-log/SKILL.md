---
name: larsadmin-audit-log
description: "Larsadmin audit log: enabling the audit, auditable entities, sensitive fields, the viewLarsadminAudit gate, pruning old entries, audit entries and the AuditRecorded event, and recording custom changes. Use when tracking who changed what in the admin panel of a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin audit log

## When to use

- Turn on the record of who created, updated, deleted, attached, detached or ran an action, and when.
- Decide who may read the log (`viewLarsadminAudit`), which entities are recorded and which fields are masked.
- Prune old entries, react to new entries (SIEM, alerts) or record a change made outside the panel form.

Not covered: changes made outside the panel (your controllers, jobs, tinker) are never recorded automatically.

## Quick start

1. Publish and run the migration (`larsadmin:install` already publishes it in new projects):

   ```bash
   php artisan vendor:publish --tag=larsadmin-migrations
   php artisan migrate
   ```

2. Turn the log on in `.env`: `LARSADMIN_AUDIT=true`.
3. Allow reading in `app/Providers/LarsadminServiceProvider.php` (inside the `gate()` method):

   ```php
   use Illuminate\Support\Facades\Gate;

   Gate::define('viewLarsadminAudit', fn ($user) => in_array($user->email, ['admin@example.com'], true));
   ```

4. Schedule pruning in `routes/console.php`:

   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('larsadmin:prune-audit')->daily();
   ```

From now on every Entity is recorded: the "History" panel shows on record pages and an "Audit log" page in
the menu, both only for users passing the gate.

## Recipes

### Opt an entity out

```php
use Maxcxam\Larsadmin\Entity;

class Tag extends Entity
{
    public static string $model = \App\Models\Tag::class;

    public static bool $auditable = false;
}
```

`Entity::audited()` (static) tells whether the entity is recorded right now: the log is on and `$auditable`
is `true`.

### Mask a sensitive field

`Password` fields, attributes in the model's `$hidden` (or outside a non-empty `$visible`) and encrypted or
hashed casts are masked automatically. Mark anything else yourself; it only affects the log:

```php
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;

$fields = [
    Text::make('API token', 'api_token')->sensitive(),
    Textarea::make('Internal note', 'internal_note')->sensitive(),
];
```

Always add `->sensitive()` when an accessor (not a cast) decrypts a value or a JSON column holds secrets.

### Record a change made outside the form

In an extension route or your own controller, wrap the change with `Larsadmin::audited()`. The panel snapshots
the entity's fields before and after the closure and writes an `updated` entry in the same transaction:

```php
use Illuminate\Database\Eloquent\Model;
use Maxcxam\Larsadmin\Larsadmin;

$record = Larsadmin::findRecord($request, 'posts', $id, 'update');

Larsadmin::audited($request, 'posts', $record, function (Model $record) use ($ids) {
    $record->tags()->sync($ids);
});
```

The second argument is the entity's `uriKey`. No entry is written when nothing changed, the log is off or
the entity is not audited. A field without a column is diffed only when it implements `hasAuditValue()` and
`auditValue()` (see `larsadmin-extending`).

### Forward entries to a SIEM

```php
use Illuminate\Support\Facades\Event;
use Maxcxam\Larsadmin\Events\AuditRecorded;

Event::listen(function (AuditRecorded $event) {
    foreach ($event->entries as $entry) {
        logger()->channel('audit')->info($entry->event, $entry->only('entity', 'auditable_id', 'actor_label', 'batch_id'));
    }
});
```

`AuditRecorded` fires after the commit and only when it has listeners. `entries` is a collection of
`Maxcxam\Larsadmin\Audit\AuditEntry`; entries inserted in one query have `id = null`, correlate them by
`batch_id`. A listener exception goes to `report()` and does not break the request.

### Customize the user label

```php
use Maxcxam\Larsadmin\Larsadmin;

Larsadmin::auditActorLabelUsing(fn ($user) => "{$user->name} ({$user->email})");
```

Call it in the provider's `boot()`. The label is a snapshot stored with each entry (default: `name`, else
`email`, else `#id`); `null` restores the default.

### Separate connection and tamper resistance

Set `LARSADMIN_AUDIT_CONNECTION` (key `larsadmin.audit.connection`) and change `larsadmin.audit.table` **before**
running the migration. With a connection different from the models' one, entries are written after the
commit without atomicity: a failed insert logs a `critical` message, so alert on it. For real immutability
give the application's database user only `INSERT` and `SELECT` on the log table.

### Erase a person's data (GDPR)

The `AuditEntry` model refuses updates and deletes. Use the query builder on
`config('larsadmin.audit.table')` and the `actor_type`/`actor_id` or `auditable_type`/`auditable_id`
columns, as shown in the guide section "Erasing or anonymizing a person's data".

## API cheat sheet

| Element | Meaning |
|---|---|
| `LARSADMIN_AUDIT` / `larsadmin.audit.enabled` | turns recording and reading on (default `false`; off = routes 404) |
| `larsadmin.audit.retention_days` | days kept by `larsadmin:prune-audit` (90; `null` keeps all) |
| `larsadmin.audit.store_ip`, `larsadmin.audit.store_user_agent` | store the IP / user agent (`true`) |
| `larsadmin.audit.max_value_length` | longest stored value (1000, at least 100); each diff is capped at 64 KB |
| `larsadmin.audit.per_page`, `larsadmin.audit.throttle` | entries per page (25), requests per minute (60) |
| `Entity::$auditable` | `false` opts the entity out |
| `->sensitive()` | record only that the field changed |
| `viewLarsadminAudit` gate | who reads the log; undefined = nobody (fail closed); `Gate::before` is honored |
| `php artisan larsadmin:prune-audit [--days=N]` | delete entries older than N days, chunks of 1000 |
| `Larsadmin::audited($request, $entity, $record, $change)` | record a change outside the form |
| `Larsadmin::auditActorLabelUsing(?Closure)` | label snapshot of the user |
| `Maxcxam\Larsadmin\Events\AuditRecorded` | event with `entries` after the commit |
| `Maxcxam\Larsadmin\Audit\AuditEntry` | read-only Eloquent model of an entry |

Recorded events: `created`, `updated`, `deleted`, `action` (one entry per record, one shared `batch_id`),
`attached`, `detached`. Background actions are recorded too, with the user, IP and user agent from the moment
they were started.

## Security rules

- MUST define `viewLarsadminAudit` explicitly and grant it narrowly. The "Audit log" page is **not
  row-scoped**: a holder sees every entry of every entity they have `viewAny` on, including records outside
  their `indexQuery()`. In multi-tenant apps grant it only to people who may see all tenants.
- MUST mark fields holding secrets that the automatic rules cannot recognize with `->sensitive()`.
- MUST run the migration before setting `LARSADMIN_AUDIT=true`: with the table missing every panel save
  fails and is rolled back.
- MUST schedule `larsadmin:prune-audit` and pick `retention_days` per your privacy rules; turn off
  `store_ip`/`store_user_agent` when you do not need them.
- NEVER rely on `allow_local` for the log: the gate is required even in `APP_ENV=local`.
- NEVER change or delete entries through `AuditEntry`; it throws `LogicException` by design.

## Pitfalls

- `fields()` that return different fields per user record only the fields returned for the acting user.
  Add a field unconditionally and hide it with `canSee()` to always record it.
- Relation panels (`HasMany`, `BelongsToMany`) are not diffed as fields; attaches are separate events.
  Pivot attributes are not recorded.
- An action's diff covers only the target entity's fields, not other tables `handle()` touches.
- Changing `audit.table` after migrating breaks recording; set it first.
- With a separate `audit.connection`, a failed insert does not roll the change back.

## Reference

- Guide: [vendor/maxcxam/larsadmin/docs/guide/en/17-audit-log.md](vendor/maxcxam/larsadmin/docs/guide/en/17-audit-log.md)
  (configuration, HTTP endpoints, GDPR queries, performance).
- Authorization overview: `larsadmin-authorization-security`; audited extension fields: `larsadmin-extending`.
- MCP (when connected): `read-docs page=17-audit-log`, `search-docs query="sensitive"`.
