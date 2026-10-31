---
name: larsadmin-fields-layout
description: "Larsadmin fields and layout: the built-in field types (Text, Textarea, Number, Boolean, Select, Date, DateTime, Password, ID, File, Image and the relation fields), shared field methods, visibility per page, readonly and canSee rules, index columns, validation rules, per-field abilities, and the Panel and Tabs layout. Use when adding or changing form fields, list columns, validation or the layout of an entity form or detail page in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin fields and layout

## When to use

- Adding, changing or removing fields in an Entity's `fields()`.
- Hiding a field on some pages, making it read-only, or limiting it to some users.
- Validation rules, list column width/alignment/truncation, default values.
- Grouping form and detail fields into panels and tabs.

Relation fields: `larsadmin-relations`. Uploads (`File`, `Image`): `larsadmin-files`. A field with its own Vue
component: `larsadmin-extending`. Fields inside an action modal: `larsadmin-actions`.

## Quick start

Fields come from `Entity::fields(Request $request): array`. `make(label, attribute)` creates a field;
chained methods configure it.

```php
namespace App\Larsadmin;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;

class Post extends Entity
{
    public static string $model = \App\Models\Post::class;

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Title', 'title')->sortable()->truncate(60)->rules('required', 'max:255'),
            Textarea::make('Body', 'body')->nullable(),
            Select::make('Status', 'status')->sortable()->rules('required')
                ->options(['draft' => 'Draft', 'live' => 'Live']),
            Boolean::make('Featured', 'featured'),
            Number::make('Views', 'views')->min(0)->default(0)->exceptOnForms(),
            Date::make('Review by', 'review_due')->nullable()->displayFormat('d.m.Y'),
            DateTime::make('Published at', 'published_at')->nullable()->sortable(),
        ];
    }
}
```

Always pass the attribute as the second argument. Without it the attribute is derived from the label, and a
non-ASCII label throws `InvalidArgumentException`. Verify with MCP `describe-entity {entity: "posts"}`.

## Recipes

### Visibility per page

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\Text;

return [
    DateTime::make('Created', 'created_at')->exceptOnForms()->sortable(),
    Text::make('Code', 'code')->hideFromIndex()->hideWhenCreating(),
    Text::make('Note', 'note')
        ->hideWhenUpdating(fn (Request $request, ?Model $post) => $post?->status === 'live'),
];
```

`hide*`/`show*` take `bool|Closure(Request, ?Model): bool`. On index `$record` is `null`, so per-row
visibility is impossible there.

### Restrict a field to some users, or make it read-only

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;

return [
    Text::make('Moderator note', 'moderator_note')->nullable()
        ->canSee(fn (Request $request) => $request->user()->can('moderate')),
    Select::make('Status', 'status')->options(['draft' => 'Draft', 'live' => 'Live'])
        ->readonly(fn (Request $request, Model $post) => $post->status === 'live'),
];
```

`canSee` removes the field from every context (columns, detail, forms, filling, validation, sorting,
search). Hidden and read-only fields are skipped on the server: values sent by hand are ignored.

### Validation

```php
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Text;

return [
    Text::make('Email', 'email')->inputType('email')
        ->rules('required', 'email', 'max:255', 'unique:users,email,{{id}}'),
    Password::make('Password', 'password')
        ->creationRules('required', 'min:8')
        ->updateRules('nullable', 'min:8'),
];
```

`rules()` applies to create and update, `creationRules()`/`updateRules()` to one of them; each call replaces
the previous set of that method. `{{id}}` becomes the record key (`NULL` on create). Implicit type rules
(`string`, `numeric`, `boolean`, `date`, `Rule::in(options)`) come first. `Number`, `Boolean`, `Date` and
`Select` are required unless `nullable()`. Keep `regex:` rules as separate arguments (no `|` splitting).

### Custom fill and display

```php
use Illuminate\Support\Str;
use Maxcxam\Larsadmin\Fields\Text;

return [
    Text::make('Slug', 'slug')->fillUsing(function ($request, $record, $attribute) {
        $record->slug = Str::slug($request->input('title'));
    }),
    Text::make('Price', 'price_cents')->displayUsing(fn ($raw) => number_format($raw / 100, 2)),
];
```

`fillUsing` may return `fn (Model $record)`, run after the save in the same transaction. With
`fillUsing` the implicit type rules are not added.

### Panels and tabs

```php
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Layout\Panel;
use Maxcxam\Larsadmin\Layout\Tabs;

return [
    Text::make('Title', 'title')->rules('required', 'max:255'),
    Panel::make('Publishing', [
        Select::make('Status', 'status')->options(['draft' => 'Draft', 'live' => 'Live']),
        DateTime::make('Published at', 'published_at')->nullable(),
        Textarea::make('Excerpt', 'excerpt')->nullable()->fullWidth(),
    ])->columns(2)->description('When the post goes live.'),
    Tabs::make('More', [
        Panel::make('SEO', [Text::make('Meta title', 'meta_title')->nullable()]),
        Panel::make('Internal', [Textarea::make('Note', 'internal_note')->nullable()]),
    ]),
    Panel::make('Service', [Text::make('Code', 'code')->nullable()])->collapsed(),
];
```

Blocks change only forms and the detail page; the list keeps the declaration order. Fields outside blocks
form an untitled panel in place. `HasMany` and `BelongsToMany` must stay outside blocks.

### React to the user's rights on the record

`$field->authorizedTo('view'|'update'|'delete')` (stable since 1.6) returns the policy answer the panel
already computed for the record the field is resolved for, without another policy call. It is `false` for
any other ability, on the create form, inside an action and for a field built by hand (fail closed). Use it
in custom field classes, for example to hide a download link (`larsadmin-extending`). It is display logic
only; the server still authorizes every request.

## API cheat sheet

Types (`Maxcxam\Larsadmin\Fields\*`): `ID`, `Text` (`inputType()`), `Textarea` (`rows()`), `Number`
(`min()`, `max()`, `step()`), `Boolean` (`trueValue()`, `falseValue()`), `Select` (`options(array|Closure)`,
enums), `Date` / `DateTime` (`displayFormat()`), `Password`, `File`, `Image`, `BelongsTo`, `HasMany`,
`BelongsToMany`. All extend `Maxcxam\Larsadmin\Fields\Field`; `HasMany` and `BelongsToMany` extend
`Maxcxam\Larsadmin\Fields\PanelField` (detail-page panels, never filled or validated).

Layout: `Maxcxam\Larsadmin\Layout\Panel::make($name, $fields)` with `columns(1|2)`, `description()`,
`collapsible()`, `collapsed()`; `Maxcxam\Larsadmin\Layout\Tabs::make(?$name, $panels)`; `fullWidth()` on a
field.

Every method with its signature: [references/field-api.md](references/field-api.md).

## Security rules

- MUST enforce access with `canSee()`, `readonly()` or policies, never by hiding a field in the UI only.
- MUST mark secret-bearing fields `->sensitive()` so the audit log stores no values (`Password` always is).
- NEVER put secrets into `withMeta()`, `default()` or `displayUsing()` output: it is sent to the browser.
- NEVER treat `authorizedTo()` or `inAction()` as an authorization boundary; they only shape output.
- MUST keep `canSee` closures independent of the session if the entity has queued actions.

## Pitfalls

- Non-ASCII label without an attribute: `InvalidArgumentException`.
- `width()` sets the list column; `fullWidth()` the form/detail grid. Different things.
- `wrap()` and `truncate()` exclude each other; the last call wins.
- `columns()` accepts only 1 or 2; `collapsible()` on an untitled panel throws.
- Nested blocks, a relation panel inside `Panel`, or a duplicate attribute throw `LogicException`.
- `Date` without `nullable()` is required even without a `required` rule.
- A `Select` closure runs once per field instance; keep it cheap.

## Reference

- Fields: [vendor/maxcxam/larsadmin/docs/guide/en/03-fields.md](vendor/maxcxam/larsadmin/docs/guide/en/03-fields.md)
  (MCP: `read-docs page=03-fields`).
- Layout: [vendor/maxcxam/larsadmin/docs/guide/en/12-layout.md](vendor/maxcxam/larsadmin/docs/guide/en/12-layout.md)
  (MCP: `read-docs page=12-layout`).
- MCP: `describe-entity` lists each field's type, contexts, rules summary and the layout.
