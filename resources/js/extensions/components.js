import { defineComponent, h, markRaw } from 'vue'
import ExtensionBoundary from '../components/extensions/ExtensionBoundary.vue'
import UnavailableField from '../components/extensions/UnavailableField.vue'

// Turns registry lookups into components for `<component :is>` (design 1.5 §5.4, §5.6). Built-in components are
// returned as they are (1.4 markup, no extra props). Extension and override components are wrapped in
// ExtensionBoundary; unknown `vendor/name` keys get an UnavailableField placeholder. Wrappers are cached so a
// re-render does not remount the component.

const WRAPPER = '__larsadminWrapper'
const cache = new Map()

// Wrapped components accept the full §5.4 contract (errors, describedBy, context); raw built-ins do not.
export function isWrapped(component) {
    return Boolean(component?.[WRAPPER])
}

function wrapper(name, render) {
    const component = defineComponent({ name, inheritAttrs: false, setup: (_, { attrs }) => () => render(attrs) })
    component[WRAPPER] = true

    return markRaw(component)
}

/**
 * kind/key: registry coordinates; resolved: registry.resolve() result (source 'extension' or 'override');
 * fallback: built-in component an override falls back to; mode: placeholder mode ('form', 'display', 'panel',
 * 'card', 'filter', 'tool').
 */
export function bounded(kind, key, resolved, { fallback = null, mode }) {
    const id = `${kind}|${key}|${resolved.part}|${mode}`
    const hit = cache.get(id)

    if (hit && hit.component === resolved.component && hit.fallback === fallback) return hit.wrapped

    const props = { kind, componentKey: key, part: resolved.part, extension: resolved.extension, component: resolved.component, fallback, mode }
    const wrapped = wrapper('LarsadminExtensionComponent', (attrs) => h(ExtensionBoundary, { ...props, bind: { ...attrs } }))

    cache.set(id, { component: resolved.component, fallback, wrapped })

    return wrapped
}

const placeholders = new Map()

export function placeholder(mode) {
    if (!placeholders.has(mode)) {
        placeholders.set(
            mode,
            wrapper('LarsadminUnavailable', (attrs) => h(UnavailableField, { mode, class: attrs.class, field: attrs.field, card: attrs.card, filter: attrs.filter, tool: attrs.tool })),
        )
    }

    return placeholders.get(mode)
}
