# 12. Field blocks
🇺🇦 [Українська](../12-layout.md)

On the create and edit forms and on the detail page, fields can be grouped into **panels** with a
heading and into **tabs**. Blocks affect only the presentation: validation, saving, the list (index)
and the column order work exactly as without them.

```php
use Maxcxam\Larsadmin\Layout\Panel;
use Maxcxam\Larsadmin\Layout\Tabs;

public function fields(Request $request): array
{
    return [
        ID::make()->sortable(),
        Text::make('Заголовок', 'title')->rules('required', 'max:255'),

        Panel::make('Публікація', [
            Select::make('Статус', 'status')->options(['draft' => 'Чернетка', 'live' => 'Опубліковано']),
            DateTime::make('Дата публікації', 'published_at')->nullable(),
            Textarea::make('Анонс', 'excerpt')->nullable()->fullWidth(),
        ])->columns(2)->description('Коли й як допис з\'явиться на сайті.'),

        Tabs::make('Додатково', [
            Panel::make('SEO', [
                Text::make('Meta title', 'meta_title')->nullable(),
                Textarea::make('Meta description', 'meta_description')->nullable(),
            ]),
            Panel::make('Статистика', [
                Number::make('Перегляди', 'views')->exceptOnForms(),
            ]),
        ]),

        Panel::make('Службове', [
            Textarea::make('Внутрішня примітка', 'internal_note')->nullable(),
        ])->collapsed(),

        BelongsToMany::make('Теги', 'tags', Tag::class),
    ];
}
```

`fields()` returns a mix of fields, `Panel` and `Tabs` in the order you want.

## Panel

`Panel::make(string $name, array $fields)` takes a name and a list of fields.

| Method | Default | Description |
| --- | --- | --- |
| `description(string)` | | text under the panel heading |
| `columns(int)` | `1` | `1` or `2` columns. Any other value throws `InvalidArgumentException` |
| `collapsible(bool $collapsible = true)` | `false` | the panel can be collapsed with a button in its heading; initially expanded |
| `collapsed(bool $collapsed = true)` | `false` | the panel is initially collapsed; also enables `collapsible()` |

The heading serves as the collapse button, so `collapsible()` and `collapsed()` on a panel with an
empty name throw `InvalidArgumentException`.

The collapsed state lives only on the open page and is not kept between page visits.

### Two columns and `fullWidth()`

In a panel with `columns(2)`, fields are laid out in a two-column grid, with the label above the field.
On narrow screens the grid collapses into one column.

`Field::fullWidth(bool $full = true)` stretches a field across both columns: handy for `Textarea` and
long texts. In a single-column panel `fullWidth()` changes nothing.

> `fullWidth()` and `width()` are different things: `width()` sets the width of the **list column**
> ([Fields](03-fields.md#list-columns)), while `fullWidth()` applies only to forms and the detail page.

## Tabs

`Tabs::make(?string $name, array $panels)` creates a set of tabs: **each panel becomes a tab**, and
the panel name becomes the tab label.

- At least one panel is required, otherwise `InvalidArgumentException`. Anything other than a `Panel`
  throws `LogicException`.
- `$name` is not shown as a heading; it is the accessible name of the tab set for screen readers
  (`aria-label`). You can pass `null`.
- A panel's `description()` and `columns()` also work inside a tab. `collapsible()`/`collapsed()` are
  ignored in a tab.
- Tabs can be operated with the keyboard: the ←/→ arrows, `Home`, `End`.

## Fields outside blocks

Fields placed in `fields()` outside `Panel` and `Tabs` are grouped into a panel without a heading, at
the position where they stand. That is why an Entity without blocks looks the same as before.

## Restrictions

`fields()` is checked when the fields are built. Errors throw `LogicException`:

| Situation | Why |
| --- | --- |
| `HasMany` or `BelongsToMany` inside a `Panel` | relation panels are rendered separately. Keep them outside blocks; they are shown after the blocks on the detail page |
| a panel inside a panel, tabs inside a panel | nesting blocks is not supported |
| the same attribute twice (including in different blocks) | an attribute must be unique within `fields()` |
| an element that is not a `Field`, `Panel` or `Tabs` | |

`BelongsTo` can go into a panel: it is a regular form field.

## Visibility

Blocks respect field visibility (`hideFromDetail`, `onlyOnForms`, `canSee`, conditional closures and so
on, see [Fields](03-fields.md#visibility)) separately for each page and each record. A panel or tab
with no visible fields left is not shown. A tab set without any tabs disappears too.

Blocks do not affect the list (index): the table columns come from the fields in the order they are
declared.

## Validation errors

After a failed save, the form shows where the errors are, even if a field is hidden:

- a collapsed panel with an error expands;
- in a tab set, the first tab with an error opens;
- the tab label (and the heading of a collapsible panel) shows the number of errors in it.

For screen readers, the counter has text such as "2 errors".

## How it is passed to the frontend

For reference, for those who read the Inertia responses: blocks do not change the fields array. A
separate `layout` tree with the field attributes is passed alongside it (`record.layout` on the detail
and edit pages, the `layout` prop on the create page). List rows have no `layout`.

Next: [Metrics](13-metrics.md).
