# Larsadmin extension API: JavaScript reference

The `larsadmin` module is provided by the panel through an import map, like `vue` (^3.5) and
`@inertiajs/vue3` (v3). Extensions import these three as bare specifiers and never bundle them. Every
export below is part of the 1.x contract (minors only add). Source of truth:
`vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md` ("The public JS API") and `kit/public-api.json`.

## The `larsadmin` module

| Export | Description |
|---|---|
| `apiVersion` | the panel API string, e.g. `'1.6'` (`Larsadmin::API_VERSION`) |
| `defineExtension(options)` | the default export of the entry module: `{ name, requires, fields, cards, filters, tools, setup }` |
| `useI18n()` | `{ t, tc, locale }`; extension keys `t('acme-rating::not_rated')`, `tc('acme-rating::stars', 3)` |
| `useLarsadmin()` | `{ url(...segments), entityUrl(uriKey, ...segments) }` under the panel path; segments encoded, `.`/`..` refused |
| `useJsonRequest()` | `{ send(method, url, body?, { background }) }` returns `{ ok, status, data, expired? }`; adds `X-XSRF-TOKEN` to same-origin mutations only |
| `useJsonResource()` | `{ status, load(url, { background }), cancel }`; cancels stale requests |
| `useCardData(card, params?)` | `{ status, data, reload }` for a `DataCard`; `params` may be a ref or getter (reloads on change) |
| `useToasts()` | `{ push({ message, type, title?, timeout? }), dismiss(id) }`; `type`: `success`, `info`, `warning`, `error`; no links |
| `useTheme()` | `{ resolved }`: `'light'` or `'dark'` |
| `useNumberFormat()` | `{ format(value, options) }`: `Intl.NumberFormat` options plus `prefix`/`suffix`; `null`/`NaN` give a dash |
| `buildUrl(base, segments)` | pure URL helper |
| `withParams(url, params)` | pure query-string helper |
| `Icon` | `<Icon name="pencil" />`; names in `Icon.NAMES` (same as `Tool::ICONS`); decorative |
| `CardShell` | card frame: props `card`, `status`, `ready`, `empty`, `skeletonClass`; slots `default`, `actions`; event `retry` |
| `ConfirmDialog` | accessible confirmation dialog: `message`, `title`, `confirmLabel`, `cancelLabel`, `danger`; events `confirm`, `cancel` |

Statuses of `useCardData()` / `useJsonResource()`: `idle`, `loading`, `error`, `forbidden`. Of the shared Inertia
props only `larsadmin.locale`, `larsadmin.path` and `user` are a contract. Everything else in the panel's
`resources/js` (`registerField`, `EntityForm`, `Combobox`, ...) is internal and not shipped for import.

## `defineExtension()` options

```js
import { defineExtension } from 'larsadmin'
import '../css/extension.css'
import RatingInput from './fields/RatingInput.vue'
import RatingDisplay from './fields/RatingDisplay.vue'
import TopRatedCard from './cards/TopRatedCard.vue'
import BackupsPage from './tools/BackupsPage.vue'
import BooleanSwitch from './overrides/BooleanSwitch.vue'

export default defineExtension({
    name: 'acme/larsadmin-rating', // equals Larsadmin::extension()
    requires: '^1.6',              // ^MAJOR.MINOR only
    fields: {
        'acme/rating': { form: RatingInput, display: RatingDisplay }, // optional index, detail, panel
        'boolean-field': { form: BooleanSwitch, override: true },     // override of a built-in part
    },
    cards: { 'acme/top-rated': TopRatedCard },
    tools: { 'acme/backups': BackupsPage },
    setup({ app }) {},
})
```

- Keys are `vendor/name` (`^[a-z0-9-]+\/[a-z0-9-]+$`); `larsadmin/` is reserved for first-party packages,
  `app/` for an extension inside the application. The first registration of a key wins.
- Cards and filters override with the object form `{ component, override: true }`.
- An error in `setup()` unloads the whole extension. Without a build step only render functions (`h()`) work.

## Component contracts

| Component | Props / events |
|---|---|
| field `form` | `field`, `modelValue` / `update:modelValue`, `invalid`, `errors`, `errorsByKey`, `describedBy`, `context` (`'create'`/`'update'`), `resourceId`, and since 1.6 `action` (`{ uriKey, entity }` in an action modal, else not passed: declare it with `default: null`). Root element `:id="field.attribute"`, honour `field.readonly` |
| field `display` / `index` / `detail` | `field` (with `value`, `displayedAs`), `context` (`'index'`/`'detail'`) |
| field `panel` | `field`, `entity` (`{ uriKey }`), `record` (`{ id }`); for fields serialized with `panel: true` |
| card | `card` (`component`, `uriKey`, `name`, `width`, `helpText`, `endpoint`, own keys) |
| filter | `filter` (`key`, `label`, `component`, `options`, `value`, `default`), `modelValue` / `update:modelValue` |
| tool | `tool` (`name`, `uriKey`, `component`), `props` (from `Tool::props()`) |

In an action modal the field has `field.inAction === true`, `context` is `'create'`, and `resourceId` is the
record key only when the action runs on exactly one record (not standalone). Overridable built-in keys and
value shapes are listed in the guide section "Overriding built-in components".

## The author kit: `vendor/maxcxam/larsadmin/kit`

The kit ships in the panel's Composer archive; there is no npm package. The skeleton aliases it as
`larsadmin-kit`.

| File | Exports |
|---|---|
| `vite.js` | `larsadmin({ entry = 'resources/js/extension.js', outDir = 'dist' })` (also the default export): the Vite preset. Builds the ES library, keeps `vue`, `@inertiajs/vue3`, `larsadmin` external, and stops the build when a copy of Vue, Inertia or the SDK is bundled. In vitest it points `larsadmin` at `kit/sdk.js` and enables `jsdom` |
| `testing.js` | `withPanel({ locale, translations, path, props })`, `mountField(Component, { field, modelValue, errors, errorsByKey, context, resourceId, action, panel, options })`, `mountDisplay(Component, { field, context, panel, options })`, `mountCard(Component, { card, panel, options })` |
| `theme.css` | Tailwind v4 theme mapping `text-fg`, `bg-canvas`, `border-default`, `text-danger-fg`, ... onto the `--la-*` tokens |
| `check-public-api.mjs` | `node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .` checks an extension uses only the stable surface |

```css
/* resources/css/extension.css */
@import "tailwindcss/utilities";
@import "larsadmin-kit/theme.css";
@source "../js";
```

Stable CSS classes: `larsadmin-input`, `larsadmin-input-invalid`, `larsadmin-checkbox`, `larsadmin-btn`,
`larsadmin-btn-primary`, `larsadmin-btn-secondary`, `larsadmin-btn-danger`, `larsadmin-btn-sm`, `larsadmin-box`,
`larsadmin-box-header`, `larsadmin-label`, `larsadmin-label-success`, `larsadmin-label-accent`,
`larsadmin-counter`. The utility classes of the panel's `app.css` are not a contract.
