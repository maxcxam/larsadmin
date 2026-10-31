import { isExtensionKey, registry } from '../../extensions/registry'
import { ensureBuiltins } from '../../extensions/builtins'
import { bounded, placeholder } from '../../extensions/components'

// Card registry: `card.component` (server) -> Vue component. Thin wrapper over the component registry (design 1.5
// §10). Unknown keys without `/` return null (CardGrid shows "unknown card"); unknown `vendor/name` keys render
// the extension placeholder.
export function registerCard(component, vueComponent) {
    ensureBuiltins()
    registry.register('cards', component, vueComponent, { builtin: true })
}

export function cardComponent(card) {
    ensureBuiltins()

    const key = card?.component
    const resolved = registry.resolve('cards', key)

    if (!resolved) return isExtensionKey(key) ? placeholder('card') : null
    if (resolved.source === 'builtin') return resolved.component

    return bounded('cards', key, resolved, { fallback: resolved.source === 'override' ? resolved.builtin : null, mode: 'card' })
}
