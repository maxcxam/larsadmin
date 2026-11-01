// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, reactive } from 'vue'
import { mount } from '@vue/test-utils'

// A minimal useForm: post() records the transformed payload instead of visiting.
const visits = []
let lastForm = null

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { larsadmin: { path: '/admin', locale: 'uk', translations: {} } } }),
    useForm: (initial) => {
        let transform = (data) => data
        const form = reactive({
            ...initial,
            errors: {},
            processing: false,
            transform(fn) {
                transform = fn

                return form
            },
            post(url, options) {
                const data = Object.fromEntries(Object.keys(initial).map((key) => [key, form[key]]))
                visits.push({ url, options, data: transform(data) })
            },
        })
        lastForm = form

        return form
    },
}))

const { registry } = await import('../../extensions/registry')
const { mountField } = await import('../../../../kit/testing.js')
const { default: ActionModal } = await import('./ActionModal.vue')

const PROPS = ['field', 'modelValue', 'invalid', 'errors', 'errorsByKey', 'describedBy', 'context', 'resourceId', 'action']
let received = null

const Rating = defineComponent({
    name: 'Rating',
    props: PROPS,
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        return () => {
            received = { ...props }

            return h('input', {
                id: props.field.attribute,
                'aria-describedby': props.describedBy ?? undefined,
                value: props.modelValue,
                onInput: (event) => emit('update:modelValue', Number(event.target.value)),
            })
        }
    },
})

const Throws = defineComponent({
    name: 'Throws',
    props: PROPS,
    render: () => {
        throw new Error('render failed')
    },
})

beforeEach(() => {
    visits.length = 0
    received = null
    lastForm = null
    vi.spyOn(console, 'error').mockImplementation(() => {})
    vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
    vi.restoreAllMocks()
    document.body.innerHTML = ''
})

function open(fields, props = {}) {
    return mount(ActionModal, {
        props: { action: { uriKey: 'rate', name: 'Rate', fields }, entity: 'posts', resources: [5], ...props },
        global: { stubs: { teleport: true } },
        attachTo: document.body,
    })
}

describe('ActionModal with extension fields (design 1.6 §8.3)', () => {
    it('passes the full form contract and the action to an extension component', async () => {
        registry.register('fields', 'acme/modal-rating', { form: Rating }, { extension: 'acme/modal' })

        const field = { component: 'acme/modal-rating', attribute: 'rating', name: 'Rating', value: 3, inAction: true, help: 'Stars' }
        const wrapper = open([field])
        await nextTick()

        expect(received).toMatchObject({ modelValue: 3, invalid: false, errors: [], errorsByKey: {}, context: 'create', resourceId: 5, action: { uriKey: 'rate', entity: 'posts' } })
        expect(received.field).toEqual(field)

        // The help text describes the input
        const help = wrapper.find('p.text-fg-muted')
        expect(help.text()).toBe('Stars')
        expect(received.describedBy).toBe(help.attributes('id'))
        expect(wrapper.find('#rating').attributes('aria-describedby')).toBe(help.attributes('id'))

        await wrapper.find('#rating').setValue('5')
        await wrapper.find('form').trigger('submit')

        expect(visits).toHaveLength(1)
        expect(visits[0].url).toBe('/admin/entities/posts/actions/rate')
        expect(visits[0].data).toEqual({ resources: [5], rating: 5 })
        expect(visits[0].options.forceFormData).toBeUndefined()
        wrapper.unmount()
    })

    it('sends a field named like a form method (post) with its value', async () => {
        registry.register('fields', 'acme/modal-post', { form: Rating }, { extension: 'acme/modal' })

        const wrapper = open([{ component: 'acme/modal-post', attribute: 'post', name: 'Post', value: 59, inAction: true }])
        await nextTick()
        await wrapper.find('form').trigger('submit')

        expect(visits).toHaveLength(1)
        expect(visits[0].data).toEqual({ resources: [5], post: 59 })
        wrapper.unmount()
    })

    it('gives the field its errors and points describedBy at the message', async () => {
        registry.register('fields', 'acme/modal-errors', { form: Rating }, { extension: 'acme/modal' })

        const wrapper = open([{ component: 'acme/modal-errors', attribute: 'tags', name: 'Tags', value: [] }], { resources: [1, 2] })
        lastForm.errors = { 'tags.0': 'Too long.', 'tags.1': 'Taken.' }
        await nextTick()

        expect(received).toMatchObject({ invalid: true, errors: ['Too long.', 'Taken.'], errorsByKey: { 'tags.0': 'Too long.', 'tags.1': 'Taken.' }, resourceId: null })

        const alert = wrapper.find('[role="alert"]')
        expect(alert.text()).toBe('Too long.')
        expect(received.describedBy).toBe(alert.attributes('id'))
        wrapper.unmount()
    })

    it('passes no record for standalone actions', async () => {
        registry.register('fields', 'acme/modal-standalone', { form: Rating }, { extension: 'acme/modal' })

        const fields = [{ component: 'acme/modal-standalone', attribute: 'score', name: 'Score', value: 1 }]
        const wrapper = open(fields, { action: { uriKey: 'import', name: 'Import', standalone: true, fields } })
        await nextTick()

        expect(received).toMatchObject({ resourceId: null, action: { uriKey: 'import', entity: 'posts' } })
        wrapper.unmount()
    })

    it('shows "Недоступно" for an unknown component and leaves its value out (fail closed)', async () => {
        const wrapper = open([
            { component: 'text-field', attribute: 'reason', name: 'Reason', value: 'spam' },
            { component: 'nobody/modal-missing', attribute: 'missing', name: 'Missing', value: 'secret' },
        ])
        await nextTick()

        expect(wrapper.text()).toContain('Недоступно')

        await wrapper.find('form').trigger('submit')
        expect(visits[0].data).toEqual({ resources: [5], reason: 'spam' })
        wrapper.unmount()
    })

    it('switches a crashing component to "Недоступно" and leaves its value out (fail closed)', async () => {
        registry.register('fields', 'acme/modal-crash', { form: Throws }, { extension: 'acme/modal' })

        const wrapper = open([{ component: 'acme/modal-crash', attribute: 'crash', name: 'Crash', value: 'x' }])
        await nextTick()
        await nextTick()

        expect(wrapper.text()).toContain('Недоступно')
        expect(console.error).toHaveBeenCalledWith(expect.stringContaining('Extension "acme/modal"'), expect.any(Error))

        await wrapper.find('form').trigger('submit')
        expect(visits[0].data).toEqual({ resources: [5] })
        wrapper.unmount()
    })

    it('keeps the 1.4 props of built-in components', async () => {
        const wrapper = open([{ component: 'text-field', attribute: 'reason', name: 'Reason', value: '' }])
        await nextTick()

        const input = wrapper.find('#reason')
        expect(input.exists()).toBe(true)
        for (const attribute of ['action', 'errors', 'context', 'describedby', 'resource-id']) {
            expect(input.attributes(attribute)).toBeUndefined()
        }
        wrapper.unmount()
    })
})

describe('kit/testing.js mountField in an action', () => {
    it('mounts the field as the action modal does', () => {
        const wrapper = mountField(Rating, { field: { attribute: 'rating', value: 2 }, action: { uriKey: 'rate', entity: 'posts' } })

        expect(wrapper.props()).toMatchObject({ context: 'create', action: { uriKey: 'rate', entity: 'posts' }, resourceId: null, modelValue: 2 })
        expect(wrapper.props('field').inAction).toBe(true)
    })

    it('keeps the 1.5 defaults without an action', () => {
        const wrapper = mountField(Rating, { field: { attribute: 'rating' } })

        expect(wrapper.props()).toMatchObject({ context: 'update', action: null })
        expect(wrapper.props('field')).not.toHaveProperty('inAction')
    })
})
