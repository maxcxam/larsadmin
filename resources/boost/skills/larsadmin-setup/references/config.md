# config/larsadmin.php: every key

Read a key with `config('larsadmin.path')`. Prefer the environment variable when a key has one
([env.md](env.md)); edit the published `config/larsadmin.php` for the rest. The guides are the source of
truth for behaviour: each section names its page.

## Panel

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.name` | `APP_NAME` + " Admin" | header and browser title |
| `larsadmin.domain` | `null` | domain the panel is served on |
| `larsadmin.path` | `admin` | URI prefix; rebuild the route cache after a change |
| `larsadmin.guard` | `null` (default guard) | guard for login, `request->user()` and the gate |
| `larsadmin.locale` | `uk` | panel language; `null` keeps the app locale (guide 08) |
| `larsadmin.locales` | `uk`, `en` | `code => label` map of languages the user can switch to |
| `larsadmin.timezone` | `null` (`app.timezone`) | timezone of metric periods (guide 13) |

## Middleware and access (guide 05)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.middleware` | `['web', HandleInertiaRequests::class]` | every panel route, the login page included |
| `larsadmin.auth_middleware` | `[Authenticate::class, Authorize::class]` | every route behind the login; keep both |
| `larsadmin.allow_without_policy` | `true` | models without a policy: `true` allows, `false` denies (use `false` in production) |
| `larsadmin.allow_local` | `true` | with `APP_ENV=local` skip the `viewLarsadmin` gate (development only) |

## Metrics (guide 13)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.metrics.cache_store` | `null` (default store) | store for metrics using `cacheFor()` |
| `larsadmin.metrics.throttle` | `120` | requests per minute per user to metric endpoints; `null` disables |

## Global search (guide 16)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.search.limit` | `5` | results per entity (clamped 1..20; `Entity::$globalSearchLimit` overrides) |
| `larsadmin.search.min_length` | `2` | shortest searched term (digit-only terms always searched) |
| `larsadmin.search.throttle` | `60` | requests per minute per user; `null` disables |
| `larsadmin.search.scout_candidates` | `50` | keys fetched from Scout before `indexQuery()` applies |
| `larsadmin.search.time_budget_ms` | `3000` | remaining entities are skipped after this budget |

## Actions (guides 07, 18)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.actions.max_all` | `1000` | max records for "all matching" (synchronous); above it 422 |
| `larsadmin.actions.queued_max_all` | `10000` | same, for queued actions |
| `larsadmin.actions.queued_max_active` | `3` | queued or running runs per user |
| `larsadmin.actions.queue` | `null` | default queue of queued actions |
| `larsadmin.actions.queue_connection` | `null` | default queue connection |
| `larsadmin.actions.runs_table` | `larsadmin_action_runs` | table of run progress |
| `larsadmin.actions.runs_connection` | `null` | database connection of that table |
| `larsadmin.actions.stale_after` | `3600` | seconds without a heartbeat (plus 60) before a running run is failed |
| `larsadmin.actions.queued_stale_after` | `86400` | seconds before a still-queued run is failed |

The queue connection's `retry_after` must exceed every queued action's `$timeout`.

## Notifications and realtime (guide 18)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.notifications.enabled` | `false` | the bell and queued actions |
| `larsadmin.notifications.poll_interval` | `60` | seconds between polls without broadcasting (`0`: page loads only) |
| `larsadmin.notifications.per_page` | `15` | bell page size |
| `larsadmin.notifications.retention_days` | `30` | used by `larsadmin:prune-notifications`; `null` keeps all |
| `larsadmin.notifications.throttle` | `120` | requests per minute per user to bell endpoints |
| `larsadmin.notifications.realtime` | `auto` | `auto` (Reverb/Pusher when broadcasting uses them), `true`, `false` |
| `larsadmin.notifications.channel_prefix` | `larsa_` | prefix of the panel's channels; never use it for your own channels |
| `larsadmin.notifications.realtime_client.host` | `null` | public WebSocket host for the browser |
| `larsadmin.notifications.realtime_client.port` | `null` | public WebSocket port |
| `larsadmin.notifications.realtime_client.scheme` | `null` | public WebSocket scheme |

## Audit log (guide 17)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.audit.enabled` | `false` | turn the log on (run the migration first) |
| `larsadmin.audit.table` | `larsadmin_audit_entries` | table name |
| `larsadmin.audit.connection` | `null` | connection; another one than the model's loses atomicity |
| `larsadmin.audit.retention_days` | `90` | used by `larsadmin:prune-audit`; `null` keeps all |
| `larsadmin.audit.store_ip` | `true` | store the client IP |
| `larsadmin.audit.store_user_agent` | `true` | store the user agent |
| `larsadmin.audit.max_value_length` | `1000` | longer values are truncated |
| `larsadmin.audit.per_page` | `25` | page size of the log UI |
| `larsadmin.audit.throttle` | `60` | requests per minute per user; `null` disables |

## Extensions (guide 19)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.extensions.source_maps` | `false` | also serve `*.map` files (debugging only) |
| `larsadmin.extensions.max_age` | `31536000` | browser cache lifetime of versioned extension files, seconds |

## Files (guide 15)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.files.disk` | `local` | default disk of `File`/`Image`; private unless the disk says `visibility => public` |
| `larsadmin.files.directory` | `larsadmin` | uploads go to `{directory}/{entity}/{attribute}` |
| `larsadmin.files.max_size` | `10240` | kilobytes per file |
| `larsadmin.files.max_files` | `10` | cap for `multiple()` fields |
| `larsadmin.files.prune` | `true` | delete replaced/removed files after the commit |
| `larsadmin.files.url_ttl` | `30` | minutes a signed URL of a private file lives |
| `larsadmin.files.throttle` | `600` | downloads per minute per user; `null` disables |
| `larsadmin.files.blocked_extensions` | long list | rejected in any part of a client file name; a backstop, prefer `->types([...])` |

## MCP server (guide 20, AI assistants)

| Key | Default | Meaning |
| --- | --- | --- |
| `larsadmin.mcp.enabled` | `null` | `null`: only `APP_ENV=local`; `true`: any non-production env; `false`: off. Never runs in production |
