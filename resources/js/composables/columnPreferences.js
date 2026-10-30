// Pure helpers for the per-entity column picker (visibility + order).
// Stored shape (localStorage `larsadmin.columns.<key>`): { order: string[], hidden: string[] }

export const storageKey = (key) => `larsadmin.columns.${key}`

// Normalises a server column so older payloads (without the newer keys) keep working.
export function normalizeColumn(column) {
    return {
        width: null,
        align: 'left',
        wrap: false,
        truncate: null,
        hideByDefault: false,
        ...column,
    }
}

// Server columns + saved prefs -> ordered [{ column, visible }].
// Unknown (removed) attributes in the prefs are ignored; columns the prefs have never seen
// keep their server position relative order, appended after the saved ones, with their own default visibility.
export function resolveColumns(columns, saved) {
    const normalized = (columns ?? []).map(normalizeColumn)
    const prefs = saved && typeof saved === 'object' ? saved : null
    const order = Array.isArray(prefs?.order) ? prefs.order.filter((a) => typeof a === 'string') : []
    const hidden = new Set(Array.isArray(prefs?.hidden) ? prefs.hidden : [])
    const byAttribute = new Map(normalized.map((column) => [column.attribute, column]))
    const known = new Set([...order, ...hidden])

    const ordered = []
    const seen = new Set()

    for (const attribute of order) {
        const column = byAttribute.get(attribute)

        if (column && !seen.has(attribute)) {
            seen.add(attribute)
            ordered.push(column)
        }
    }

    for (const column of normalized) {
        if (!seen.has(column.attribute)) ordered.push(column)
    }

    const items = ordered.map((column) => ({
        column,
        visible: prefs && known.has(column.attribute) ? !hidden.has(column.attribute) : !column.hideByDefault,
    }))

    // Never end up with an empty table.
    if (items.length > 0 && !items.some((item) => item.visible)) items[0].visible = true

    return items
}

export function serialize(items) {
    return {
        order: items.map((item) => item.column.attribute),
        hidden: items.filter((item) => !item.visible).map((item) => item.column.attribute),
    }
}

export function isDefault(columns, items) {
    const defaults = resolveColumns(columns, null)

    return JSON.stringify(serialize(defaults)) === JSON.stringify(serialize(items))
}

export function toggleColumn(items, attribute) {
    const target = items.find((item) => item.column.attribute === attribute)

    // The last visible column cannot be hidden.
    if (!target || (target.visible && items.filter((item) => item.visible).length === 1)) return items

    return items.map((item) => (item === target ? { ...item, visible: !item.visible } : item))
}

export function moveColumn(items, attribute, delta) {
    const index = items.findIndex((item) => item.column.attribute === attribute)
    const to = index + delta

    if (index === -1 || to < 0 || to >= items.length) return items

    const next = [...items]
    ;[next[index], next[to]] = [next[to], next[index]]

    return next
}

export function loadPreferences(key, storage = globalThis.localStorage) {
    try {
        const raw = storage?.getItem(storageKey(key))

        return raw ? JSON.parse(raw) : null
    } catch {
        return null
    }
}

export function savePreferences(key, prefs, storage = globalThis.localStorage) {
    try {
        if (prefs === null) storage?.removeItem(storageKey(key))
        else storage?.setItem(storageKey(key), JSON.stringify(prefs))
    } catch {
        // storage unavailable (private mode, quota): preferences just do not persist
    }
}
