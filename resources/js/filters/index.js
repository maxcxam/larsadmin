import { isExtensionKey, registry } from '../extensions/registry'
import { ensureBuiltins, SelectFilter } from '../extensions/builtins'
import { bounded, placeholder } from '../extensions/components'

// Maps a filter's server-side `component` key to the Vue component that renders it (thin wrapper over the
// component registry, design 1.5 §10). Unknown keys without `/` keep the 1.4 fallback (SelectFilter).
export function filterComponent(filter) {
    ensureBuiltins()

    const key = filter?.component
    const resolved = registry.resolve('filters', key)

    if (!resolved) return isExtensionKey(key) ? placeholder('filter') : SelectFilter
    if (resolved.source === 'builtin') return resolved.component

    return bounded('filters', key, resolved, { fallback: resolved.source === 'override' ? (resolved.builtin ?? SelectFilter) : null, mode: 'filter' })
}

export function registerFilter(component, vueComponent) {
    ensureBuiltins()
    registry.register('filters', component, vueComponent, { builtin: true })
}

function isEmpty(value) {
    if (value === null || value === undefined || value === '') return true
    if (typeof value === 'object') return Object.values(value).every(isEmpty)

    return false
}

export function isFilterActive(value) {
    return !isEmpty(value)
}

// A filter whose `default` is non-null falls back to that default on the server when its key is absent,
// so clearing it has to be sent explicitly as an empty value (`filters[key]=`).
function hasDefault(definition) {
    return definition && definition.default !== null && definition.default !== undefined
}

// Drops empty values (and empty date-range ends) so they never reach the query string, except that
// filters with a non-null default are kept as `key: ""` when cleared (pass the filter definitions).
// Inertia serialises the nested object as filters[key]=v / filters[key][from]=v.
export function cleanFilters(filters, definitions = []) {
    const result = {}

    for (const [key, value] of Object.entries(filters ?? {})) {
        if (isEmpty(value)) continue

        result[key] = typeof value === 'object'
            ? Object.fromEntries(Object.entries(value).filter(([, part]) => !isEmpty(part)))
            : value
    }

    for (const definition of definitions ?? []) {
        if (hasDefault(definition) && !(definition.key in result)) result[definition.key] = ''
    }

    return result
}
