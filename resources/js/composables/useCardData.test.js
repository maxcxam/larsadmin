// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { useCardData } from './useCardData'

let fetchMock

const json = (body, status = 200) => Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) })

function mountWith(card, params) {
    let api

    mount(defineComponent({
        setup() {
            api = useCardData(card, params)

            return () => h('div')
        },
    }))

    return api
}

beforeEach(() => {
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => vi.unstubAllGlobals())

describe('useCardData', () => {
    const card = { component: 'acme/top', endpoint: '/admin/api/dashboards/main/metrics/top-rated' }

    it('loads { data } from the card endpoint on mount', async () => {
        fetchMock.mockReturnValue(json({ data: [{ id: 1 }] }))

        const { status, data } = mountWith(card, { limit: 5 })
        expect(status.value).toBe('loading')
        await flushPromises()

        expect(fetchMock).toHaveBeenCalledWith('/admin/api/dashboards/main/metrics/top-rated?limit=5', expect.objectContaining({ credentials: 'same-origin' }))
        expect(status.value).toBe('idle')
        expect(data.value).toEqual([{ id: 1 }])
    })

    it('reloads when reactive params change', async () => {
        fetchMock.mockReturnValueOnce(json({ data: 'a' })).mockReturnValueOnce(json({ data: 'b' }))
        const params = ref({ period: 'week' })

        const { data } = mountWith(card, params)
        await flushPromises()
        expect(data.value).toBe('a')

        params.value = { period: 'month' }
        await flushPromises()

        expect(fetchMock).toHaveBeenLastCalledWith(`${card.endpoint}?period=month`, expect.any(Object))
        expect(data.value).toBe('b')
    })

    it('reports forbidden and error statuses and clears the data', async () => {
        fetchMock.mockReturnValueOnce(json({ message: 'no' }, 403)).mockReturnValueOnce(json({ message: 'bad' }, 422))

        const { status, data, reload } = mountWith(card)
        await flushPromises()
        expect(status.value).toBe('forbidden')
        expect(data.value).toBeNull()

        await reload()
        expect(status.value).toBe('error')
    })

    it('is an error without an endpoint', async () => {
        const { status } = mountWith({ component: 'acme/static' })
        await flushPromises()

        expect(fetchMock).not.toHaveBeenCalled()
        expect(status.value).toBe('error')
    })
})
