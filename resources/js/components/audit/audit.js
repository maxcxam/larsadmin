// Framework-free helpers of the audit log UI (history panel + global page). Covered by audit.test.js.
import { cleanFilters } from '../../filters'
import { isSafePath } from '../search/palette'

const EVENTS = ['created', 'updated', 'deleted', 'action', 'attached', 'detached']

// i18n key of an entry's event; accepts the entry (soft deletes get their own key) or the bare event name.
export function eventKey(entry) {
    const event = typeof entry === 'string' ? entry : entry?.event
    const soft = typeof entry === 'object' && entry !== null && entry.soft === true

    if (event === 'deleted' && soft) return 'audit_event_deleted_soft'

    return EVENTS.includes(event) ? `audit_event_${event}` : 'audit_event_updated'
}

// "#12" for a record without a (visible) title.
export function recordText(record) {
    if (!record) return ''

    const title = record.title

    if (title !== null && title !== undefined && String(title) !== '') return String(title)

    return record.id !== null && record.id !== undefined && record.id !== '' ? `#${record.id}` : ''
}

// The event as text: "Updated", "Action “Publish”", "Attached Tags: News".
export function eventLabel(entry, t) {
    const key = eventKey(entry)

    if (entry?.event === 'action') return t(key, { action: entry.action?.name ?? '' })

    if (entry?.event === 'attached' || entry?.event === 'detached') {
        const related = entry.related ?? {}

        return t(key, { entity: related.entityLabel ?? related.entity ?? '', title: recordText(related) })
    }

    return t(key)
}

// Who made the change, for display (SECA-05): the real system actor (no actor, or `system: true`) is flagged so it
// renders as a badge, and a user carries its id so a user named "System" or after someone else stays distinguishable.
export function actorInfo(entry) {
    const actor = entry?.actor

    if (!actor || typeof actor !== 'object' || actor.system === true) return { system: true, label: null, id: null }

    const id = actor.id !== null && actor.id !== undefined && String(actor.id) !== '' ? String(actor.id) : null
    const label = actor.label !== null && actor.label !== undefined && String(actor.label) !== '' ? String(actor.label) : null

    // Nothing that identifies a user: treated as the system (older payloads without the flag).
    if (id === null && label === null) return { system: true, label: null, id: null }

    return { system: false, label: label ?? `#${id}`, id }
}

// The actor as plain text: "System" for the system, "Alice (#5)" for a user with a known id.
export function actorText(entry, t) {
    const actor = actorInfo(entry)

    if (actor.system) return t('audit_system')

    return actor.id !== null && actor.label !== `#${actor.id}` ? `${actor.label} (#${actor.id})` : actor.label
}

// The event plus the number of changed fields when there are any: "Updated: 3 fields".
export function summary(entry, t, tc) {
    const label = eventLabel(entry, t)
    const count = Array.isArray(entry?.changes) ? entry.changes.length : 0

    return count > 0 ? `${label}: ${tc('audit_changed_fields', count)}` : label
}

// Localised "30 Oct 2026, 14:05" in the panel timezone; an unknown timezone falls back to the browser's,
// an unparsable value is shown as is.
export function formatDateTime(iso, locale, timezone) {
    if (iso === null || iso === undefined || iso === '') return ''

    const date = new Date(iso)

    if (Number.isNaN(date.getTime())) return String(iso)

    const options = { dateStyle: 'medium', timeStyle: 'short' }

    try {
        return new Intl.DateTimeFormat(locale || undefined, timezone ? { ...options, timeZone: timezone } : options).format(date)
    } catch {
        return new Intl.DateTimeFormat(locale || undefined, options).format(date)
    }
}

// Coerces whatever the server sent into a value object ({ kind, ... }); plain scalars are accepted too
// (action field values).
export function toValue(value) {
    if (value === null || value === undefined || value === '') return { kind: 'empty' }
    if (typeof value === 'boolean') return { kind: 'boolean', value }
    if (typeof value !== 'object') return { kind: 'text', text: String(value) }
    if (value.masked === true) return { kind: 'masked' }
    if (typeof value.kind === 'string') return value

    return { kind: 'text', text: JSON.stringify(value) }
}

// Same-origin record links only (a path, or an absolute URL on `origin`); anything else is rendered as text.
export function safeUrl(url, origin = typeof window === 'undefined' ? null : window.location.origin) {
    if (isSafePath(url)) return url
    if (typeof url !== 'string' || !origin || !/^https?:\/\//i.test(url)) return null

    try {
        return new URL(url).origin === origin ? url : null
    } catch {
        return null
    }
}

// Plain-text rendering of a value (also what screen readers get for the visual cells).
export function valueText(raw, t, { locale, timezone } = {}) {
    const value = toValue(raw)
    let text

    switch (value.kind) {
        case 'masked':
            return t('audit_masked')
        case 'empty':
            return t('audit_empty_value')
        case 'boolean':
            text = value.value ? t('yes') : t('no')
            break
        case 'datetime':
            text = formatDateTime(value.value, locale, timezone)
            break
        case 'record':
            text = recordText(value)
            break
        case 'files':
            text = (Array.isArray(value.files) ? value.files : []).map((file) => (typeof file === 'string' ? file : file?.name ?? '')).join(', ')
            if (text === '') return t('audit_empty_value')
            break
        default:
            text = value.text === null || value.text === undefined ? '' : String(value.text)
    }

    return value.truncated === true ? `${text} ${t('audit_truncated')}` : text
}

// Diff rows of an entry: { key, label, masked, old, new } with both sides coerced to value objects.
export function changeRows(entry) {
    const changes = Array.isArray(entry?.changes) ? entry.changes : []

    return changes
        .filter((change) => change && typeof change === 'object')
        .map((change, index) => ({
            key: change.attribute ?? String(index),
            label: change.label ?? change.attribute ?? '',
            masked: change.masked === true,
            old: change.masked === true ? null : toValue(change.old),
            new: change.masked === true ? null : toValue(change.new),
        }))
}

// Action parameters as { label, value } with coerced values.
export function actionFieldRows(entry) {
    const fields = Array.isArray(entry?.action?.fields) ? entry.action.fields : []

    return fields
        .filter((field) => field && typeof field === 'object')
        .map((field, index) => ({
            key: `${index}-${field.label ?? ''}`,
            label: field.label ?? '',
            value: field.masked === true ? { kind: 'masked' } : toValue(field.value),
        }))
}

// Whether an action run deleted the record (meta.record_deleted on the server).
export function recordDeleted(entry) {
    return entry?.recordDeleted === true || entry?.record_deleted === true || entry?.meta?.record_deleted === true
}

// Whether the expanded entry has anything to show.
export function hasDetails(entry, { meta = false } = {}) {
    return changeRows(entry).length > 0
        || actionFieldRows(entry).length > 0
        || recordDeleted(entry)
        || (meta && Boolean(entry?.ip || entry?.userAgent))
}

// Query for the global page visit: cleaned filters (cleared defaults kept as explicit empties) and no page,
// so every filter change starts from the first page.
export function filterQuery(filters, definitions = []) {
    const cleaned = cleanFilters(filters, definitions)

    return Object.keys(cleaned).length ? { filters: cleaned } : {}
}

// PHP serialises an empty array as [], so anything that is not a plain object means "no filters".
export function activeFilterValues(query) {
    const value = query?.filters

    return value && typeof value === 'object' && !Array.isArray(value) ? value : {}
}

// Whether a record history response has the expected shape.
export function isHistoryPage(body) {
    return Boolean(body) && typeof body === 'object' && Array.isArray(body.entries)
}

// Appends a "Show more" response (CRA-14). Returns null when it must be dropped: a malformed body, no entries on
// screen, or a stale request (made for another URL or generation than the panel shows now, or answering another
// page than the one requested). Entries already shown are skipped (offset pages shift when new entries arrive).
export function appendPage(state, request, body) {
    if (!state || !Array.isArray(state.entries) || !request || !isHistoryPage(body)) return null
    if (request.url !== state.url || request.generation !== state.generation || request.page !== state.page + 1) return null

    const answered = Number(body.page)

    if (Number.isFinite(answered) && answered > 0 && answered !== request.page) return null

    const known = new Set(state.entries.map((entry) => entry?.id))
    const added = body.entries.filter((entry) => entry && !known.has(entry.id))

    return { entries: [...state.entries, ...added], added: added.length, page: request.page, hasMore: body.hasMore === true }
}

// The record history URL with ?page=N (or &page=N).
export function pageUrl(url, page) {
    const [path, hash = ''] = String(url).split('#')
    const [base, existing = ''] = path.split('?')
    const params = new URLSearchParams(existing)

    if (page > 1) params.set('page', String(page))
    else params.delete('page')

    const query = params.toString()

    return `${base}${query ? `?${query}` : ''}${hash ? `#${hash}` : ''}`
}
