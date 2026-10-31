# 03. Fields
🇺🇦 [Українська](../03-fields.md)

Fields are returned by the `Entity::fields(Request $request): array` method. Every field is created with
`Field::make(...)` and configured with chained methods.

```php
Text::make('Назва', 'name')->sortable()->rules('required', 'max:255'),
```

The first argument is the field's label in the UI, the second is the model attribute. When the attribute
is omitted, it is derived from the label: `Str::snake(Str::lower($name))` (`'Created At'` gives `created_at`).
**A label with non-ASCII characters and no explicit attribute throws an `InvalidArgumentException`**, so
always pass the attribute as the second argument for Ukrainian (or other non-ASCII) labels.

## Field types

All classes live in the `Maxcxam\Larsadmin\Fields` namespace.

| Field | Component | Notes |
| --- | --- | --- |
| `ID` | `id-field` | `ID::make()` gives the label `ID` and the attribute `id`; shown only on index and detail |
| `Text` | `text-field` | `inputType('email')` sets the HTML input type (`email`, `url`, `tel`) |
| `Textarea` | `textarea-field` | hidden on index; `rows(int)`, 5 by default |
| `Number` | `number-field` | `min()`, `max()`, `step()` |
| `Boolean` | `boolean-field` | `trueValue()`, `falseValue()` set what is stored; `true`/`false` by default |
| `Date` | `date-field` | the form sends `Y-m-d`; `displayFormat('d.m.Y')` for index and detail |
| `DateTime` | `datetime-field` | the form sends `Y-m-d\TH:i:s`; inherits `displayFormat()` |
| `Password` | `password-field` | forms only; the hash is never sent to the browser; changed only when a new value is entered (`Hash::make`) |
| `Select` | `select-field` | `options(array\|Closure)`, a `value => label` map |
| `File` | `file-field` | uploads a file to a disk; `multiple()`, private files with signed URLs, see [Files and images](15-files.md) |
| `Image` | `image-field` | like `File`, raster images only, with thumbnails; see [Files and images](15-files.md) |
| `BelongsTo`, `HasMany`, `BelongsToMany` | | see [Relations](04-relations.md) |

The default date display format comes from the `larsadmin::ui.formats.date` and
`larsadmin::ui.formats.datetime` translations (for `en`: `Y-m-d` and `Y-m-d H:i:s`), so it depends on the
panel language.

### Example: all types together

Example (`app/Larsadmin/Post.php`):

```php
public function fields(Request $request): array
{
    return [
        ID::make()->sortable(),
        Text::make('Заголовок', 'title')->sortable()->rules('required', 'max:255'),
        Textarea::make('Текст', 'body')->nullable(),
        Select::make('Статус', 'status')->sortable()->rules('required')->options([
            'draft' => 'Чернетка',
            'review' => 'На перевірці',
            'live' => 'Опубліковано',
        ]),
        Boolean::make('Опубліковано', 'published'),
        Number::make('Перегляди', 'views')->min(0)->default(0)->sortable(),
        Date::make('Перевірити до', 'review_due')->nullable(),
        DateTime::make('Дата публікації', 'published_at')->nullable()->sortable(),
    ];
}
```

`Select` understands enums: for a `BackedEnum` it uses `value`, for a `UnitEnum` it uses `name`. Options
can also be given as a closure, which runs once per field instance:

```php
Select::make('Статус', 'status')->options(fn () => Status::pluck('label', 'code')->all());
```

## Common methods

| Method | Effect |
| --- | --- |
| `sortable(bool $sortable = true)` | allows sorting by the column on index (also required for `Entity::$sort`) |
| `nullable(bool $nullable = true)` | an empty string is stored as `null`; adds the `nullable` rule |
| `readonly(bool\|Closure $readonly = true)` | the field is neither filled nor validated (see "Visibility") |
| `help(string)` | help text below the field |
| `placeholder(string)` | placeholder |
| `default(mixed\|Closure)` | value on the create form; a closure receives the `Request` |
| `withMeta(array)` | extra data in the serialized field |
| `resolveUsing(callable)` | changes the value the form sees: `fn ($raw, $record, $attribute)` |
| `displayUsing(callable)` | changes what index/detail display: `fn ($raw, $record, $attribute)` |
| `fillUsing(callable)` | fully replaces how the value is written: `fn ($request, $record, $attribute)` |
| `sensitive(bool $sensitive = true)` | the audit log records only that the field changed, without its values; forms and pages are not affected. `Password` is always masked; also mark fields with a decrypting accessor and JSON columns holding secrets ([Audit log](17-audit-log.md#masking-sensitive-fields)) |

`fillUsing` may return a closure `fn (Model $record)`. It runs **after the record is saved**, in the same
transaction (handy for dependent data).

```php
Text::make('Slug', 'slug')->fillUsing(function ($request, $record, $attribute) {
    $record->slug = Str::slug($request->input('title'));
});
```

When `fillUsing` is set, the implicit type rules (see below) are not added.

## Visibility

By default a field is visible on index, detail, create and update.

| Method | Effect |
| --- | --- |
| `hideFromIndex()` | hides the field from the list |
| `hideFromDetail()` | hides it from the detail page |
| `hideWhenCreating()` | hides it from the create form |
| `hideWhenUpdating()` | hides it from the edit form |
| `showOnIndex()`, `showOnDetail()`, `showWhenCreating()`, `showWhenUpdating()` | shows it in that context |
| `onlyOnIndex()` | only in the list |
| `onlyOnDetail()` | only on the detail page |
| `onlyOnForms()` | only on the create and edit forms |
| `exceptOnForms()` | on index and detail, but not on forms |
| `canSee(Closure)` | `fn (Request $request): bool`; the field exists only for users who are allowed |

The `hide*` and `show*` methods accept `bool|Closure` (`true` by default). The closure has the signature
`fn (Request $request, ?Model $record): bool`. `hide*` hides the field when the closure returns `true`;
`show*` shows it. **On index, `$record` is `null`**: visibility there is decided per request, not per row.

```php
Text::make('Нотатка', 'note')
    ->hideWhenUpdating(fn (Request $request, ?Model $post) => $post?->status === 'live');
```

### Read-only

`readonly(bool|Closure $readonly = true)`: the closure has the signature `fn (Request $request, Model $record): bool`
(when creating, `$record` is a new model).

```php
Select::make('Статус', 'status')->options(['draft' => 'Чернетка', 'live' => 'Опубліковано'])
    ->readonly(fn (Request $request, Model $post) => $post->status === 'live');
```

### Security

A hidden field, a `readonly` field and a field denied by `canSee` are **skipped on the server** during
filling and validation: a value for such a field sent by hand is ignored. A field whose `canSee` returned
`false` is absent from every context (columns, detail, forms, filling, validation, sorting).

### Where each field is shown

| Field / setting | index | detail | create | update |
| --- | --- | --- | --- | --- |
| a regular field | yes | yes | yes | yes |
| `ID` | yes | yes | no | no |
| `Textarea` | no | yes | yes | yes |
| `Password` | no | no | yes | yes |
| `exceptOnForms()` | yes | yes | no | no |
| `onlyOnForms()` | no | no | yes | yes |
| `HasMany`, `BelongsToMany` | no | yes (panel) | no | no |
| `canSee()` returned `false` | no | no | no | no |

Example (`app/Larsadmin/User.php`):

```php
DateTime::make('Створено', 'created_at')->exceptOnForms()->sortable(),
```

## List columns

| Method | Description |
| --- | --- |
| `width(string\|int)` | width: an `int` is pixels; a string like `120px`, `20%`, `12rem`, `10em`, `8ch`. Other units and formats throw an `InvalidArgumentException` |
| `align('left'\|'center'\|'right')` | alignment; `left` by default, `right` for `Number`, `center` for `Boolean` |
| `wrap()` | allow text to wrap in the cell |
| `truncate(?int $chars = null)` | cut long text with an ellipsis: after `$chars` characters (at least 1), or at the column width when `null` |
| `hideByDefault()` | the column is hidden until the user turns it on in the "Columns" menu ([List UI](11-ui.md)) |

`wrap()` and `truncate()` are mutually exclusive: the one called last wins.

## Grouping fields

Fields on forms and the detail page can be grouped into panels (`Layout\Panel`) and tabs
(`Layout\Tabs`). `fullWidth()` stretches a field across both columns of a two-column panel. See
[Field blocks](12-layout.md).

## Combined example

```php
public function fields(Request $request): array
{
    return [
        ID::make()->sortable()->width(70),
        Text::make('Заголовок', 'title')->sortable()->truncate(60)->rules('required', 'max:255'),
        Textarea::make('Текст', 'body')->nullable()->wrap(),
        Select::make('Статус', 'status')->sortable()->width('9rem')
            ->options(['draft' => 'Чернетка', 'live' => 'Опубліковано'])
            ->readonly(fn (Request $request, Model $post) => $post->status === 'live'),
        Number::make('Перегляди', 'views')->min(0)->default(0)->sortable()->align('center'),
        Text::make('Внутрішній код', 'code')->nullable()->hideByDefault()->hideWhenCreating(),
        Text::make('Нотатка модератора', 'moderator_note')->nullable()
            ->canSee(fn (Request $request) => $request->user()->can('moderate')),
        DateTime::make('Створено', 'created_at')->exceptOnForms()->sortable(),
    ];
}
```

## Validation

```php
Text::make('Email', 'email')->inputType('email')->sortable()
    ->rules('required', 'email', 'max:255', 'unique:users,email,{{id}}'),
```

| Method | Applies to |
| --- | --- |
| `rules(...)` | create and update |
| `creationRules(...)` | create only |
| `updateRules(...)` | update only |

Arguments: strings, rule objects, closures, or a single array. Each call **replaces** the set given by
an earlier call of the same method.

### Pipe-delimited rules (`|`)

Strings containing `|` are split into separate rules: `rules('required|max:255')` is equivalent to
`rules('required', 'max:255')`. The exception: a string that contains `regex:` or `not_regex:` is not
split, because the pattern may contain `|`. Pass such rules as separate arguments:

```php
Text::make('Код', 'code')->rules('required', 'regex:/^(a|b)\d+$/');
```

### The `{{id}}` placeholder

In string rules, `{{id}}` is replaced with the current record's key, and with `NULL` when creating.
This gives you `unique` without separate rules for editing:

```php
Text::make('Слаг', 'slug')->rules('required', 'max:255', 'unique:categories,slug,{{id}}'),
```

### Implicit type rules

Rules that follow from the field type are added automatically before your rules:

| Field | Implicit rules |
| --- | --- |
| `Text`, `Textarea`, `Password` | `nullable`, `string` |
| `Number` | `numeric`, plus `min:N` / `max:N` from `min()` / `max()` |
| `Boolean` | `boolean` |
| `Date`, `DateTime` | `date` |
| `Select` | `Rule::in(...)` over the option keys |
| `BelongsTo` | `required` (or `nullable`) and the `RelatableExists` check |
| `File`, `Image` | rules for each uploaded file, see [Files and images](15-files.md#validation-and-limits) |

For `Number`, `Boolean`, `Date` and `Select`, the `nullable` rule is added only when `nullable()` is called.
So `Date::make(...)` without `nullable()` requires a value even without an explicit `required`.

Order: implicit rules, `rules()`, then `creationRules()` or `updateRules()`.

### Password: different rules for create and update

Example (`app/Larsadmin/User.php`):

```php
Password::make('Пароль', 'password')
    ->creationRules('required', 'min:8')
    ->updateRules('nullable', 'min:8')
    ->help('Залиште порожнім, щоб не змінювати пароль.'),
```

### Messages

Validation messages come from `lang/{locale}/validation.php`, and field names from the field labels.
`larsadmin:install` installs the Ukrainian messages ([Localization](08-i18n.md)).

## Custom fields

A custom field is a subclass of `Field` (or `Text`, `Number`, …). Since 1.5 its protected hooks
(`resolveValue()`, `formatForDisplay()`, `fillValue()`, `fillString()`, `typeRules()`, `implicitRules()`,
`acceptsNullInput()`, `defaultAlign()`), public hooks (`fill()`, `fillInto()`, `fillAttribute()`,
`resolve()`, `eagerLoads()`, `applySort()`, `bindTo()`, `hasAuditValue()`, `auditValue()`), `withMeta()`,
`jsonSerialize()` and `$component` are stable API. A field with a built-in component is described in
[Extending](10-extending.md#a-field-with-a-built-in-component), a field with its own Vue component,
including a field that uploads files (`multipart()`), in [Extensions: the public API](19-extensions.md#custom-fields).

Next: [Relations](04-relations.md).
