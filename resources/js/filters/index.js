import SelectFilter from './SelectFilter.vue'
import BooleanFilter from './BooleanFilter.vue'
import DateRangeFilter from './DateRangeFilter.vue'

// Maps a filter's server-side `component` key to the Vue component that renders it.
const filterComponents = {
    'select-filter': SelectFilter,
    'boolean-filter': BooleanFilter,
    'date-range-filter': DateRangeFilter,
}

export function filterComponent(filter) {
    return filterComponents[filter.component] ?? SelectFilter
}

export function registerFilter(component, vueComponent) {
    filterComponents[component] = vueComponent
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
