import { nextTick, ref } from 'vue'
import { describe, expect, it } from 'vitest'
import { useSelection } from './useSelection.js'

function make() {
    const ids = ref([1, 2, 3])
    const total = ref(10)
    const reset = ref('a')
    const selection = useSelection({ pageIds: () => ids.value, total: () => total.value, resetOn: () => [reset.value] })

    return { selection, ids, total, reset }
}

describe('useSelection', () => {
    it('toggles single rows and reports indeterminate / page-selected', () => {
        const { selection } = make()

        selection.toggle(1)
        expect(selection.count.value).toBe(1)
        expect(selection.pageIndeterminate.value).toBe(true)
        expect(selection.pageSelected.value).toBe(false)

        selection.togglePage(true)
        expect(selection.pageSelected.value).toBe(true)
        expect(selection.selected.value).toEqual([1, 2, 3])
    })

    it('offers select-all-matching only when more rows match than the page holds', () => {
        const { selection, total } = make()

        selection.togglePage(true)
        expect(selection.canSelectAllMatching.value).toBe(true)

        total.value = 3
        expect(selection.canSelectAllMatching.value).toBe(false)
    })

    it('selects all matching and reports the total as count', () => {
        const { selection } = make()

        selection.togglePage(true)
        selection.selectAllMatching()

        expect(selection.selected.value).toBe('all')
        expect(selection.count.value).toBe(10)
        expect(selection.isSelected(99)).toBe(true)

        selection.toggle(1, false)
        expect(selection.allMatching.value).toBe(false)
    })

    it('clears when a reset source changes', async () => {
        const { selection, reset } = make()

        selection.togglePage(true)
        reset.value = 'b'
        await nextTick()

        expect(selection.count.value).toBe(0)
    })
})
