import { computed, ref, watch } from 'vue'

// Row selection for the index table.
// useSelection({ pageIds: () => [ids on this page], total: () => matching count, resetOn: () => [page, search, filters] })
// `selected` is an array of ids, or the string 'all' once "select all N matching" is chosen.
export function useSelection({ pageIds, total = () => 0, resetOn = () => [] }) {
    const ids = ref(new Set())
    const allMatching = ref(false)

    const onPage = computed(() => pageIds())
    const count = computed(() => (allMatching.value ? total() : ids.value.size))
    const pageSelected = computed(() => onPage.value.length > 0 && onPage.value.every((id) => allMatching.value || ids.value.has(id)))
    const pageIndeterminate = computed(() => !pageSelected.value && onPage.value.some((id) => ids.value.has(id)))
    // Offer "select all N" only when the whole page is selected and more rows match
    const canSelectAllMatching = computed(() => pageSelected.value && !allMatching.value && total() > onPage.value.length)
    const selected = computed(() => (allMatching.value ? 'all' : [...ids.value]))

    const isSelected = (id) => allMatching.value || ids.value.has(id)

    function toggle(id, on = !ids.value.has(id)) {
        allMatching.value = false
        const next = new Set(ids.value)
        if (on) next.add(id)
        else next.delete(id)
        ids.value = next
    }

    function togglePage(on = !pageSelected.value) {
        allMatching.value = false
        const next = new Set(ids.value)
        onPage.value.forEach((id) => (on ? next.add(id) : next.delete(id)))
        ids.value = next
    }

    function selectAllMatching() {
        allMatching.value = true
    }

    function clear() {
        allMatching.value = false
        ids.value = new Set()
    }

    watch(resetOn, clear)

    return { ids, selected, count, allMatching, pageSelected, pageIndeterminate, canSelectAllMatching, isSelected, toggle, togglePage, selectAllMatching, clear }
}
