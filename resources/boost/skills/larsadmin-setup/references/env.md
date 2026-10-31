# LARSADMIN_* environment variables

Every variable the package reads, the config key it sets ([config.md](config.md)) and its default.

| Variable | Config key | Default | Notes |
| --- | --- | --- | --- |
| `LARSADMIN_NAME` | `larsadmin.name` | `APP_NAME` + " Admin" | quote values with spaces |
| `LARSADMIN_DOMAIN` | `larsadmin.domain` | none | rebuild the route cache after a change |
| `LARSADMIN_PATH` | `larsadmin.path` | `admin` | rebuild the route cache after a change |
| `LARSADMIN_GUARD` | `larsadmin.guard` | default guard | also used by the `viewLarsadmin` gate |
| `LARSADMIN_LOCALE` | `larsadmin.locale` | `uk` | `en` for an English panel |
| `LARSADMIN_TIMEZONE` | `larsadmin.timezone` | `app.timezone` | metric periods |
| `LARSADMIN_ALLOW_LOCAL` | `larsadmin.allow_local` | `true` | set `false` on every shared or public server |
| `LARSADMIN_QUEUE` | `larsadmin.actions.queue` | connection default | queued actions |
| `LARSADMIN_QUEUE_CONNECTION` | `larsadmin.actions.queue_connection` | `queue.default` | queued actions |
| `LARSADMIN_RUNS_CONNECTION` | `larsadmin.actions.runs_connection` | default DB connection | run progress table |
| `LARSADMIN_NOTIFICATIONS` | `larsadmin.notifications.enabled` | `false` | bell and queued actions |
| `LARSADMIN_REALTIME` | `larsadmin.notifications.realtime` | `auto` | `auto`, `true`, `false` |
| `LARSADMIN_CHANNEL_PREFIX` | `larsadmin.notifications.channel_prefix` | `larsa_` | lowercase, ends with `_` |
| `LARSADMIN_REALTIME_HOST` | `larsadmin.notifications.realtime_client.host` | none | public WebSocket host |
| `LARSADMIN_REALTIME_PORT` | `larsadmin.notifications.realtime_client.port` | none | public WebSocket port |
| `LARSADMIN_REALTIME_SCHEME` | `larsadmin.notifications.realtime_client.scheme` | none | public WebSocket scheme |
| `LARSADMIN_AUDIT` | `larsadmin.audit.enabled` | `false` | run the migration and define `viewLarsadminAudit` first |
| `LARSADMIN_AUDIT_CONNECTION` | `larsadmin.audit.connection` | default DB connection | |
| `LARSADMIN_EXTENSION_SOURCE_MAPS` | `larsadmin.extensions.source_maps` | `false` | debugging only |
| `LARSADMIN_FILES_DISK` | `larsadmin.files.disk` | `local` | default disk of `File`/`Image` |
| `LARSADMIN_MCP` | `larsadmin.mcp.enabled` | none (local only) | `true` allows non-production envs; `false` turns it off |

A production `.env` usually sets at least:

```dotenv
LARSADMIN_ALLOW_LOCAL=false
LARSADMIN_MCP=false
```
