# 20. AI assistants: skills and the MCP server
🇺🇦 [Українська](../20-ai-assistants.md) · 🌐 [larsadmin.dev](https://larsadmin.dev/en/docs/ai-assistants)

Since 1.6 the package ships material for AI assistants that write your application's code (Claude Code,
Cursor, VS Code and others): a **guideline** with the package's rules, **15 skills** with recipes for each
area, and a local **MCP server** `larsadmin` through which the assistant searches this guide, sees the
entities registered in the application and generates classes with the same generators as
`php artisan larsadmin:*`.

All of it is **optional**. The panel has no new required dependencies and works exactly as in 1.5 without the
AI tooling. The AI gets no access to record data: the server returns only code metadata and guide text.

## What ships

| What | Where in the package | What for |
| --- | --- | --- |
| Guideline | `resources/boost/guidelines/core.md` | in short: what Larsadmin is, the mandatory rules, a "task → skill" table, how to use MCP. It is loaded into every session, so it is short (at most 80 lines) |
| Skills | `resources/boost/skills/larsadmin-*/SKILL.md` (+ `references/*.md`) | task instructions: when to use, recipes, an API cheat sheet, security rules, pitfalls, links to the full guide. The assistant loads a skill only when it takes on the matching task |
| MCP server | `php artisan larsadmin:mcp` | 8 tools: searching and reading the guide, application introspection, class generation ([Tools](#tools)) |
| The `larsadmin:ai` command | | installs the skills, the MCP server entry and the guideline without Laravel Boost ([Without Boost](#installing-without-boost-larsadminai)) |

The skills and the guideline are in English. They link to the English guides of the installed package
version (`vendor/maxcxam/larsadmin/docs/guide/en/NN-name.md`), so the assistant reads the documentation of
exactly your version.

| Skill | Area | Guide |
| --- | --- | --- |
| `larsadmin-setup` | installation, configuration, upgrades, AI tooling | [01](01-installation.md), 20 |
| `larsadmin-entities` | entities, queries, the generator | [02](02-entities.md) |
| `larsadmin-fields-layout` | fields, validation, columns, panels and tabs | [03](03-fields.md), [12](12-layout.md) |
| `larsadmin-relations` | `BelongsTo`, `HasMany`, `BelongsToMany` | [04](04-relations.md) |
| `larsadmin-authorization-security` | gate, policies, scoping, the security checklist | [05](05-authorization.md) |
| `larsadmin-filters` | built-in and custom filters | [06](06-filters.md) |
| `larsadmin-actions` | actions, action fields, background actions | [07](07-actions.md), [18](18-notifications.md) |
| `larsadmin-metrics-dashboards` | metrics, cards, dashboards | [13](13-metrics.md), [14](14-dashboards.md) |
| `larsadmin-files` | `File`, `Image`, disks, `FileSafety` | [15](15-files.md) |
| `larsadmin-global-search` | global search, Scout | [16](16-search.md) |
| `larsadmin-audit-log` | the audit log | [17](17-audit-log.md) |
| `larsadmin-notifications-realtime` | the bell, notifications, Reverb/Pusher | [18](18-notifications.md) |
| `larsadmin-ui-i18n-theming` | languages, translations, themes, the list UI | [08](08-i18n.md), [09](09-theming.md), [11](11-ui.md) |
| `larsadmin-extending` | extensions, custom fields, cards, tools, the JS SDK | [10](10-extending.md), [19](19-extensions.md) |
| `larsadmin-testing` | tests of the panel and of extensions | [21](21-testing.md) |

## Installing with Laravel Boost

[Laravel Boost](https://github.com/laravel/boost) finds the guidelines and skills of the packages your
application depends on directly:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

In `boost:install`, select `maxcxam/larsadmin` among the third-party packages. The guideline goes into your
assistant's guidelines file (`CLAUDE.md`, `AGENTS.md` and so on), the skills are copied into the assistant's
skills directory (for Claude Code, `.claude/skills/larsadmin-*`).

- **Skills need Boost 2.x** (tested with 2.4 and 2.10). Boost 1.x installs the guideline only.
- Boost sees **direct** dependencies only: `maxcxam/larsadmin` must be in the `require` section of your
  `composer.json`, not only a dependency of another package (an add-on, say).
- After `composer update`, refresh the files with `php artisan boost:update`.
- Boost already puts the guideline into `CLAUDE.md`: do **not** add the import line (`larsadmin:ai --claude-md`).

Without interactive questions (`--no-interaction`, in a script or CI, say) `boost:install` takes the agents
and packages from `boost.json` in the application root:

```json
{"agents":["claude_code"],"packages":["maxcxam/larsadmin"]}
```

```bash
php artisan boost:install --no-interaction
```

**Exclude the `inertia-laravel/core` guideline.** Inertia comes into the application as a dependency of the
panel, so Boost adds its Inertia guideline as well. It can push the assistant to write Inertia pages in your
application, although the panel's pages already ship in the package. `config/boost.php` does not exist until
you publish it:

```bash
php artisan vendor:publish --tag=boost-config
```

```php
// config/boost.php
'guidelines' => [
    'exclude' => ['inertia-laravel/core'],
],
```

Then run `php artisan boost:update`. `skills.exclude` turns off single skills the same way. The
`boost:install` summary shows the guideline names to use in `exclude`.

Boost adds only its own server to `.mcp.json`. Add the `larsadmin` server separately:

```bash
composer require laravel/mcp --dev
php artisan larsadmin:ai --mcp
```

## Installing without Boost: `larsadmin:ai`

Pick **one** way to install and update the skills: Boost (`boost:install`, `boost:update`) or
`larsadmin:ai --skills`. With Boost you need `larsadmin:ai` only for `--mcp`.

```bash
php artisan larsadmin:ai [--skills] [--mcp] [--claude-md] [--path=.claude/skills] [--force]
```

Without options the command asks what to install (skills and MCP are preselected). With `--no-interaction`
and no options it runs `--skills --mcp`. The command needs neither Boost nor `laravel/mcp`.

| Option | What it does |
| --- | --- |
| `--skills` | copies the package's `larsadmin-*` skills to `{path}/larsadmin-*` (`.claude/skills` by default). Each directory gets a `.larsadmin-skill` marker (the package version and a hash of the contents) |
| `--mcp` | adds the `larsadmin` server to `.mcp.json` in the application root; other servers and keys of the file are kept. Prints the same snippet for other clients. Without `laravel/mcp` it warns that the server will not start |
| `--claude-md` | appends the guideline import line to the end of `CLAUDE.md`, only when it is not there yet; your text is not changed. Without the option the command only prints a tip with that line |
| `--path=` | the skills directory, relative to the application root |
| `--force` | replaces skill directories changed by hand or not created by this command, and an existing `larsadmin` entry in `.mcp.json` |

The `.mcp.json` entry (Claude Code format):

```json
{
    "mcpServers": {
        "larsadmin": {
            "command": "php",
            "args": ["artisan", "larsadmin:mcp"]
        }
    }
}
```

The line `--claude-md` adds to `CLAUDE.md`:

```text
@vendor/maxcxam/larsadmin/resources/boost/guidelines/core.md
```

The command writes only inside the application root: a `--path` that leads outside it or through a symlink
(or a junction on Windows) is refused before any file is written. When `.mcp.json` is not a JSON object, has a
duplicate key or a number PHP cannot keep exactly (an integer beyond the native range, an over-long decimal),
the file is left unchanged: the command fails and prints the entry to add by hand.

### Other MCP clients

The server speaks stdio, so any MCP client (Cursor, VS Code, Windsurf and others) starts it with the same
command: `php artisan larsadmin:mcp` in the application root. Copy the snippet `larsadmin:ai --mcp` prints
into your client's configuration. The skills use the Agent Skills format (`SKILL.md`) and can go to your
client's skills directory through `--path`.

## The MCP server

The `larsadmin` server is built on the [`laravel/mcp`](https://github.com/laravel/mcp) package. The panel does
not depend on it; install it as a dev dependency:

```bash
composer require laravel/mcp --dev
```

`laravel/mcp` `^0.7.1` … `^1.0` is supported. It needs Laravel 12.41.1+ or 13.

The transport is **stdio** only: the client starts `php artisan larsadmin:mcp` itself as a child process.
There is no HTTP endpoint, so the server cannot be reached over the network. The command writes errors to
stderr, because stdout is the MCP channel.

### When the server runs

| `APP_ENV` | `LARSADMIN_MCP` not set (`null`) | `LARSADMIN_MCP=true` | `LARSADMIN_MCP=false` |
| --- | --- | --- | --- |
| `local` | runs | runs | off |
| anything but `production` (`staging`, `testing`, a dev container) | off | runs | off |
| `production`, `prod`, `live` (any case) | **never** | **never** | **never** |

Production is detected both by `app()->isProduction()` and by the environment names `production`, `prod` and
`live`, case-insensitively.

The config key is `larsadmin.mcp.enabled` (`env('LARSADMIN_MCP')`). The strings `true`/`false`/`1`/`0`/`on`/`off`
are recognised; an unrecognised value counts as off.

The check runs in three places: when the server is registered, in the `larsadmin:mcp` command and at the
start of every tool. So starting the server around the command (`php artisan mcp:start larsadmin`) or a
config cached in another environment does not open it in production. When the check fails,
`larsadmin:mcp` exits with code 1 and prints the reason, for example:

```text
The larsadmin MCP server runs only when APP_ENV=local; set LARSADMIN_MCP=true to run it in the [staging] environment (never in production).
```

Without `laravel/mcp` the command prints `The larsadmin MCP server needs the laravel/mcp package: composer require laravel/mcp --dev`.

## Tools

The server has 8 tools. Seven only read (`readOnly`, `idempotent`). `generate` is marked `destructive` (with
`force: true` it overwrites files) and writes files only when asked to explicitly. Each returns structured JSON of at most 50,000 characters; longer lists are cut with
`"truncated": true`. Errors are returned as text, without a stack trace and without values.

| Tool | Input | Returns |
| --- | --- | --- |
| `search-docs` | `query` (2–200 characters), `limit` (1–10, 5), `page` (optional) | the best matching guide sections with an excerpt |
| `read-docs` | `page`, `section` (optional) | the page's table of contents and a section's Markdown |
| `application-info` | | versions, panel settings (allow-listed), features, registration counts |
| `list-entities` | | the registered entities in short |
| `describe-entity` | `entity` (`uriKey` or class) | the structure of one entity |
| `list-dashboards` | | the dashboards and their cards |
| `list-extensions` | | extensions and tools |
| `generate` | `kind`, `name`, `options`, `write`, `force` | the plan of files; with `write: true` writes them |

### `search-docs`

Full-text search (BM25) over the English guides of the installed package copy (`docs/guide/en`), by `##` and
`###` sections. Identifiers are found both whole (`larsadmin:entity`, `LARSADMIN_PATH`, `relatableQuery`) and in
parts. `page` limits the search to one page (`03-fields`); an unknown page gives an error with the list.

```json
{
  "version": "1.6.0",
  "results": [
    { "page": "04-relations", "title": "Relations", "section": "Which records can be selected",
      "anchor": "which-records-can-be-selected", "score": 12.4, "excerpt": "…" }
  ]
}
```

### `read-docs`

A guide page by its `NN-name` (only from the list of existing pages; no path from the client is opened).
Without `section` it returns the table of contents and the start of the page; with `section` (an anchor or a
heading) the section with its subsections: `{ page, title, sections: [{ title, anchor, level }], content,
truncated }`. The content is cut at 40,000 characters.

### `application-info`

Versions (`larsadmin`, `apiVersion`, `laravel`, `php`), panel settings (`name`, `path`, `domainSet`, `guard`,
`locale`, `locales`, `timezone`, the files disk name, the actions queue name), enabled features (`audit`,
`notifications`, `realtime`, `scout`, `globalSearch`), the number of entities, dashboards, tools and
extensions, `mcp.writesAllowed`, and where the provider and the `app/Larsadmin` directory are.

The configuration is read through an explicit list of keys. Never returned: the domain (only
`domainSet: true|false`), realtime hosts, ports and keys, connections, any keys, passwords and tokens.

### `list-entities` and `describe-entity`

`list-entities` returns, for each entity, `key`, `class`, `file`, `model`, `label`, `singularLabel`, `group`,
`inNavigation`, `globallySearchable`, `auditable` and the number of fields, actions, filters and cards.

`describe-entity` (input `entity`: the `uriKey` or class of a registered entity) returns the static
properties, whether `indexQuery`/`detailQuery`/`relatableQuery` are overridden, the policy class and file,
the fields, layout blocks, actions (with their fields), filters, cards and warnings: no policy,
`$globallySearchable` without `$search`, filters without an overridden `indexQuery`.

Introspection sees what is visible **without a user**. Prototypes of fields, actions, filters and cards are
built with a synthetic request without authentication, and the server **never**:

- runs closures (`canSee`, `canRun`, visibility, `readonly`): it reports that a closure is there instead
  of a result. In a field's `contexts` and `readonly` that is the string `"closure"`, while a field's `canSee`
  is a flag: `true` means the field has a `canSee` closure (it was not run, so visibility for a given user is
  unknown), `false` means there is none. In actions and filters, `canSee`/`canRun` are `"closure"`,
  `"overridden"` (the method is overridden in the class) or `"default"`;
- calls `resolve()`, `calculate()`/`data()` of cards, `Tool::props()`, `options()` of filters or any model
  query;
- returns values, `default`, `placeholder`, `help`, `withMeta()` or `confirmText`;
- returns `Select` options (only `{ source, count }`) or rule parameters that may be data. Numeric parameters are
  shown only for size and bound rules (`min`, `max`, `size`, `between`, `digits`, `digits_between`, `decimal`,
  `gt`, `lt` and so on), formats in full (`date_format`, `mimes`, `mimetypes`, `extensions`); `in`, `not_in`,
  `exists`, `unique`, `regex`, `required_if` and the others are given by name only, even with numeric
  parameters (they may be record IDs).

Three keys describe a field's validation rules:

| Key | What it holds |
| --- | --- |
| `declaredRules` | `{ create, update }`: the rules from `rules()` together with `creationRules()` / `updateRules()` |
| `rules` | `{ create, update }`: the complete rules the panel validates with (the field type's implicit rules first, then the declared ones), or `null` |
| `rulesComputed` | `true` when `rules` could be computed without host code |

`rulesComputed: false` and `rules: null` occur for extension fields, `Select` (it builds `in` from its
options), `File`/`Image` and fields with an overridden `rulesFor()`: the implicit rules of such fields are not
run. Read the field's documentation then. Relation panels (`HasMany`, `BelongsToMany`) are not validated: all
three keys are `null` / `false` there. A leading implicit `nullable` in `rules` only means the field accepts
empty input.

When `fields()`, `actions()`, `filters()` or `cards()` of your entity throws without a user (it calls
`$request->user()->…`, say), the response holds
`"unavailable": [{ "unavailable": "fields", "reason": "<exception class> without an authenticated user" }]`,
without the exception's message. The rest of the description is returned as usual.

### `list-dashboards` and `list-extensions`

`list-dashboards`: `key`, `class`, `file`, `name`, `inNavigation`, `isHome` (the first registered dashboard),
`canSee` and the cards (`uriKey`, `class`, `type`, `width`, …). Metric data is not calculated.

`list-extensions`: extensions (`name`, `path`, `version`, `script`, `style`, `translations`, `package`) and
tools (`uriKey`, `class`, `file`, `name`, `component`, `icon`). `Tool::props()` is not called.

`package` tells where to find the extension's PHP classes: `{ name, namespaces, source }`, where `namespaces`
are the package's PSR-4 namespaces and `source` is `"installed.json"` (from `vendor/composer/installed.json`)
or `"composer.json"` (from the `composer.json` in the extension directory); `null` when no package is found.
The component keys an extension registers in JavaScript are not listed.

### `generate`

Generates classes with the same code as the artisan commands (the same stubs, the same name validation,
byte-for-byte the same output).

| Field | Value |
| --- | --- |
| `kind` | `entity`, `action`, `filter`, `dashboard`, `value`, `trend`, `partition`, `progress`, `field`, `card`, `data-card` (= `larsadmin:card --data`), `tool`, `extension` |
| `name` | a StudlyCase class name, with `/` for sub-namespaces (`Blog/Post`); for `extension` the Composer package name (`acme/larsadmin-rating`) |
| `options` | only the options of the matching command: `entity` `{ model }`; `action` `{ destructive }`; `field`, `card`, `data-card`, `tool` `{ extension }` (the extension package directory); `extension` `{ path, field, card }`. An unknown key gives an error |
| `write` | `false` (default): only the plan; `true`: write the files |
| `force` | overwrite existing files; only together with `write: true` |

Names that are PHP reserved words (`Class`, `List`, `Match` and so on) are refused, as in the artisan commands.

The response:

```json
{
  "kind": "action",
  "written": false,
  "files": [
    { "path": "app/Larsadmin/Actions/MarkLive.php", "status": "create", "contents": "<?php …" }
  ],
  "nextSteps": ["Return it from an entity's actions(Request $request): new \\App\\Larsadmin\\Actions\\MarkLive"]
}
```

`status`: `create` (no such file), `exists` (the file exists and stays unchanged without `force`),
`overwrite` (the file existed and was overwritten with `force: true`). `contents` is returned in the plan
only (`write: false`).

The usual flow: the assistant calls `generate` without `write`, shows you the plan, and only after you agree
calls it again with `write: true`. The writing rules:

- **all or nothing**: when any file already exists and `force` is not set, nothing is written, and
  `nextSteps` explains how to overwrite. Every path of the plan is checked before the first file is written;
- application classes are written only to `app/Larsadmin/**` (the Vue components of application fields,
  cards and tools to `resources/larsadmin/js`), an extension package only to the `path` directory
  (`packages` by default), which must be below `packages/` or `extensions/` of the application root. The same
  goes for the `extension` option of fields, cards and tools;
- never into `vendor`, `node_modules`, `storage`, `bootstrap`, `public` or directories starting with a dot;
  path segments of dots only or ending in a dot are refused;
- a path through a symlink (a dangling one included) or outside the application root is refused;
- never in production;
- no `composer`, `npm` or any other code execution: only files from stubs. `nextSteps` says what to run next;
- every write is logged at `info` level with the message `larsadmin.mcp.generate` and the file paths.

The assistant changes existing code (adding a field to an entity and so on) with its own means: `generate`
only creates new files.

## Security model

- The server runs locally only (stdio) and **never in production** ([When the server runs](#when-the-server-runs)).
- No tool reads database records or runs host closures, SQL, artisan or arbitrary code.
- Responses are built only from explicit lists of allowed keys; configuration secrets and exception messages
  never get into them.
- Only `generate` writes files, only with `write: true` and only inside the application.
- The skills and the guideline are plain text files: they give the assistant no rights it did not have.

Introspection shows the assistant the structure of the admin panel, the policies and the validation rules.
Do not turn the server on (`LARSADMIN_MCP=true`) in shared environments where other people can reach the
terminal.

## Keeping the skills up to date

The skills have the same version as the package. After `composer update maxcxam/larsadmin`, refresh them:

- with Boost: `php artisan boost:update`;
- without Boost: `php artisan larsadmin:ai --skills`.

`larsadmin:ai` replaces only directories with a `.larsadmin-skill` marker whose contents did not change since
they were installed. A directory you edited or created yourself is skipped with a warning
(`it was changed by hand`); `--force` replaces it as well. To keep your own changes, put them in a separate
skill with a different name.

The guideline imported through `CLAUDE.md` (without Boost only) is read straight from `vendor/` and is updated
with the package. With Boost, `boost:update` refreshes the guideline.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| The client says the `larsadmin` server failed to start or was not found | run `php artisan larsadmin:mcp` by hand: the command prints the reason to stderr. Most often `APP_ENV` is not `local` (set `LARSADMIN_MCP=true`) or `laravel/mcp` is missing |
| `needs the laravel/mcp package` | `composer require laravel/mcp --dev` |
| `composer require laravel/mcp` does not install on Laravel 12 | Laravel 12.41.1+ is needed: `composer update laravel/framework` |
| `LARSADMIN_MCP` was changed but nothing happened | the configuration is cached: `php artisan config:clear` |
| `never runs in production` | by design; work with a local copy of the application |
| `boost:install` does not offer `maxcxam/larsadmin` or installs no skills | the package is not in the application's `require`, or Boost 1.x (skills need 2.x) |
| `larsadmin:ai` prints `Skipped … changed by hand` | the skill directory was edited by hand: `--force` replaces it |
| `larsadmin:ai` prints `Skipped … it was installed by Laravel Boost` | Boost installed the skills: update them with `php artisan boost:update`, or pass `--force` to manage them with `larsadmin:ai` from now on |
| `larsadmin:ai` prints `Skipped … not created by larsadmin:ai` | the directory was not created by this command (by hand or another tool): `--force` replaces it |
| The assistant writes Inertia pages or Vue components in the application for ordinary panel tasks | Boost added the `inertia-laravel/core` guideline: exclude it in `config/boost.php` ([with Boost](#installing-with-laravel-boost)) |
| `Skipped .mcp.json: the "larsadmin" server is already configured` | the entry exists; `--force` overwrites only that entry |
| `The file [.mcp.json] has the duplicate key …` or `… has the number …, which cannot be kept exactly` | rewriting the file would lose data; add the printed `larsadmin` entry by hand |
| `generate`: `must be below packages/ or extensions/` | MCP creates extensions only in `packages/` or `extensions/`; for another directory use `php artisan larsadmin:extension --path=…` |
| `describe-entity` returns `unavailable` | the entity's `fields()`/`actions()`/`filters()`/`cards()` fail without a user; make the code cope with `$request->user() === null`, or ignore it: the rest of the description is there |
| `generate` returns `written: false` with `already exist` | the files exist; call it with `write: true` and `force: true` if you really want to overwrite them |
| `would be generated outside app/Larsadmin` | `name` starts with the root namespace (`App/Models/…`); give the name relative to `app/Larsadmin` (`Post`, `Blog/Post`) |

Next: [Testing](21-testing.md).
