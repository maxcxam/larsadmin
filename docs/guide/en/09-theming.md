# 09. Themes
🇺🇦 [Українська](../09-theming.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/en/docs/theming)

The panel interface is styled after GitHub (Primer) and has three theme modes: **light**, **dark**
and **system**.

## Theme switcher

The switcher is in the panel header and on the login page. Modes:

| Mode | Behavior |
| --- | --- |
| System (default) | the theme follows the OS (`prefers-color-scheme`) |
| Light | always light |
| Dark | always dark |

The choice is stored in the browser's `localStorage` under the `larsadmin.theme` key (value `light` or
`dark`; for the system theme the key is removed). If storage is unavailable (private mode), the choice
lasts until the page is reloaded.

To avoid a "flash", the panel's root template applies the stored theme before the styles load.

### How it works in the markup

The theme is an attribute on `<html>`:

| State | Attribute |
| --- | --- |
| light | `data-theme="light"` |
| dark | `data-theme="dark"` |
| system | no attribute; `@media (prefers-color-scheme: dark)` applies |

## Design tokens

Colors are defined as `--la-*` CSS variables on `:root` (light theme). In the source
`resources/css/app.css` the dark values are written **once**, in the `@variant theme-dark` block (a
custom Tailwind variant, `@custom-variant theme-dark`). At build time it expands into two selectors:
`:root[data-theme="dark"]` (forced dark theme) and `:root:not([data-theme="light"])` inside
`@media (prefers-color-scheme: dark)` (system theme). Components use the tokens through the
`--color-*` Tailwind aliases.

| Group | Variables |
| --- | --- |
| Text | `--la-fg-default`, `--la-fg-muted`, `--la-fg-subtle`, `--la-fg-on-emphasis` |
| Surfaces | `--la-canvas-default`, `--la-canvas-subtle`, `--la-canvas-inset` |
| Borders | `--la-border-default`, `--la-border-muted` |
| Accent | `--la-accent-fg`, `--la-accent-emphasis`, `--la-accent-subtle` |
| Success | `--la-success-fg`, `--la-success-emphasis`, `--la-success-emphasis-hover`, `--la-success-subtle` |
| Danger | `--la-danger-fg`, `--la-danger-emphasis`, `--la-danger-subtle` |
| Other | `--la-neutral-muted`, `--la-header-bg`, `--la-header-fg`, `--la-overlay`, `--la-shadow` |
| Charts | `--la-chart-1` … `--la-chart-8`: the palette of metric slices and lines |

The chart colors are chosen to have a contrast of at least 3:1 against the background of both the
light and the dark theme (WCAG 1.4.11).

A few values for reference (light / dark):

| Token | Light | Dark |
| --- | --- | --- |
| `--la-canvas-default` | `#ffffff` | `#0d1117` |
| `--la-fg-default` | `#1f2328` | `#f0f6fc` |
| `--la-accent-emphasis` | `#0969da` | `#1f6feb` |
| `--la-header-bg` | `#25292e` | `#010409` |

Fonts: `--font-sans` (system stack: `-apple-system`, `Segoe UI`, `Noto Sans`, ...) and `--font-mono`.
The base font size is 14px.

## CSS contract for extensions

Since 1.5 the `--la-*` tokens above and the classes `larsadmin-input`, `larsadmin-input-invalid`,
`larsadmin-checkbox`, `larsadmin-btn`, `larsadmin-btn-primary`, `larsadmin-btn-secondary`,
`larsadmin-btn-danger`, `larsadmin-btn-sm`, `larsadmin-box`, `larsadmin-box-header`, `larsadmin-label`,
`larsadmin-label-success`, `larsadmin-label-accent`, `larsadmin-counter` are a **stable 1.x contract**:
extensions may rely on them. The Tailwind utility classes in `dist/app.css` are not a contract.

An extension builds its own utility classes with the `vendor/maxcxam/larsadmin/kit/theme.css` theme: it
maps Tailwind colours (`text-fg`, `bg-canvas`, `border-default`, `text-danger-fg`, …) onto the `--la-*`
tokens, so the extension's styles follow the light and dark themes. See
[Extensions: styles and theming](19-extensions.md#styles-and-theming).

## Customization

There is no public API for changing the panel's own theme: the package ships with prebuilt CSS
(`dist/app.css`, published to `public/vendor/larsadmin`), and `larsadmin:publish` overwrites the assets.

You can add your own styles with your own template. The panel's root template is a regular
`larsadmin::app` view, so you can override it with the standard Laravel mechanism
(`resources/views/vendor/larsadmin/app.blade.php`) and include your CSS after `app.css`.
This is not a supported API: the CSS structure may change between versions. An overridden template must
keep the import map and the `nonce` attributes of the package's template, or extensions will not load:
after updating the panel, compare it with the package's `resources/views/app.blade.php`.

Next: [Extending](10-extending.md).
