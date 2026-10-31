# 17. Audit log
🇺🇦 [Українська](../17-audit-log.md)

The audit log records who changed what through the panel, and when: creating, updating and deleting
records, running actions, attaching and detaching in `BelongsToMany`. Each change stores the field values
"before → after". The entry is written in the same database transaction as the change.

The log is shown in the "History" panel at the bottom of a record's page and on the "Audit log" page in
the side menu. Both are available only to users the application explicitly allows through the
`viewLarsadminAudit` gate.

The log is **off by default**. It needs no extra Composer or npm dependencies.

## Quick start

1. Publish and run the migration (`larsadmin:install` already publishes it in new projects):

   ```bash
   php artisan vendor:publish --tag=larsadmin-migrations
   php artisan migrate
   ```

2. Turn the log on in `.env`:

   ```dotenv
   LARSADMIN_AUDIT=true
   ```

3. Allow viewing in `app/Providers/LarsadminServiceProvider.php`:

   ```php
   protected function gate(): void
   {
       Gate::define('viewLarsadmin', fn ($user) => in_array($user->email, ['admin@example.com']));

       Gate::define('viewLarsadminAudit', fn ($user) => in_array($user->email, ['admin@example.com']));
   }
   ```

4. Schedule the pruning of old entries in `routes/console.php`:

   ```php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('larsadmin:prune-audit')->daily();
   ```

From now on every Entity is logged. An Entity that should not be audited opts out with
[`$auditable = false`](#opting-an-entity-out).

> **Warning.** When the log is on but the table is missing, every save in the panel fails with
> "The audit log table [...] does not exist" and the change is rolled back. This way the panel never
> makes a change without an entry. Run the migration or set `LARSADMIN_AUDIT=false` again.

## What is recorded

| Event | When | What is stored |
| --- | --- | --- |
| `created` | a record is created through the form | the non-empty field values |
| `updated` | the edit form is saved | only the changed fields, "before → after" |
| `deleted` | a record is deleted (the delete button) | the field values at deletion; a soft delete is marked "Soft deleted" |
| `action` | an action runs | one entry per handled record with its field diff; one entry without a record for a `standalone` action |
| `attached`, `detached` | attach or detach in a `BelongsToMany` panel | the panel field, the related Entity and the related record's key; the entry belongs to the parent record |

Each entry also stores:

- **the user**: the model type and key, the guard and a snapshot of the label at the time of the change
  (see [The user label](#the-user-label));
- **the IP address and the user agent** (each can be switched off: `store_ip`, `store_user_agent`);
- **`batch_id`**: an id shared by the entries of one operation, such as all the entries of one action run.

Saving a form without any change writes no entry. When a model event cancels the save or the delete
(`saving`, `deleting` and so on return `false`), no entry is written either.

### Which fields are diffed

The diff covers the Entity's fields, not every attribute of the model:

- every field of `fields()` is recorded, whatever its visibility (`hideFrom*`, `onlyOn*`, `canSee`):
  what to show is decided when the log is read (see [Who can see the log](#who-can-see-the-log));
- relation panels (`HasMany`, `BelongsToMany`) are not recorded as fields; attaching is recorded by the
  separate `attached`/`detached` events;
- computed fields without a model column are not recorded;
- model attributes that are not Entity fields (`updated_at`, internal columns) are not recorded.

The fields come from `fields()` for the current request. When `fields()` returns a different set for
different users, the log only gets the fields `fields()` returned for the user who made the change. To
always record a field, add it unconditionally and limit its visibility with `canSee()`.

### Values

- **`BelongsTo`** stores the foreign key. The title of the related record is resolved when the log is
  read, and only within what the viewer may see (see [Who can see the log](#who-can-see-the-log)).
- **`File` and `Image`** store the file names and paths, not the contents. Only the names reach the
  browser.
- **`Select`** stores the value; the option label is shown when the log is read.
- **Dates and enums** are normalized: an attribute with a `date` cast as `Y-m-d`, other dates as
  ISO-8601, enums as their `value` (backed) or `name`.
- **Comparison** follows Eloquent's dirty check: a form posting `"5"` for a column that holds `5` is not
  a change, and neither is `true`/`false` against `1`/`0` (a column without a `boolean` cast). Two
  strings are compared strictly: `"007"` → `"7"` is a change.
- **Length.** Each value is cut to `max_value_length` characters (1000 by default, at least 100); arrays
  are JSON-encoded first. The whole diff of one entry is limited to 64 KB: the largest values are
  replaced with empty ones. Cut values are marked "(truncated)" in the interface.

## Masking sensitive fields

For sensitive fields the log records only that the field changed, without the "before" and "after"
values. The interface shows such changes as "Hidden".

Masked automatically:

- every `Password` field (it cannot be unmasked: `->sensitive(false)` has no effect on `Password`);
- the attributes listed in the model's `$hidden` and, when the model has a non-empty `$visible`, every
  attribute outside it;
- attributes with the `hashed`, `encrypted`, `encrypted:*` (`encrypted:array`, `encrypted:collection`,
  `encrypted:object`), `AsEncryptedArrayObject` or `AsEncryptedCollection` casts, and with any cast
  class whose name contains "encrypt" (a custom `EncryptedJson`, say).

When the log is read, changes to fields the viewer sees neither in the list nor on the record page
(`onlyOnForms()`, say, or `hideFromIndex()` together with `hideFromDetail()`) are shown as "Hidden" too.

Mark any other field yourself:

```php
Text::make('API token', 'api_token')->sensitive(),
Textarea::make('Internal note', 'internal_note')->sensitive(),
```

`sensitive()` only affects the log: forms, the list and the record page show the field as usual.

> **Check the fields the rules cannot recognize.** The log stores the value the model returns. When an
> attribute is decrypted by an accessor (`get…Attribute`, `Attribute::make()`) rather than a cast, or a
> JSON column holds tokens or other secrets, mark the field `->sensitive()`, or the decrypted value ends
> up in the log.

Masking is applied again when the log is read, under the current rules. When a field becomes
`sensitive()` later, its older values are shown as "Hidden" too (they stay in the table until pruned).

The values of action fields are masked by the same `Password` and `sensitive()` rules, see
[Actions](#actions).

## Opting an Entity out

```php
class Tag extends Entity
{
    public static string $model = \App\Models\Tag::class;

    public static bool $auditable = false;
}
```

Changes to this Entity are not recorded, the "History" panel is not shown on its record pages, and the
record history endpoint answers 404. Entries written earlier stay on the "Audit log" page.

`Entity::audited()` returns whether the Entity is recorded right now: the log is on and `$auditable` is
`true`.

| Member | Type, default | Description |
| --- | --- | --- |
| `$auditable` | `bool`, `true` | Whether to record the Entity's changes when the log is on |
| `audited()` | `bool` | `Larsadmin::auditEnabled() && static::$auditable` |

## Actions

An action run writes one entry per handled record. All the entries of one run share a `batch_id`.

- Before `handle()` the field values of the chunk's records are captured, and after `handle()` the
  records are read again in one query. The difference is the action's change. The entries are written
  in the chunk's transaction (`chunkSize`): when a chunk is rolled back, it has no entries.
- Calls to `Entity::save()` and `Entity::deleteRecord()` inside `handle()` for the chunk's own records
  write no entries of their own: the action entry covers them. Saves of other records (related ones
  saved through their Entity, say) are recorded as usual, with the run's `batch_id`.
- When the record no longer exists after the action, the entry is marked "The action deleted the record".
- Only the diff of the target Entity's fields is recorded. Changes `handle()` makes to other tables are
  not part of it.
- The entry also stores the action's name at run time and the values of its fields. `Password` and
  `sensitive()` field values are masked, long values are cut.
- A `standalone` action writes one entry without a record: it is visible only on the "Audit log" page.
- A [background action](18-notifications.md#audit-log) is recorded the same way. The user, IP
  address and user agent are taken from the moment it was started, all chunks of the run share one
  `batch_id`, and the entries' `meta` holds the run id (`run`). In the log's JSON such an action has
  `action.queued: true`.

When the log is read, only the action fields that are in the action's current `fields()` and pass
`canSee` are shown. When the action was removed from the Entity, only its name is shown.

## Transactions

The entry is inserted in the same transaction as the change:

- the change is rolled back (an exception while saving, a failing action chunk) → there is no entry;
- the insert into the log fails → an exception, and the change is rolled back too.

This holds when the log and the Entity's model use the same database connection. When
`audit.connection` points to another connection, the entry is inserted **after the commit** of the
change, without atomicity: when the insert fails, the change stays, a `critical` message (with the
`batch_id`, the Entity and the record keys) is logged and the exception goes to `report()`. Alert on
these messages.

## Who can see the log

> **The "Audit log" page is not row-scoped.** It does not check `indexQuery()`, `detailQuery()` or
> `view` for each entry (the record may have been deleted, and its policy can no longer be evaluated).
> Anyone with the `viewLarsadminAudit` gate and `viewAny` on an Entity sees **every** entry of that
> Entity, including records outside their `indexQuery()`, with all the fields visible to them. The gate
> is the trust boundary: in multi-tenant applications, grant it only to those who may see the data of
> every tenant.

Viewing is closed until the application defines the `viewLarsadminAudit` gate. The package does not
define it, so without it nobody sees the log (fail closed). `Gate::before` (for a super admin, say) is
honored. `allow_local` does not apply to this gate: even with `APP_ENV=local` the gate is required.

| What | Conditions |
| --- | --- |
| The "Audit log" menu item | the log is on and the `viewLarsadminAudit` gate allows it |
| The "Audit log" page | the gate; only entries of Entities registered now on which the user has `viewAny` |
| A record's "History" panel | the gate, `$auditable`, `viewAny` on the Entity, the record within `detailQuery()` and `view` on it |

When the log is read:

- changes to fields hidden from the viewer by `canSee` are not shown, nor is their number;
- changes to fields that are no longer among the Entity's fields (removed, renamed) are not shown;
- the title and link of the record, of a `BelongsTo` related record and of the record of an
  `attached`/`detached` entry are shown only when the record is within its Entity's `detailQuery()` and
  the viewer has `view` on it. Otherwise `#id` is shown without a link; a deleted and an inaccessible
  record look the same;
- the IP address and the user agent are shown only on the "Audit log" page (in an expanded entry), not
  in a record's panel.

### Immutability

The log is read-only: the panel has no routes to change or delete entries. The
`Maxcxam\Larsadmin\Audit\AuditEntry` model does not let an entry be changed or deleted through Eloquent:
`$guarded = ['*']`, and `save()`, `push()`, `update()`, `increment()`/`decrement()`, `delete()` and the
`updating`/`deleting` events throw a `LogicException`. Only the package itself writes entries, and only
[`larsadmin:prune-audit`](#pruning) deletes them.

This guards against accidental changes in code, not against an attacker: the model cannot stop query
builder or raw SQL writes. For real tamper resistance:

- give the application's database user only `INSERT` and `SELECT` on the log table, and `DELETE` only to
  the user that runs the pruning;
- or keep the log on a separate connection (`audit.connection`) with its own grants. Keep in mind that
  the entry is then not atomic with the change (see [Transactions](#transactions)).

See also [Authorization](05-authorization.md#audit-log).

## Interface

### A record's history

The "History" panel sits at the bottom of the record page, after the relation panels. It shows the 10
newest entries; "Show more" appends the next ones. After a save, an attach or an action on the same page
the panel reloads.

Each entry is collapsed to one line: the user, the event, the number of changed fields and the time.
The user is shown as the label with the key (`Olena Koval (#12)`), so equal labels are not confused; a
change without a user is marked "System". A button expands the "Field / Before / After" table. Times are shown in the
panel timezone (`larsadmin.timezone`).

The history of a deleted record is available only on the "Audit log" page.

### The "Audit log" page

The "Audit log" item appears in the side menu below the Entities. The page shows the entries of every
Entity the user may access, newest first, `audit.per_page` per page (25). Columns: time, user, event,
section (Entity), record, changes.

Filters:

- **Section**: the Entities the user has `viewAny` on;
- **Event**: created, updated, deleted, action, attached, detached;
- **User**: up to 200 users found in the visible entries, plus "System". Equal labels get the key
  appended: `Olena Koval (#12)`. The list is cached per viewer for 5 minutes, so a new user may take a
  moment to appear in the filter;
- **Date**: a range of days in the panel timezone, both ends inclusive.

Pagination is "Previous / Next" only, without a total: the page never counts the whole table.

The panel and the page work with the keyboard and screen readers (WCAG 2.1 AA): entries expand with a
button carrying `aria-expanded`, and the diff is conveyed by a table and text, not by color alone.

## Configuration

`config/larsadmin.php`:

```php
'audit' => [
    'enabled' => env('LARSADMIN_AUDIT', false),
    'table' => 'larsadmin_audit_entries',
    'connection' => env('LARSADMIN_AUDIT_CONNECTION'),
    'retention_days' => 90,
    'store_ip' => true,
    'store_user_agent' => true,
    'max_value_length' => 1000,
    'per_page' => 25,
    'throttle' => 60,
],
```

| Key | Env | Default | Description |
| --- | --- | --- | --- |
| `audit.enabled` | `LARSADMIN_AUDIT` | `false` | Turns recording and viewing on. A disabled log writes nothing and its routes answer 404 |
| `audit.table` | | `larsadmin_audit_entries` | The log table. Change it before running the migration: the migration reads the name from this key |
| `audit.connection` | `LARSADMIN_AUDIT_CONNECTION` | `null` (default connection) | The log's database connection. A connection other than the model's means writing after the commit, without atomicity (see [Transactions](#transactions)) |
| `audit.retention_days` | | `90` | How many days `larsadmin:prune-audit` keeps entries; `null` keeps everything |
| `audit.store_ip` | | `true` | Store the IP address |
| `audit.store_user_agent` | | `true` | Store the user agent (up to 512 characters) |
| `audit.max_value_length` | | `1000` | The longest stored value, in characters; at least 100 |
| `audit.per_page` | | `25` | Entries per "Audit log" page; limited to 1..100 |
| `audit.throttle` | | `60` | Requests per minute per user to the log routes (limiter `larsadmin-audit`); `null` disables the limit |

A config published earlier without an `audit` section works with the defaults, that is with the log off.

### The migration

The package does not add its migrations to the application's `migrate` implicitly: publish the migration.

```bash
php artisan vendor:publish --tag=larsadmin-migrations
php artisan migrate
```

The file lands in `database/migrations` with the current date in its name. The migration reads the
table and connection from `audit.table` and `audit.connection`, so set them before `migrate`.

### The user label

Along with the user's key, a snapshot of their label is stored, so the log stays readable after the user
is renamed or deleted. By default it is `name`, else `email`, else `#{id}`. Set your own label in the
service provider's `boot()`:

```php
use Maxcxam\Larsadmin\Larsadmin;

Larsadmin::auditActorLabelUsing(fn ($user) => "{$user->name} ({$user->email})");
```

The closure receives the `Authenticatable` and returns `?string`; the label is cut to 255 characters.
Passing `null` instead of a closure restores the default.

## Pruning

```bash
php artisan larsadmin:prune-audit            # older than audit.retention_days (90 days)
php artisan larsadmin:prune-audit --days=30  # older than 30 days
```

The command deletes the entries older than the given number of days in chunks of 1000 and prints how
many it deleted. `--days` must be an integer of at least 1. When `retention_days` is `null` and `--days`
is not given, the command deletes nothing. It also works while the log is off.

The package does not register a schedule itself. Add the command to the application's scheduler:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('larsadmin:prune-audit')->daily();
```

The log holds personal data (names and emails in labels, IP addresses, changed values). Choose
`retention_days` to match your privacy requirements (GDPR, for example), and turn off
`store_ip`/`store_user_agent` when you do not need them.

### Erasing or anonymizing a person's data

The panel has no interface to erase or anonymize log entries. When a person asks to have their data
erased (GDPR, the right to be forgotten), do it with a database query run by a user with
`UPDATE`/`DELETE` on the log table (the `AuditEntry` model refuses such changes, so go through `DB`):

```php
use App\Models\User;
use Illuminate\Support\Facades\DB;

$audit = DB::connection(config('larsadmin.audit.connection'))
    ->table(config('larsadmin.audit.table', 'larsadmin_audit_entries'));

// Anonymize the entries the person made: drop the label, IP and user agent.
(clone $audit)
    ->where('actor_type', (new User)->getMorphClass())
    ->where('actor_id', (string) $user->getKey())
    ->update(['actor_label' => null, 'ip_address' => null, 'user_agent' => null]);

// Or delete them entirely.
(clone $audit)
    ->where('actor_type', (new User)->getMorphClass())
    ->where('actor_id', (string) $user->getKey())
    ->delete();

// The history of the person's own record (their profile in a users Entity, say).
(clone $audit)
    ->where('auditable_type', (new User)->getMorphClass())
    ->where('auditable_id', (string) $user->getKey())
    ->delete();
```

Field values in the `changes` column of other entries may hold the person's data too; check them
separately. To keep less of such data in the first place, turn off `store_ip`/`store_user_agent` and
keep `retention_days` as short as you can.

## The `AuditRecorded` event

After the commit of a change that wrote entries, the `Maxcxam\Larsadmin\Events\AuditRecorded` event is
dispatched. Its `entries` property is a `Collection` of `AuditEntry` models with the attributes as
written. Useful, for example, to forward entries to a SIEM:

```php
use Illuminate\Support\Facades\Event;
use Maxcxam\Larsadmin\Events\AuditRecorded;

Event::listen(function (AuditRecorded $event) {
    foreach ($event->entries as $entry) {
        logger()->channel('audit')->info($entry->event, $entry->only('entity', 'auditable_id', 'actor_label', 'batch_id'));
    }
});
```

- The event is dispatched only when it has listeners.
- Entries inserted in one query (an action run on several records) have `id = null`; correlate them by
  `batch_id`.
- When the change is rolled back, the event is not dispatched.
- An exception in a listener goes to `report()` and does not break the request: the change is already
  committed.

## HTTP endpoints

Both routes are in the `auth_middleware` group, read-only, with the `larsadmin-audit` limiter
(`audit.throttle`) and a `Cache-Control: no-store, private` header.

| Route | Name | Response |
| --- | --- | --- |
| `GET {path}/audit` | `larsadmin.audit.index` | the `Audit/Index` Inertia page; parameters `filters[entity\|event\|actor\|date]`, `page` |
| `GET {path}/api/entities/{entity}/{id}/audit?page=N` | `larsadmin.api.audit` | a record's history as JSON, 10 entries per page |

```json
{
  "entries": [
    {
      "id": 41,
      "event": "updated",
      "createdAt": "2026-10-30T14:05:00+02:00",
      "actor": { "label": "Olena Koval", "id": "12", "system": false },
      "action": null,
      "related": null,
      "soft": false,
      "recordDeleted": false,
      "changes": [
        { "attribute": "title", "label": "Title", "old": { "kind": "text", "text": "Draft" }, "new": { "kind": "text", "text": "Kyiv in autumn" } },
        { "attribute": "password", "label": "Password", "masked": true }
      ]
    }
  ],
  "page": 1,
  "perPage": 10,
  "hasMore": false,
  "timezone": "Europe/Kyiv"
}
```

`actor` is `null` for a change without a user ("System"), otherwise `{ label, id, system: false }`; the
"Audit log" page adds `type`.

| Situation | Status |
| --- | --- |
| The log is off | 404 |
| No `viewLarsadminAudit` | 403 |
| An Entity with `$auditable = false` (record history) | 404 |
| No `viewAny` on the Entity or `view` on the record (record history) | 403 |
| The record is outside `detailQuery()` (record history) | 404 |
| `page` is not an integer from 1 to 1000 (record history; the "Audit log" page shows the first page instead) | 422 |
| `audit.throttle` exceeded | 429 |

A 404 response never names the model class.

The frontend learns whether to show the menu item from the shared Inertia prop `larsadmin.audit`: `null`
or `{ url }`. The record page gets an `audit` prop with the history's `{ url }`, or `null`.

## Performance

| Operation | Extra cost |
| --- | --- |
| Create, update, delete | 1 `INSERT` (value snapshots in memory, no extra `SELECT`) |
| Attach, detach | 1 `INSERT` |
| Action, per chunk | 1 `SELECT` to read the records again and 1 batched `INSERT` |
| Log off or `$auditable = false` | nothing |

The table has indexes for a record's history, the entity, user and date filters, and `batch_id`. The
table's growth is limited by `retention_days`, `max_value_length` and the 64 KB limit per diff.

## Limitations

- Only changes made **through the panel** are recorded. Changes the application makes elsewhere (its own
  controllers, queues, tinker, Eloquent directly) are not.
- Logins and logouts, page views and file downloads are not recorded.
- There is no undo from the log, no export, no search in the changes.
- `BelongsToMany` pivot attributes are not recorded. Attaching shows in the parent record's history, not
  the related record's.
- Changes to `HasMany` child records are recorded on the child records, when made through their Entity.
- An action's diff covers only the target Entity's fields (see [Actions](#actions)).
- A separate log connection (`audit.connection`) gives no atomicity (see [Transactions](#transactions)).
- Changing an encrypted attribute whose stored value can no longer be decrypted (after rotating
  `APP_KEY` without `APP_PREVIOUS_KEYS`, say) still throws from Eloquent's dirty check, before the log is
  involved (CRA-15).

Next: [Notifications and background actions](18-notifications.md).
