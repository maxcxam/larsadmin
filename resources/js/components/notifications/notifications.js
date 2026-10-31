import { safeUrl } from '../audit/audit'
import { isSafePath } from '../search/palette'

// Framework-free helpers of the bell (badge, URL safety, list merging, run progress, polling delays).

export const LEVELS = ['info', 'success', 'warning', 'danger']
export const ICONS = ['bell', 'check', 'alert', 'info', 'download', 'file', 'history']
export const RUN_STATUSES = ['queued', 'running', 'completed', 'failed', 'cancelled', 'abandoned']
const STATUS_SHAPE = /^[a-z_]{1,20}$/
export const ACTIVE_RUN_STATUSES = ['queued', 'running']

// A queued run nobody picked up after this long shows "Waiting for a queue worker".
export const WAITING_WORKER_AFTER_MS = 60_000
export const MAX_POLL_DELAY_MS = 300_000

// Badge text: the count ("99+" above 99), empty at zero or unknown (null when the table is missing).
export function badgeText(count) {
    const value = Number(count)

    if (!Number.isFinite(value) || value <= 0) return ''

    return value > 99 ? '99+' : String(Math.floor(value))
}

// Same-origin links only: a path ("/…", not "//…" or "/\…") or an absolute http(s) URL on `origin`.
export function safeHref(url, origin = typeof window === 'undefined' ? null : window.location.origin) {
    return safeUrl(url, origin)
}

export function isSafeHref(url, origin) {
    return safeHref(url, origin) !== null
}

// Panel paths are visited with Inertia; other same-origin URLs (host pages, downloads) with a full load.
export function isPanelHref(href, basePath) {
    if (!isSafePath(href) || typeof basePath !== 'string' || basePath === '') return false

    const base = basePath.replace(/\/+$/, '')
    const path = href.split(/[?#]/)[0]

    return base === '' ? true : path === base || path.startsWith(`${base}/`)
}

export function normalizeLevel(level) {
    return LEVELS.includes(level) ? level : 'info'
}

// The icon to draw: the whitelisted `icon`, else the level's icon.
export function levelIcon(level, icon = null) {
    if (ICONS.includes(icon)) return icon

    return { success: 'check', warning: 'alert', danger: 'alert' }[normalizeLevel(level)] ?? 'bell'
}

const UNITS = [
    ['year', 31_536_000],
    ['month', 2_592_000],
    ['week', 604_800],
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
]

// "5 minutes ago" in the panel locale; null for less than a minute ("just now") or an invalid date.
export function relativeTime(iso, locale = 'uk', now = Date.now()) {
    const time = Date.parse(iso)

    if (!Number.isFinite(time)) return null

    const seconds = Math.round((time - now) / 1000)

    if (Math.abs(seconds) < 60) return null

    const [unit, size] = UNITS.find(([, size]) => Math.abs(seconds) >= size)

    try {
        return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(Math.round(seconds / size), unit)
    } catch {
        return new Intl.RelativeTimeFormat('en', { numeric: 'auto' }).format(Math.round(seconds / size), unit)
    }
}

// Full date for the `title` tooltip, in the server's timezone when it is a valid IANA name.
export function absoluteTime(iso, locale = 'uk', timezone = null) {
    const date = new Date(iso)

    if (!Number.isFinite(date.getTime())) return ''

    const options = { dateStyle: 'medium', timeStyle: 'short' }

    try {
        return date.toLocaleString(locale, timezone ? { ...options, timeZone: timezone } : options)
    } catch {
        return date.toLocaleString(locale, options)
    }
}

function compareItems(a, b) {
    const byTime = (Date.parse(b.createdAt) || 0) - (Date.parse(a.createdAt) || 0)

    if (byTime !== 0) return byTime

    return String(b.id) < String(a.id) ? -1 : String(b.id) > String(a.id) ? 1 : 0
}

// Union by id (incoming wins), ordered like the server: created_at desc, id desc. Items without an id are dropped.
export function mergeItems(current, incoming) {
    const byId = new Map()

    for (const item of [...(current ?? []), ...(incoming ?? [])]) {
        if (item && item.id !== undefined && item.id !== null) byId.set(String(item.id), item)
    }

    return [...byId.values()].sort(compareItems)
}

// Items not seen yet in this tab (and, with `since`, created after it): candidates for a toast.
export function newSince(items, knownIds, since = null) {
    const known = knownIds instanceof Set ? knownIds : new Set(knownIds ?? [])
    const after = since ? Date.parse(since) : NaN

    return (items ?? []).filter((item) => {
        if (!item || known.has(String(item.id))) return false
        if (Number.isFinite(after) && !((Date.parse(item.createdAt) || 0) > after)) return false

        return true
    })
}

export function isActiveRun(run) {
    return ACTIVE_RUN_STATUSES.includes(run?.status)
}

// Finished = any well-formed status that is not queued/running (unknown future statuses included).
export function isFinishedRun(run) {
    return typeof run?.status === 'string' && STATUS_SHAPE.test(run.status) && !ACTIVE_RUN_STATUSES.includes(run.status)
}

// Failed, abandoned (stale run given up by the server) or a status this client does not know.
export function isFailedRun(run) {
    return isFinishedRun(run) && !['completed', 'cancelled'].includes(run.status)
}

// Percent done (0..100, integer); null = indeterminate (standalone run, unknown total, still queued).
export function percent(run) {
    const total = Number(run?.total)
    const processed = Number(run?.processed)

    if (run?.status === 'queued' || !Number.isFinite(total) || total <= 0 || !Number.isFinite(processed)) {
        return isFinishedRun(run) ? 100 : null
    }

    return Math.min(100, Math.max(0, Math.floor((processed / total) * 100)))
}

// The i18n key of the run's status line.
export function runStatusKey(run, now = Date.now()) {
    if (!run || typeof run.status !== 'string' || !STATUS_SHAPE.test(run.status)) return 'runs_status_queued'

    if (!RUN_STATUSES.includes(run.status)) return 'runs_status_failed'

    if (run.status === 'running' && run.cancelRequested) return 'runs_cancelling'

    if (run.status === 'queued' && now - (Date.parse(run.createdAt) || now) >= WAITING_WORKER_AFTER_MS) return 'runs_waiting_worker'

    return `runs_status_${run.status}`
}

// Applies a realtime progress signal ({id, status, processed, total}) to a run; unknown values are ignored.
export function applyRunSignal(run, signal) {
    const next = { ...run }

    if (typeof signal?.status === 'string' && STATUS_SHAPE.test(signal.status)) next.status = signal.status

    for (const key of ['processed', 'total']) {
        const value = signal?.[key]

        if (value === null && key === 'total') next.total = null
        else if (Number.isFinite(Number(value)) && value !== null && value !== '') next[key] = Number(value)
    }

    // Completed / failed / cancelled runs cannot be cancelled; others (e.g. abandoned) keep the server's flag.
    if (['completed', 'failed', 'cancelled'].includes(next.status)) next.cancellable = false

    return next
}

// Status polling with exponential backoff after failures, capped at 5 minutes.
export function nextPollDelay(failures, baseMs) {
    const base = Math.max(1000, Number(baseMs) || 60_000)
    const attempts = Math.max(0, Math.floor(Number(failures) || 0))

    return Math.min(MAX_POLL_DELAY_MS, base * 2 ** Math.min(attempts, 16))
}

// Active runs: every 3 s, every 10 s after two minutes; with realtime only a 30 s safety net.
export function runsPollDelay(activeForMs, realtime = false) {
    if (realtime) return 30_000

    return activeForMs >= 120_000 ? 10_000 : 3_000
}

// A signal from the realtime channel: only ids and progress numbers are accepted.
export function parseSignal(payload) {
    if (!payload || typeof payload !== 'object') return null

    const id = typeof payload.id === 'string' || typeof payload.id === 'number' ? String(payload.id) : null

    if (!id || !/^[A-Za-z0-9-]{1,64}$/.test(id)) return null

    if (payload.kind === 'notification') return { kind: 'notification', id }

    if (payload.kind === 'run') {
        return {
            kind: 'run',
            id,
            status: typeof payload.status === 'string' && STATUS_SHAPE.test(payload.status) ? payload.status : null,
            processed: Number.isFinite(Number(payload.processed)) ? Number(payload.processed) : null,
            total: payload.total === null || payload.total === undefined ? null : Number.isFinite(Number(payload.total)) ? Number(payload.total) : null,
        }
    }

    return null
}

// Builds "{base}/{id}[/{suffix}]" with the id percent-encoded.
export function memberUrl(base, id, suffix = null) {
    const url = `${String(base).replace(/\/+$/, '')}/${encodeURIComponent(String(id))}`

    return suffix ? `${url}/${suffix}` : url
}

export function withCursor(url, cursor) {
    if (!cursor) return url

    return `${url}${url.includes('?') ? '&' : '?'}cursor=${encodeURIComponent(cursor)}`
}

// Local mutations win over list responses that were in flight when they happened (CRN-06):
// deleted ids are dropped, read ids and everything created up to `readUntil` (mark all as read) stay read.
export function applyLocalChanges(items, { read = new Set(), deleted = new Set(), readUntil = null } = {}) {
    const until = readUntil ? Date.parse(readUntil) : NaN

    return (items ?? [])
        .filter((item) => !deleted.has(String(item.id)))
        .map((item) => {
            if (item.read) return item

            const markedAll = Number.isFinite(until) && (Date.parse(item.createdAt) || 0) <= until

            return read.has(String(item.id)) || markedAll ? { ...item, read: true } : item
        })
}
