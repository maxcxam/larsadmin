---
name: larsadmin-ui-i18n-theming
description: "Larsadmin UI, translations and theming: panel locales and the locale resolver, translated labels, date formats, themes and design tokens, list columns and responsive layout. Use when translating the panel, changing its language, look or colors in a Laravel app that uses maxcxam/larsadmin."
license: MIT
metadata:
  package: maxcxam/larsadmin
  version: "1.6"
---

# Larsadmin UI, translations and theming

## When to use

- Change the panel language, add a language, take it from the user profile, or translate package strings.
- Translate entity, field, filter and action labels; change date formats.
- Understand the light/dark/system theme, the `--la-*` design tokens and what you may customize.
- Explain list columns, row menus and responsive behaviour to a user.

## Quick start

The panel ships `uk` (default) and `en`. To make English the default for the panel only:

```dotenv
LARSADMIN_LOCALE=en
```

The language applies to panel routes only. It is resolved in this order, first non-empty value wins:

1. the closure from `Larsadmin::resolveLocaleUsing()`;
2. the user's choice in the session (the header switcher), accepted only when listed in `larsadmin.locales`;
3. `larsadmin.locale` (`LARSADMIN_LOCALE`, `uk` by default);
4. the application language (`app()->getLocale()`); this is also what `larsadmin.locale = null` means.

## Recipes

### Take the language from the user profile

```php
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;

// in boot() of App\Providers\LarsadminServiceProvider
Larsadmin::resolveLocaleUsing(fn (Request $request) => $request->user()?->locale);
```

The value is **not** checked against `larsadmin.locales`: return supported codes only. `null` or `''` falls
through to the next step.

### Restrict or extend the switcher

```php
// config/larsadmin.php: only English and German in the switcher
return [
    'locale' => env('LARSADMIN_LOCALE', 'en'),
    'locales' => [
        'en' => 'English',
        'de' => 'Deutsch', // needs lang/vendor/larsadmin/de, see below
    ],
];
```

`locales` is `code => label` (the shipped default lists `uk` and `en`). The switcher posts to `POST {path}/locale` (30 requests per minute; guests too).

### Change package strings or add a language

```bash
php artisan vendor:publish --tag=larsadmin-lang
```

Files land in `lang/vendor/larsadmin/{uk,en}` (`ui.php`, `messages.php`, `actions.php`, `metrics.php`); keep only
the keys you change. For a new language add `lang/vendor/larsadmin/{code}` with the same files and the code
in `larsadmin.locales`. Missing frontend keys fall back to the bundled Ukrainian copy, then to the key.

`larsadmin:install` copies `lang/uk/validation.php` only when the app has none; English validation messages
come from Laravel.

### Translate entity and field labels

```php
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Text;

class Category extends Entity
{
    public static string $model = \App\Models\Category::class;

    public static ?string $label = 'Categories';

    public static ?string $singularLabel = 'Category';

    public function fields(\Illuminate\Http\Request $request): array
    {
        return [Text::make(__('Name'), 'name')];
    }
}
```

- `$label` and `$singularLabel` go through `__()`: translate them in `lang/{code}.json`.
- A field name (first argument of `make()`) is shown as is: wrap it in `__()` yourself. For a non-ASCII
  name always pass the attribute as the second argument.
- Default filter and action names, `Filter::make($label)` and filter options go through `__()`; an
  overridden `name()` is shown as is.

### Date formats

`Date` and `DateTime` use the `formats.date` and `formats.datetime` keys of `larsadmin::ui` (`uk`: `d.m.Y`,
`d.m.Y H:i:s`; `en`: `Y-m-d`, `Y-m-d H:i:s`). Per field: `->displayFormat('d/m/Y')`. The panel timezone is
`larsadmin.timezone` (`LARSADMIN_TIMEZONE`).

### Themes and colors

- Modes: system (default), light, dark; the switcher is in the header and on the login page. The choice is
  stored in `localStorage` (`larsadmin.theme`) and applied as `data-theme` on `<html>` before styles load.
- Colors are `--la-*` CSS variables: text `--la-fg-*`, surfaces `--la-canvas-*`, borders `--la-border-*`,
  accent, success, danger, header, overlay and the chart palette `--la-chart-1` ... `--la-chart-8`.
- The tokens and the classes `larsadmin-input`, `larsadmin-btn`, `larsadmin-btn-primary`, `larsadmin-box`,
  `larsadmin-label` and their siblings are a stable 1.x contract for extensions. Extensions style themselves
  with `kit/theme.css` (see `larsadmin-extending`).
- There is no public API to restyle the panel. As an unsupported escape hatch you may override the
  `larsadmin::app` view in `resources/views/vendor/larsadmin/app.blade.php` and load your CSS after
  `app.css`; keep the import map and the `nonce` attributes, and re-compare after every update.

### List columns and responsive behaviour

- The "Columns" menu shows, hides and reorders columns per entity; it is stored in the browser
  (`localStorage` key `larsadmin.columns.<uriKey>`, relation panels `larsadmin.columns.<parent>.<attribute>`),
  never on the server. "Reset" restores the order of `fields()` without `hideByDefault()` columns.
- Initial column look comes from the field: `width()`, `align()`, `wrap()`, `truncate()`, `hideByDefault()`.
- Tables scroll horizontally inside their container; the sidebar becomes a slide-out panel on mobile.
- Row actions (`showInline()`) live in the row's "⋯" menu; global search opens with Ctrl+K / ⌘K.

## API cheat sheet

| Element | Meaning |
|---|---|
| `LARSADMIN_LOCALE` / `larsadmin.locale` | default panel language (`uk`); `null` = application language |
| `larsadmin.locales` | switcher languages, `code => label` |
| `Larsadmin::resolveLocaleUsing(?Closure)` | per-request language, highest priority |
| `php artisan vendor:publish --tag=larsadmin-lang` | publish package strings to `lang/vendor/larsadmin` |
| `larsadmin::ui`, `larsadmin::messages`, `larsadmin::actions`, `larsadmin::metrics` | translation groups |
| `Entity::$label`, `Entity::$singularLabel` | plural and singular labels, through `__()` |
| `->displayFormat('d/m/Y')` | date format of one `Date`/`DateTime` field |
| `--la-*` tokens, `larsadmin-*` classes | stable CSS contract (light and dark) |
| `localStorage` `larsadmin.theme`, `larsadmin.columns.*` | per-browser theme and column choices |

## Security rules

- MUST return only supported language codes from `resolveLocaleUsing()`; never pass raw request input
  (query string, headers) through without an allowlist.
- MUST keep the `nonce` attributes and the import map when overriding the `larsadmin::app` view, or a strict
  CSP blocks extensions.
- NEVER treat hidden columns as access control: the "Columns" menu is a browser preference. Hide data with
  `canSee()` and policies (`larsadmin-authorization-security`).
- NEVER put HTML into translations expecting it to render; the panel escapes text.

## Pitfalls

- The panel's language does not change the rest of the application, and `app()->getLocale()` outside panel
  routes stays as configured.
- A language added to `locales` without `lang/vendor/larsadmin/{code}` files shows Ukrainian fallbacks.
- `larsadmin:publish` overwrites `public/vendor/larsadmin`: never edit the published CSS.
- Tailwind utility classes in the panel's `dist/app.css` are not a contract; do not rely on them in your
  view overrides or extensions.
- Column choices are per browser; clearing storage or private mode resets them.

## Reference

- Guides: [vendor/maxcxam/larsadmin/docs/guide/en/08-i18n.md](vendor/maxcxam/larsadmin/docs/guide/en/08-i18n.md),
  [vendor/maxcxam/larsadmin/docs/guide/en/09-theming.md](vendor/maxcxam/larsadmin/docs/guide/en/09-theming.md),
  [vendor/maxcxam/larsadmin/docs/guide/en/11-ui.md](vendor/maxcxam/larsadmin/docs/guide/en/11-ui.md).
- Field display options: `larsadmin-fields-layout`. Extension styling: `larsadmin-extending`.
- MCP (when connected): `read-docs page=08-i18n`, `read-docs page=09-theming section=design-tokens`.
