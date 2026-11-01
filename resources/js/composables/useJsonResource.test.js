// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import { useJsonResource } from './useJsonResource.js'

function withResource() {
    let resource = null
    const wrapper = mount(defineComponent({
        setup() {
            resource = useJsonResource()

            return () => h('div')
        },
    }))

    return { resource, wrapper }
}

afterEach(() => {
    vi.unstubAllGlobals()
})

describe('useJsonResource', () => {
    it('a request without a response resolves to null with status error, it never throws (1.6.4)', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))))
        const { resource, wrapper } = withResource()

        await expect(resource.load('/admin/options')).resolves.toBeNull()
        expect(resource.status.value).toBe('error')
        wrapper.unmount()
    })

    it('an unreadable body is an error too, not an exception', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: () => Promise.reject(new SyntaxError('Unexpected token')) })))
        const { resource, wrapper } = withResource()

        await expect(resource.load('/admin/options')).resolves.toBeNull()
        expect(resource.status.value).toBe('error')
        wrapper.unmount()
    })

    it('a superseded request resolves to undefined and leaves the state to the newer one', async () => {
        let first
        vi.stubGlobal('fetch', vi.fn()
            .mockImplementationOnce(() => new Promise((resolve, reject) => (first = reject)))
            .mockImplementationOnce(async () => ({ ok: true, status: 200, json: async () => ({ data: [1] }) })))
        const { resource, wrapper } = withResource()

        const older = resource.load('/admin/options?page=1')
        await expect(resource.load('/admin/options?page=2')).resolves.toEqual({ data: [1] })
        first(new TypeError('Failed to fetch'))

        await expect(older).resolves.toBeUndefined()
        expect(resource.status.value).toBe('idle')
        wrapper.unmount()
    })
})
