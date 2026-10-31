# Field API

Methods of `Maxcxam\Larsadmin\Fields\Field` (inherited by every field) that host code calls. Source of
truth: guide 03 (fields) and guide 12 (layout). Hooks for custom field classes are in guide 19 and the
`larsadmin-extending` skill.

## Creating

| Method | Notes |
| --- | --- |
| `make(string $label, ?string $attribute = null)` | attribute derived from the label when omitted (ASCII labels only) |
| `ID::make()` | label `ID`, attribute `id`; index and detail only |

## Values

| Method | Notes |
| --- | --- |
| `default(mixed\|Closure)` | create form value; a closure receives the `Request` |
| `nullable(bool = true)` | empty string stored as `null`; adds `nullable` |
| `resolveUsing(callable)` | value the form sees: `fn ($raw, $record, $attribute)` |
| `displayUsing(callable)` | value index/detail show: `fn ($raw, $record, $attribute)` |
| `fillUsing(callable)` | replaces writing: `fn ($request, $record, $attribute)`; may return `fn (Model $record)` run after save |
| `help(string)`, `placeholder(string)` | UI text |
| `withMeta(array)` | extra serialized data (sent to the browser) |
| `sensitive(bool = true)` | audit log records the change without values |

## Visibility

| Method | Notes |
| --- | --- |
| `hideFromIndex()`, `hideFromDetail()`, `hideWhenCreating()`, `hideWhenUpdating()` | `bool\|Closure(Request, ?Model): bool` |
| `showOnIndex()`, `showOnDetail()`, `showWhenCreating()`, `showWhenUpdating()` | same signature |
| `onlyOnIndex()`, `onlyOnDetail()`, `onlyOnForms()`, `exceptOnForms()` | shortcuts |
| `canSee(Closure(Request): bool)` | removes the field from every context |
| `readonly(bool\|Closure(Request, Model): bool)` | shown but never filled or validated |

Defaults: `ID` index and detail; `Textarea` not on index; `Password` forms only; `HasMany` and
`BelongsToMany` detail only (as panels).

## List columns

| Method | Notes |
| --- | --- |
| `sortable(bool = true)` | also required for `Entity::$sort` keys |
| `width(string\|int)` | int = px; or `px`, `%`, `rem`, `em`, `ch` strings |
| `align('left'\|'center'\|'right')` | defaults: `Number` right, `Boolean` center |
| `wrap()`, `truncate(?int $chars = null)` | mutually exclusive |
| `hideByDefault()` | column off until enabled in the "Columns" menu |

## Validation

| Method | Notes |
| --- | --- |
| `rules(...)` | create and update |
| `creationRules(...)`, `updateRules(...)` | one context; each call replaces that set |

Strings with `|` are split, except those containing `regex:`/`not_regex:`. `{{id}}` is the record key or
`NULL`. Order: implicit type rules, `rules()`, then `creationRules()`/`updateRules()`.

## Rights and context (stable since 1.6)

| Method | Notes |
| --- | --- |
| `authorizedTo(string $ability): bool` | `view`, `update` or `delete` on the resolved record; `false` otherwise, on create, in actions and for hand-built fields |
| `inAction(): bool` | `true` when the field belongs to an action modal |
| `actionValue(Request $request): mixed` | override hook: the value an action receives (see `larsadmin-actions`) |

Context constants: `Field::INDEX`, `Field::DETAIL`, `Field::CREATE`, `Field::UPDATE`.

## Type-specific

| Field | Methods |
| --- | --- |
| `Text` | `inputType('email'\|'url'\|'tel')` |
| `Textarea` | `rows(int)` (5) |
| `Number` | `min()`, `max()`, `step()` |
| `Boolean` | `trueValue()`, `falseValue()` |
| `Select` | `options(array\|Closure)`; `BackedEnum` uses `value`, `UnitEnum` uses `name` |
| `Date`, `DateTime` | `displayFormat('d.m.Y')`; forms send `Y-m-d` / `Y-m-d\TH:i:s` |
| `File`, `Image` | see `larsadmin-files` |
| `BelongsTo`, `HasMany`, `BelongsToMany` | see `larsadmin-relations` |

## Layout

| Method | Notes |
| --- | --- |
| `Panel::make(string $name, array $fields)` | a titled group |
| `->description(string)` | text under the heading |
| `->columns(int)` | 1 or 2 |
| `->collapsible(bool = true)`, `->collapsed(bool = true)` | need a non-empty name; ignored inside tabs |
| `Tabs::make(?string $name, array $panels)` | each `Panel` becomes a tab; `$name` is the `aria-label` |
| `Field::fullWidth(bool = true)` | spans both columns of a 2-column panel |
