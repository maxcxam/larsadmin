# 10. Extending
🇺🇦 [Українська](../10-extending.md)

> **There is no public API for extending the frontend in 1.0.** It is planned for future versions.
> Everything described below is internal and may change without notice and without a CHANGELOG entry.

## What you can extend in PHP

The backend is built on plain PHP classes that you already extend:

| What | Base class | Section |
| --- | --- | --- |
| Entity | `Maxcxam\Larsadmin\Entity` | [Entities](02-entities.md) |
| Action | `Maxcxam\Larsadmin\Actions\Action` | [Actions](07-actions.md) |
| Filter | `Maxcxam\Larsadmin\Filters\Filter` | [Filters](06-filters.md) |
| Metric | `Maxcxam\Larsadmin\Metrics\Value`, `Trend`, `Partition`, `Progress` | [Metrics](13-metrics.md) |
| Dashboard | `Maxcxam\Larsadmin\Dashboards\Dashboard` | [Dashboards](14-dashboards.md) |
| Field | `Maxcxam\Larsadmin\Fields\Field` | below |

A custom field class works on the server (rules, filling, serialization), but it must set `$component`
to one of the **existing** frontend components, because the package ships with a prebuilt bundle.
For example, a subclass of `Text` uses `text-field`.

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

The protected methods of `Field` (`resolveValue`, `formatForDisplay`, `fillValue`, `typeRules`) are
meant for subclasses, but are formally not part of the stable API.

## Internal component registry

The frontend source (`resources/js/fields/index.js`) has a `registerField` function that maps the
`component` key of a server-side field to Vue components:

```js
registerField(component, { display, form, panel })
```

Likewise, `registerFilter(component, vueComponent)` in `resources/js/filters/index.js` and
`registerCard(component, vueComponent)` in `resources/js/components/cards/index.js`.

What this means in practice:

- The registries are **internal**: they live inside the package bundle and are not exported to `window`.
- You cannot call them from your application. Your own Vue components get into the panel only by
  changing the package code and rebuilding it (`npm run build` in the package repository).
- An unknown `component` key does not raise an error anywhere: a field renders as plain text (display)
  and a text input (form), a filter as a select. A card with an unknown key shows a visible error,
  "Unknown card component: …".

Keys of the built-in components:

| Type | Keys |
| --- | --- |
| Fields | `id-field`, `text-field`, `textarea-field`, `number-field`, `boolean-field`, `select-field`, `date-field`, `datetime-field`, `password-field`, `belongs-to-field`, `has-many-field`, `belongs-to-many-field` |
| Filters | `select-filter`, `boolean-filter`, `date-range-filter` |
| Cards | `value-metric`, `trend-metric`, `partition-metric`, `progress-metric` |

## What is planned

A public extension API (plugging your own Vue components for fields, filters and cards in from the
application, without rebuilding the package) is planned for future versions. Until then, rely only on
the documented sections of this guide.

How to build and test the package itself: the "Developing the package" section of
[Installation](01-installation.md#developing-the-package).
