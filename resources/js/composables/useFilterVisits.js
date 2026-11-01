import { computed, ref } from 'vue'

// Filter state of an index page while its filter visits are in flight. The server's `query.filters` only changes
// when a visit answers; a second filter edited before that would be merged into the stale server state and the
// newer visit (which cancels the older one) would drop the first edit. So the values the user chose are kept
// locally until the latest visit settles: every edit and every other reload (search, sort, per page) builds on them.
//
//   const filterVisits = useFilterVisits(() => activeFilters.value)
//   filterVisits.current.value                      -> pending values, else the server's
//   router.get(url, data, { ...filterVisits.track(values) })  -> onFinish / onCancel that settle the latest visit
//
// `track(values)` with `undefined` keeps the current pending values (a reload that does not edit filters).
// When the latest visit settles (success, validation error, network error, cancel) the server state is the truth
// again: on success it echoes the applied filters, otherwise the page still shows what was really applied.
export function useFilterVisits(serverFilters) {
    const pending = ref(null)
    let latest = 0

    const current = computed(() => pending.value ?? serverFilters())

    function track(values) {
        if (values !== undefined) pending.value = values

        const visit = ++latest
        const settle = () => {
            if (visit === latest) pending.value = null
        }

        return { onFinish: settle, onCancel: settle }
    }

    return { current, track }
}
