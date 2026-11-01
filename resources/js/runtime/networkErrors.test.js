// @vitest-environment jsdom
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { http, HttpNetworkError } from '@inertiajs/core'

// The real Inertia router and useForm; only usePage is pinned (no createInertiaApp in a unit test).
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ url: '/admin/entities/posts/create', props: { larsadmin: { path: '/admin', locale: 'uk', translations: {} } } }),
}))

const { router } = await import('@inertiajs/vue3')
const { handleNetworkErrors } = await import('./networkErrors.js')
const { default: EntityForm } = await import('../components/EntityForm.vue')

const originalClient = http.getClient()
const unhandled = []
const onUnhandled = (reason) => unhandled.push(reason)
let removeListener = null
let notified = []

// A request that never gets a response, as the XHR client reports it.
const offline = { request: vi.fn(async (config) => Promise.reject(new HttpNetworkError('Network error', config.url))) }

beforeAll(() => {
    router.init({
        initialPage: { component: 'Entities/Create', props: {}, url: '/admin/entities/posts/create', version: null },
        resolveComponent: () => ({}),
        swapComponent: async () => {},
    })
})

const flush = async () => {
    for (let i = 0; i < 10; i++) await new Promise((resolve) => setTimeout(resolve, 0))
}

beforeEach(() => {
    unhandled.length = 0
    notified = []
    offline.request.mockClear()
    http.setClient(offline)
    process.on('unhandledRejection', onUnhandled)
    removeListener = handleNetworkErrors(router, { notify: (error) => notified.push(error) })
    vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
    removeListener?.()
    process.off('unhandledRejection', onUnhandled)
    http.setClient(originalClient)
    vi.restoreAllMocks()
    document.body.innerHTML = ''
})

describe('handleNetworkErrors (1.6.4)', () => {
    it('cancels the networkError event and notifies', () => {
        const error = new Error('Network error')
        const event = new CustomEvent('inertia:networkError', { cancelable: true, detail: { error } })

        expect(document.dispatchEvent(event)).toBe(false)
        expect(event.defaultPrevented).toBe(true)
        expect(notified).toEqual([error])
    })

    it('keeps the event cancelled when the toast itself fails', () => {
        removeListener()
        vi.spyOn(console, 'error').mockImplementation(() => {})
        removeListener = handleNetworkErrors(router, {
            notify: () => {
                throw new Error('no toast')
            },
        })

        expect(document.dispatchEvent(new CustomEvent('inertia:networkError', { cancelable: true, detail: { error: null } }))).toBe(false)
        expect(console.error).toHaveBeenCalled()
    })

    it('a form save without a response: toast, processing reset, data kept, nothing uncaught', async () => {
        const wrapper = mount(EntityForm, {
            props: {
                fields: [{ component: 'text-field', attribute: 'title', name: 'Title', value: 'Draft' }],
                action: '/admin/entities/posts',
                submitLabel: 'Save',
                cancelHref: '/admin/entities/posts',
            },
            global: { stubs: { Link: true } },
            attachTo: document.body,
        })

        await wrapper.find('input#title').setValue('Unsaved title')
        await wrapper.find('form').trigger('submit')
        await flush()

        expect(offline.request).toHaveBeenCalledTimes(1)
        expect(notified).toHaveLength(1)
        expect(notified[0]).toBeInstanceOf(HttpNetworkError)
        expect(unhandled).toEqual([])

        await nextTick()
        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
        expect(wrapper.find('input#title').element.value).toBe('Unsaved title')
        wrapper.unmount()
    })

    it('without the handler the same failure is an unhandled rejection (the 1.6.3 bug)', async () => {
        removeListener()
        removeListener = null

        router.post('/admin/entities/posts', { title: 'x' })
        await flush()

        expect(unhandled).toHaveLength(1)
        expect(unhandled[0]).toBeInstanceOf(HttpNetworkError)
    })
})
