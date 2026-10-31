// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { registry } from '../../extensions/registry'
import { isWrapped } from '../../extensions/components'
import { displayComponent, formComponent, isFieldUnavailable } from '../../fields'
import { cardComponent } from '../cards'
import { filterComponent } from '../../filters'
import { TextInput } from '../../extensions/builtins'

const Throws = { name: 'Throws', props: ['field'], render: () => { throw new Error('render failed') } }
const Fine = { name: 'Fine', props: ['field', 'modelValue', 'errors', 'describedBy', 'context'], render() { return h('input', { id: this.field.attribute, 'data-errors': (this.errors ?? []).join('|'), 'data-context': this.context }) } }

let errorSpy

beforeEach(() => {
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => vi.restoreAllMocks())

describe('field wrappers over the registry', () => {
    it('keeps the 1.4 fallback for unknown keys without a slash', () => {
        expect(formComponent({ component: 'legacy-field' })).toBe(TextInput)
        expect(isFieldUnavailable({ component: 'legacy-field' })).toBe(false)
    })

    it('renders the placeholder for unknown vendor/name keys and excludes them from the form', () => {
        const field = { component: 'nobody/missing', attribute: 'missing', name: 'Missing' }
        const wrapper = mount(formComponent(field), { props: { field } })

        expect(wrapper.text()).toContain('Недоступно')
        expect(wrapper.find('#missing').exists()).toBe(true)
        expect(isFieldUnavailable(field)).toBe(true)

        const display = mount(displayComponent(field, 'index'), { props: { field } })
        expect(display.text()).toContain('—')
        expect(display.find('[title]').attributes('title')).toContain('Недоступно')
    })

    it('shows a loading state while extensions are still loading', async () => {
        registry.setExtensionState('acme/slow', 'loading')

        const field = { component: 'acme/slow-field', attribute: 'slow' }
        const wrapper = mount(formComponent(field), { props: { field } })

        expect(wrapper.text()).toContain('Завантаження розширення')

        registry.setExtensionState('acme/slow', 'failed', { reason: 'import' })
        await nextTick()
        expect(wrapper.text()).toContain('Недоступно')
    })

    it('passes the form contract to extension components', () => {
        registry.register('fields', 'acme/fine', { form: Fine }, { extension: 'acme/fine' })

        const field = { component: 'acme/fine', attribute: 'fine' }
        const component = formComponent(field)

        expect(isWrapped(component)).toBe(true)

        const wrapper = mount(component, { props: { field, modelValue: 1, errors: ['Bad.'], describedBy: 'fine-error', context: 'update' } })
        expect(wrapper.find('input').attributes('data-errors')).toBe('Bad.')
        expect(isFieldUnavailable(field)).toBe(false)
    })

    it('switches a crashed extension field to the placeholder and fails closed', async () => {
        registry.register('fields', 'acme/crash', { form: Throws }, { extension: 'acme/crash' })

        const field = { component: 'acme/crash', attribute: 'crash' }
        const wrapper = mount(formComponent(field), { props: { field } })
        await nextTick()

        expect(wrapper.text()).toContain('Недоступно')
        expect(isFieldUnavailable(field)).toBe(true)
        expect(errorSpy).toHaveBeenCalledWith(expect.stringContaining('Extension "acme/crash"'), expect.any(Error))
    })

    it('falls back to the built-in component when an override crashes, keeping the value', async () => {
        registry.register('fields', 'number-field', { form: Throws }, { extension: 'acme/over', override: true })

        const field = { component: 'number-field', attribute: 'amount', value: 3 }
        const wrapper = mount(formComponent(field), { props: { field, modelValue: 3, errors: [], describedBy: null } })
        await nextTick()

        const input = wrapper.find('input#amount')
        expect(input.exists()).toBe(true)
        // Contract-only props do not leak onto the built-in input.
        expect(input.attributes('errors')).toBeUndefined()
        expect(input.attributes('describedby')).toBeUndefined()
        expect(isFieldUnavailable(field)).toBe(false)
        // For the rest of the page session the built-in is resolved directly.
        expect(isWrapped(formComponent(field))).toBe(false)
    })
})

describe('non-render errors (CRX-01)', () => {
    const Clicky = (handler) => ({ name: 'Clicky', props: ['field', 'modelValue'], render() { return h('button', { id: this.field.attribute, onClick: handler }, 'go') } })

    it('keeps the field available when a click handler throws', async () => {
        registry.register('fields', 'acme/click-sync', { form: Clicky(() => { throw new Error('sync boom') }) }, { extension: 'acme/click' })

        const field = { component: 'acme/click-sync', attribute: 'sync' }
        const wrapper = mount(formComponent(field), { props: { field, modelValue: 1 } })

        await wrapper.find('button').trigger('click')
        await nextTick()

        expect(wrapper.find('button').exists()).toBe(true)
        expect(isFieldUnavailable(field)).toBe(false)
        expect(errorSpy).toHaveBeenCalledWith(expect.stringContaining('threw in'), expect.any(Error))
    })

    it('keeps the field available when an async handler rejects, and toasts in debug mode', async () => {
        const { useToasts } = await import('../../composables/useToasts')
        const before = useToasts().state.items.length

        registry.register('fields', 'acme/click-async', { form: Clicky(async () => { throw new Error('network down') }) }, { extension: 'acme/click' })

        const field = { component: 'acme/click-async', attribute: 'async' }
        const page = { props: { larsadmin: { debug: true } } }
        const wrapper = mount(formComponent(field), { props: { field, modelValue: 1 }, global: { provide: { [Symbol.for('larsadmin.page')]: page } } })

        await wrapper.find('button').trigger('click')
        await new Promise((resolve) => setTimeout(resolve, 0))
        await nextTick()

        expect(wrapper.find('button').exists()).toBe(true)
        expect(isFieldUnavailable(field)).toBe(false)
        expect(useToasts().state.items.length).toBe(before + 1)
        expect(useToasts().state.items.at(-1)).toMatchObject({ type: 'error', title: 'acme/click' })
        useToasts().clear()
    })

    it('still switches off a component whose mounted hook throws', async () => {
        const Broken = { props: ['field'], mounted() { throw new Error('mount boom') }, render: () => h('input') }
        registry.register('fields', 'acme/mount-crash', { form: Broken }, { extension: 'acme/mount' })

        const field = { component: 'acme/mount-crash', attribute: 'mount' }
        mount(formComponent(field), { props: { field } })
        await nextTick()

        expect(isFieldUnavailable(field)).toBe(true)
    })
})

describe('card and filter wrappers', () => {
    it('keeps null for unknown card keys without a slash and a placeholder for vendor/name', () => {
        expect(cardComponent({ component: 'mystery-metric' })).toBeNull()

        const card = { component: 'acme/none', name: 'Sales', uriKey: 'sales' }
        const wrapper = mount(cardComponent(card), { props: { card } })
        expect(wrapper.text()).toContain('Sales')
        expect(wrapper.text()).toContain('Недоступно')
    })

    it('renders extension cards inside the boundary', () => {
        const Card = { props: ['card'], render() { return h('p', this.card.name) } }
        registry.register('cards', 'acme/card', Card, { extension: 'acme/card' })

        const wrapper = mount(cardComponent({ component: 'acme/card' }), { props: { card: { component: 'acme/card', name: 'Top rated' } } })
        expect(wrapper.text()).toBe('Top rated')
    })

    it('keeps the SelectFilter fallback for unknown filter keys without a slash', () => {
        expect(filterComponent({ component: 'legacy-filter' }).__name ?? filterComponent({ component: 'legacy-filter' }).name).toMatch(/SelectFilter/)
        expect(isWrapped(filterComponent({ component: 'acme/missing-filter' }))).toBe(true)
    })
})
