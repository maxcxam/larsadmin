// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'

// router.get records the visit and leaves it in flight; the test answers (or cancels) it.
const visits = []

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/admin/entities/posts', props: { larsadmin: { path: '/admin', locale: 'uk', translations: {} } } }),
    router: { get: (url, data, options) => visits.push({ url, data, options }), delete: vi.fn() },
    Head: { render: () => null },
    Link: { render: () => null },
}))

const { default: Index } = await import('./Index.vue')

const FILTERS = [
    { key: 'status', label: 'Status', component: 'select-filter', options: [{ value: 'draft', label: 'Draft' }, { value: 'published', label: 'Published' }], default: null },
    { key: 'author', label: 'Author', component: 'select-filter', options: [{ value: '1', label: 'Ann' }, { value: '2', label: 'Bob' }], default: null },
]

const STUBS = { ColumnMenu: true, ActionMenu: true, EntityTable: true, Pagination: true, CardGrid: true, ActionModal: true, ConfirmDialog: true }

function render(filters = {}) {
    return mount(Index, {
        props: {
            entity: { uriKey: 'posts', label: 'Posts', searchable: true, perPageOptions: [25, 50] },
            columns: [],
            records: { data: [], total: 0, current_page: 1 },
            query: { search: '', perPage: 25, filters },
            filters: FILTERS,
        },
        global: { stubs: STUBS },
        attachTo: document.body,
    })
}

// The server answers a visit: new props (it echoes the applied filters), then Inertia's onFinish.
async function answer(wrapper, visit) {
    await wrapper.setProps({ query: { ...wrapper.props('query'), filters: visit.data.filters ?? [] } })
    visit.options.onFinish?.()
    await nextTick()
}

async function openFilters(wrapper) {
    await wrapper.find('button[aria-haspopup="dialog"]').trigger('click')
}

const selects = (wrapper) => wrapper.findAll('[role="dialog"] select')

beforeEach(() => {
    visits.length = 0
    vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
})

describe('entity index filters while a filter visit is in flight (1.6.4)', () => {
    it('a second filter changed before the first visit answered keeps the first one', async () => {
        const wrapper = render()
        await openFilters(wrapper)

        await selects(wrapper)[0].setValue('draft')
        expect(visits).toHaveLength(1)
        expect(visits[0].data.filters).toEqual({ status: 'draft' })

        // Visit 1 still in flight: the menu already shows the user's choice.
        expect(selects(wrapper)[0].element.value).toBe('draft')

        await selects(wrapper)[1].setValue('2')
        expect(visits).toHaveLength(2)
        expect(visits[1].data.filters).toEqual({ status: 'draft', author: '2' })

        // Inertia cancels the older visit; the newer one answers.
        visits[0].options.onCancel?.()
        await answer(wrapper, visits[1])

        expect(selects(wrapper)[0].element.value).toBe('draft')
        expect(selects(wrapper)[1].element.value).toBe('2')
        wrapper.unmount()
    })

    it('a stale answer of the older visit does not reset what the user chose since', async () => {
        const wrapper = render()
        await openFilters(wrapper)

        await selects(wrapper)[0].setValue('draft')
        await selects(wrapper)[1].setValue('1')

        // The older visit answers anyway (not cancelled): its state is out of date.
        await answer(wrapper, visits[0])
        expect(selects(wrapper)[1].element.value).toBe('1')

        await selects(wrapper)[0].setValue('published')
        expect(visits[2].data.filters).toEqual({ status: 'published', author: '1' })

        await answer(wrapper, visits[2])
        expect(selects(wrapper)[0].element.value).toBe('published')
        expect(selects(wrapper)[1].element.value).toBe('1')
        wrapper.unmount()
    })

    it('a sort or per-page reload during a filter visit carries the pending filters', async () => {
        const wrapper = render()
        await openFilters(wrapper)

        await selects(wrapper)[0].setValue('draft')
        await wrapper.find('label > select').setValue('50')

        expect(visits[1].data).toMatchObject({ perPage: 50, filters: { status: 'draft' } })
        wrapper.unmount()
    })

    it('a failed visit falls back to the filters the server really applied', async () => {
        const wrapper = render({ author: '1' })
        await openFilters(wrapper)

        await selects(wrapper)[0].setValue('draft')
        expect(selects(wrapper)[0].element.value).toBe('draft')

        // Network error: no new props, the visit just finishes.
        visits[0].options.onFinish()
        await nextTick()

        expect(selects(wrapper)[0].element.value).toBe('')
        expect(selects(wrapper)[1].element.value).toBe('1')
        wrapper.unmount()
    })
})
