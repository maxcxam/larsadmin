import { isExtensionKey, registry } from '../extensions/registry'
import { ensureBuiltins, TextDisplay, TextInput } from '../extensions/builtins'
import { bounded, isWrapped, placeholder } from '../extensions/components'

// Thin internal wrappers over the component registry (design 1.5 §10). They map a field's server-side `component`
// key to the Vue component that renders it in a context. Keys without `/` that are not registered keep the 1.4
// fallback (plain text display, text input); unknown `vendor/name` keys render a placeholder (fail closed).

function pick(field, part, fallback, mode) {
    ensureBuiltins()

    const key = field?.component
    const resolved = registry.resolve('fields', key, part)

    if (!resolved) {
        if (isExtensionKey(key)) return placeholder(mode)

        return fallback
    }

    if (resolved.source === 'builtin') return resolved.component

    return bounded('fields', key, resolved, {
        fallback: resolved.source === 'override' ? (resolved.builtin ?? fallback) : null,
        mode,
    })
}

// context: 'index' | 'detail' (both fall back to the `display` part); omitted = `display`.
export function displayComponent(field, context = null) {
    const part = context === 'index' || context === 'detail' ? context : 'display'

    return pick(field, part, TextDisplay, 'display')
}

// Props of the display contract (design 1.5 §5.4) for extension / override components only; built-in components
// keep exactly their 1.4 props.
export function displayProps(component, context) {
    return isWrapped(component) ? { context } : {}
}

export function formComponent(field) {
    return pick(field, 'form', TextInput, 'form')
}

export function panelComponent(field) {
    return pick(field, 'panel', null, 'panel')
}

// Fail closed (ADR-032): an extension field whose form component is missing or crashed is not submitted.
export function isFieldUnavailable(field) {
    ensureBuiltins()

    return registry.isUnavailable('fields', field?.component, 'form')
}

export function registerField(component, { display, form, panel } = {}) {
    ensureBuiltins()
    registry.register('fields', component, { display, form, panel }, { builtin: true })
}
