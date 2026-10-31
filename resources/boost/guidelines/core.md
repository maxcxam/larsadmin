# Larsadmin

Larsadmin (`maxcxam/larsadmin`) is an admin panel for Laravel built with Inertia and Vue 3. Host code lives in
`app/Larsadmin` and is registered in `App\Providers\LarsadminServiceProvider`.

## Golden rules

- Scaffold with the generators (`php artisan larsadmin:entity`, `larsadmin:action`, `larsadmin:filter`,
  `larsadmin:value`, ...) before writing classes by hand; they produce the conventional namespaces and stubs.
- Never edit `vendor/maxcxam/larsadmin`. Change behaviour through your own classes, config and the extension API.
- Give every Entity model a Laravel Policy. A missing policy method denies; a model without any policy is
  allowed while `larsadmin.allow_without_policy` is `true`, so prefer setting it to `false`.
- Scope rows in `indexQuery()`, `detailQuery()` and `relatableQuery()` of the Entity (tenants, ownership).
  Filters and hidden fields are UI, not a security boundary.
- The host application needs no npm build for the panel: the assets ship prebuilt. Only extension authors
  run npm. Run `php artisan larsadmin:publish` after every package update.
- Read the guide before guessing an API: `vendor/maxcxam/larsadmin/docs/guide/en/NN-*.md` matches the installed
  version. Use only classes and methods documented there.

## Which skill for which task

| Task | Skill |
|---|---|
| Install, configure or upgrade the panel; AI tooling | `larsadmin-setup` |
| Add or change an entity (admin screen for a model) | `larsadmin-entities` |
| Fields, columns, validation, panels and tabs | `larsadmin-fields-layout` |
| Relationships between entities | `larsadmin-relations` |
| Who may see or change what; security review | `larsadmin-authorization-security` |
| Filters on entity lists | `larsadmin-filters` |
| Actions, action modals, background actions | `larsadmin-actions` |
| Metrics, cards and dashboards | `larsadmin-metrics-dashboards` |
| File and image uploads | `larsadmin-files` |
| Global search | `larsadmin-global-search` |
| Audit log | `larsadmin-audit-log` |
| Notifications and realtime updates | `larsadmin-notifications-realtime` |
| Translations, themes and UI | `larsadmin-ui-i18n-theming` |
| Custom fields, cards, tools and extension packages | `larsadmin-extending` |
| Tests for admin panel code | `larsadmin-testing` |

## MCP server

When the `larsadmin` MCP server is connected (`php artisan larsadmin:mcp`; runs in `local`, in other
non-production environments only with `LARSADMIN_MCP=true`, never in `production`/`prod`/`live`), use it instead of guessing:

- `search-docs` (`query`) and `read-docs` (`page` such as `07-actions`, optional `section`) search and read
  the guides of the installed version.
- `application-info`, `list-entities`, `describe-entity` (`entity`: uriKey or class), `list-dashboards` and
  `list-extensions` show what the app registers: fields, rules, actions, filters, cards, policies and warnings.
  They return metadata only, never record data.
- `generate` (`kind`, `name`, `options`) runs the same generators as artisan. It only returns a plan unless
  called with `write: true` (`force: true` overwrites files); review the plan first. Extensions go only below
  `packages/` or `extensions/`.

Without the server, read the guide files directly and run the artisan generators.
