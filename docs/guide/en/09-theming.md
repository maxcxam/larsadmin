# 09. Themes
🇺🇦 [Українська](../09-theming.md)

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

## Customization

There is no public API for changing the theme yet: the package ships with prebuilt CSS (`dist/app.css`,
published to `public/vendor/larsadmin`), and `larsadmin:publish` overwrites the assets. The tokens
above are documented for reference and for working on the package source (`resources/css/app.css`).

You can add your own styles with your own template. The panel's root template is a regular
`larsadmin::app` view, so you can override it with the standard Laravel mechanism
(`resources/views/vendor/larsadmin/app.blade.php`) and include your CSS after `app.css`.
This is not a supported API: the CSS structure and token names may change between versions.

Next: [Extending](10-extending.md).
