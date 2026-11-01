// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { larsadmin: { locale: 'uk', translations: {} } } }) }))

const { default: RowActionMenu } = await import('./RowActionMenu.vue')

function open() {
    const wrapper = mount(RowActionMenu, {
        props: { actions: [{ uriKey: 'publish', name: 'Publish' }], runnable: ['publish'], title: 'Post' },
        attachTo: document.body,
    })

    return wrapper
}

function rectAt(top) {
    return { top, bottom: top + 24, left: 100, right: 124, width: 24, height: 24, x: 100, y: top }
}

afterEach(() => {
    document.body.innerHTML = ''
    vi.restoreAllMocks()
})

describe('RowActionMenu and scrolling', () => {
    it('stays open and follows its button when the table wrapper scrolls', async () => {
        const wrapper = open()
        const button = wrapper.find('button').element
        vi.spyOn(button, 'getBoundingClientRect').mockReturnValue(rectAt(200))

        await wrapper.find('button').trigger('click')
        await nextTick()
        expect(document.querySelector('[role="menu"]')).not.toBeNull()

        button.getBoundingClientRect.mockReturnValue(rectAt(150))
        document.body.dispatchEvent(new Event('scroll'))
        window.dispatchEvent(new Event('scroll'))
        await nextTick()

        expect(document.querySelector('[role="menu"]')).not.toBeNull()
        wrapper.unmount()
    })

    it('closes once its button has scrolled out of the viewport', async () => {
        const wrapper = open()
        const button = wrapper.find('button').element
        vi.spyOn(button, 'getBoundingClientRect').mockReturnValue(rectAt(200))

        await wrapper.find('button').trigger('click')
        await nextTick()

        button.getBoundingClientRect.mockReturnValue(rectAt(-100))
        window.dispatchEvent(new Event('scroll'))
        await nextTick()

        expect(document.querySelector('[role="menu"]')).toBeNull()
        wrapper.unmount()
    })
})
