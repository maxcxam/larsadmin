import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
import { useFilterVisits } from './useFilterVisits.js'

describe('useFilterVisits (1.6.4)', () => {
    it('shows the server state until a visit starts', () => {
        const server = ref({ status: 'draft' })
        const { current } = useFilterVisits(() => server.value)

        expect(current.value).toEqual({ status: 'draft' })
        server.value = { status: 'published' }
        expect(current.value).toEqual({ status: 'published' })
    })

    it('keeps the pending values until the latest visit settles', () => {
        const server = ref({})
        const { current, track } = useFilterVisits(() => server.value)

        const first = track({ status: 'draft' })
        const second = track({ ...current.value, author: '2' })
        expect(current.value).toEqual({ status: 'draft', author: '2' })

        // The older visit is cancelled or answers late: ignored.
        first.onCancel()
        server.value = { status: 'draft' }
        first.onFinish()
        expect(current.value).toEqual({ status: 'draft', author: '2' })

        server.value = { status: 'draft', author: '2' }
        second.onFinish()
        expect(current.value).toBe(server.value)
    })

    it('a reload without filter edits keeps the pending values and becomes the latest visit', () => {
        const server = ref({})
        const { current, track } = useFilterVisits(() => server.value)

        const filter = track({ status: 'draft' })
        const sort = track(undefined)

        filter.onFinish()
        expect(current.value).toEqual({ status: 'draft' })

        sort.onCancel()
        expect(current.value).toEqual({})
    })
})
