// Test helpers for larsadmin extensions (design 1.5 §6.4), on top of @vue/test-utils from the author's own
// devDependencies. Use with the preset (kit/vite.js), which points `larsadmin` at kit/sdk.js in vitest.
//
//   import { mountField } from '../../../vendor/maxcxam/larsadmin/kit/testing.js'
//   const wrapper = mountField(RatingInput, { field: { attribute: 'rating', value: 3, max: 5 } })
//   await wrapper.find('button').trigger('click')
//   expect(wrapper.emitted('update:modelValue')[0]).toEqual([1])
import { reactive } from 'vue'
import { mount } from '@vue/test-utils'

// Same key as the SDK (resources/js/sdk/page.js): the SDK composables read this page instead of Inertia's.
const PANEL_PAGE = Symbol.for('larsadmin.page')

/**
 * Mount options that give SDK composables (useI18n, useLarsadmin, ...) a panel page without an Inertia app.
 * translations: the extension's own namespaces, { 'acme-rating': { stars: '…' } } -> t('acme-rating::stars').
 * props: extra page props (merged; `larsadmin` keys are merged into the panel ones).
 */
export function withPanel({ locale = 'uk', translations = {}, path = '/admin', props = {} } = {}) {
    const { larsadmin = {}, ...rest } = props
    const page = reactive({
        component: 'Test',
        url: path,
        props: {
            ...rest,
            larsadmin: { locale, path, name: 'Admin', translations: {}, extensionTranslations: translations, debug: false, ...larsadmin },
        },
    })

    return { global: { provide: { [PANEL_PAGE]: page } } }
}

function merge(options, extra) {
    return {
        ...options,
        ...extra,
        props: { ...(extra.props ?? {}), ...(options.props ?? {}) },
        global: { ...(extra.global ?? {}), ...(options.global ?? {}), provide: { ...(extra.global?.provide ?? {}), ...(options.global?.provide ?? {}) } },
    }
}

/**
 * Mounts a form component with the field contract of design §5.4. v-model is wired: emitted values become the new
 * `modelValue`. Pass `panel` for withPanel() options and `options` for extra mount options.
 * `action` (since 1.6, design 1.6 §8.3): { uriKey, entity } mounts the field as in an action's modal: the field is
 * serialized with `inAction: true` and the context defaults to 'create', as the panel sends it.
 */
export function mountField(Component, { field = {}, modelValue, errors = [], errorsByKey = {}, action = null, context = action ? 'create' : 'update', resourceId = null, panel = {}, options = {} } = {}) {
    const attribute = field.attribute ?? 'field'
    const value = modelValue === undefined ? field.value ?? null : modelValue
    let wrapper

    wrapper = mount(
        Component,
        merge(options, {
            ...withPanel(panel),
            props: {
                field: { name: attribute, attribute, readonly: false, required: false, ...(action ? { inAction: true } : {}), ...field },
                modelValue: value,
                invalid: errors.length > 0,
                errors,
                // Keyed messages (`attribute`, `attribute.*`), design §18 A6b
                errorsByKey,
                describedBy: errors.length > 0 ? `${attribute}-error` : null,
                context,
                resourceId,
                // { uriKey, entity } in an action's modal, else null (since 1.6)
                action,
                'onUpdate:modelValue': (next) => wrapper.setProps({ modelValue: next }),
            },
        }),
    )

    return wrapper
}

/**
 * Mounts a display component (index / detail) with the display contract of design §5.4: `field` (with `value`
 * and `displayedAs`) and `context` ('index' | 'detail').
 */
export function mountDisplay(Component, { field = {}, context = 'detail', panel = {}, options = {} } = {}) {
    const attribute = field.attribute ?? 'field'
    const value = field.value ?? null

    return mount(
        Component,
        merge(options, {
            ...withPanel(panel),
            props: {
                field: { name: attribute, attribute, value, displayedAs: value === null ? null : String(value), ...field },
                context,
            },
        }),
    )
}

/** Mounts a card component with `card` (component, uriKey, name, width, endpoint, own keys). */
export function mountCard(Component, { card = {}, panel = {}, options = {} } = {}) {
    return mount(
        Component,
        merge(options, {
            ...withPanel(panel),
            props: { card: { component: 'test/card', uriKey: 'test-card', name: 'Card', width: '1/3', helpText: null, endpoint: null, ...card } },
        }),
    )
}
