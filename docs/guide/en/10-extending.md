# 10. Extending
🇺🇦 [Українська](../10-extending.md)

The panel can be extended on two levels:

- **PHP classes** you already extend in your application (entities, actions, filters, metrics,
  events). They need no JavaScript, and the pages below describe each of them.
- **The public extension API** (since 1.5): your own Vue components for fields, cards, filters and
  sidebar pages, your own routes and translations, and overriding the UI of built-in components. An
  extension is a Composer package (or a directory in the application) and plugs in without rebuilding
  the panel. Guide: [Extensions: the public API](19-extensions.md).

## What you can extend

| What | Base class or API | Section |
| --- | --- | --- |
| Entity | `Maxcxam\Larsadmin\Entity` | [Entities](02-entities.md) |
| Action | `Maxcxam\Larsadmin\Actions\Action` | [Actions](07-actions.md) |
| Filter | `Maxcxam\Larsadmin\Filters\Filter` | [Filters](06-filters.md) |
| Metric | `Maxcxam\Larsadmin\Metrics\Value`, `Trend`, `Partition`, `Progress` | [Metrics](13-metrics.md) |
| Dashboard | `Maxcxam\Larsadmin\Dashboards\Dashboard` | [Dashboards](14-dashboards.md) |
| Deleting a record with files | `Entity::deleteRecord(Request)` in your actions | [Files and images](15-files.md#deleting-records) |
| Global search through Scout | `Entity::usesScout()`, `Entity::scoutQuery()` | [Global search](16-search.md#laravel-scout) |
| Reacting to audit log entries | the `Maxcxam\Larsadmin\Events\AuditRecorded` event | [Audit log](17-audit-log.md#the-auditrecorded-event) |
| Notifications in the panel's bell | `Maxcxam\Larsadmin\Notifications\LarsadminNotification` (may be extended) | [Notifications](18-notifications.md#sending-notifications) |
| Background action | `Action::$queued`, `queued()`, `onQueue()`, `onQueueConnection()`, `$timeout` | [Notifications: background actions](18-notifications.md#background-actions) |
| Reacting to a finished background action | the `Maxcxam\Larsadmin\Events\ActionRunFinished` event, the `Maxcxam\Larsadmin\Actions\Queued\ActionRun` model (read-only) | [Notifications](18-notifications.md#the-actionrunfinished-event) |
| Field with a built-in component | `Maxcxam\Larsadmin\Fields\Field` | below |
| Field with its own Vue component | `Field` + `defineExtension({ fields })` | [Custom fields](19-extensions.md#custom-fields) |
| Card (static or with data) | `Maxcxam\Larsadmin\Metrics\Card`, `DataCard` | [Cards](19-extensions.md#cards) |
| Filter with its own component | `Filter` + `defineExtension({ filters })` | [Filters](19-extensions.md#filters) |
| Sidebar page | `Maxcxam\Larsadmin\Tools\Tool` | [Tools](19-extensions.md#tools-sidebar-pages) |
| Your own HTTP routes | `Larsadmin::routes()`, `findRecord()`, `findField()`, `audited()` | [Extension routes](19-extensions.md#extension-routes) |
| UI of a built-in field, filter or metric | `override: true` | [Overrides](19-extensions.md#overriding-built-in-components) |

## A field with a built-in component

A custom field class that only changes server behaviour (rules, filling, formatting) can use the
component of a built-in field. For example, a subclass of `Text` uses `text-field`:

```php
use Maxcxam\Larsadmin\Fields\Text;

class Slug extends Text
{
    protected function formatForDisplay(mixed $value): mixed
    {
        return $value === null ? null : '/'.$value;
    }
}
```

Since 1.5 the protected hooks of `Field` (`resolveValue()`, `formatForDisplay()`, `fillValue()`,
`fillString()`, `typeRules()`, `implicitRules()`, `acceptsNullInput()`, `defaultAlign()`), the public hooks
and `jsonSerialize()` are **stable API** for subclasses (the full list is in
[Custom fields](19-extensions.md#the-php-class)). A field with its own Vue component is described there too.

### Custom array fields and forms with files

A form that contains a `File` or `Image` field, or a field with `multipart: true` (see
[Fields that upload files](19-extensions.md#fields-that-upload-files)), is sent as `multipart/form-data`
(see [Files and images](15-files.md)). Such a request does not carry empty arrays at all: if the user
cleared a custom field whose value is an array, the field's key is missing, and `fill()` keeps the old
value. In a JSON form without such fields the same field receives `[]`. Built-in fields are not affected.
If your field takes an array, either send a presence flag with it (like the `present` key of file fields)
or treat a missing key as an empty array.

## The component registry

The server-side `component` key of a field, filter or card is matched to a Vue component through the
panel's registry. Since 1.5 the registry is open to extensions through `defineExtension()` of the
`larsadmin` module, and the built-in components register in it the same way. The internal functions
`registerField`, `registerFilter` and `registerCard` remain as wrappers and are not API.

How the panel handles a key:

| Key | Behaviour |
| --- | --- |
| built-in (`text-field`, `select-filter`, …) | the built-in component or its [override](19-extensions.md#overriding-built-in-components) |
| `vendor/name`, registered by an extension | the extension's component |
| `vendor/name`, not registered (the extension did not load, is incompatible or crashed) | an "Unavailable" placeholder; in a form the value is **not sent** ([fail closed](19-extensions.md#unavailable-and-crashed-extensions)) |
| without `/`, unknown to the panel | a field renders as plain text (display) and a text input (form), a filter as a select, as in 1.4; a card shows the error "Unknown card component: …" |

Keys of the built-in components:

| Type | Keys |
| --- | --- |
| Fields | `id-field`, `text-field`, `textarea-field`, `number-field`, `boolean-field`, `select-field`, `date-field`, `datetime-field`, `password-field`, `file-field`, `image-field`, `belongs-to-field`, `has-many-field`, `belongs-to-many-field` |
| Filters | `select-filter`, `boolean-filter`, `date-range-filter` |
| Cards | `value-metric`, `trend-metric`, `partition-metric`, `progress-metric` |

How to build and test the package itself: the "Developing the package" section of
[Installation](01-installation.md#developing-the-package).

Next: [List interface](11-ui.md).
