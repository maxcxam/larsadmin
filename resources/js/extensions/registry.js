import { markRaw, reactive, shallowReactive } from 'vue'

// One reactive component registry for fields, cards, filters and tools (design 1.5 §5.2, §5.6, §10; ADR-032).
// Built-in components register through the same `register()` call that extensions use (dogfooding). Because the
// maps are reactive, a placeholder rendered before an extension finished loading re-renders into its component.
//
// A field entry has the parts `form`, `display` and optional `index`, `detail` (both fall back to `display`) and
// `panel`. Cards, filters and tools have one part, `component`.
//
// Keys:
//   - extension keys look like `vendor/name`; the first registration wins, later ones are rejected (console.error);
//   - keys without `/` are reserved for the panel. An extension may replace the UI of a short list of built-in
//     keys (OVERRIDABLE) with an explicit `override: true`. The built-in entry stays next to the override, so a
//     failed or crashed override simply falls back to it.

export const KINDS = ['fields', 'cards', 'filters', 'tools']
export const FIELD_PARTS = ['form', 'display', 'index', 'detail', 'panel']
export const OVERRIDE_FIELD_PARTS = ['form', 'display', 'index', 'detail']
export const KEY_PATTERN = /^[a-z0-9-]+\/[a-z0-9-]+$/

// Built-in keys whose props, events and value shapes are a stable contract (design §5.6, Q-70).
export const OVERRIDABLE = Object.freeze({
    fields: ['text-field', 'password-field', 'textarea-field', 'number-field', 'boolean-field', 'select-field', 'date-field', 'datetime-field'],
    filters: ['select-filter', 'boolean-filter', 'date-range-filter'],
    cards: ['value-metric', 'trend-metric', 'partition-metric', 'progress-metric'],
    tools: [],
})

const PREFIX = '[larsadmin]'

// `vendor/name` keys belong to extensions; anything else is a panel key.
export function isExtensionKey(key) {
    return typeof key === 'string' && key.includes('/')
}

function isComponent(value) {
    return value !== null && (typeof value === 'object' || typeof value === 'function')
}

const raw = (component) => (component && typeof component === 'object' ? markRaw(component) : component)

// Normalizes what a caller passed into { part: component }. Fields take an object of parts; the other kinds take
// a component or `{ component }`. Unknown parts are dropped.
function partsOf(kind, entry) {
    const parts = {}

    if (kind === 'fields') {
        for (const part of FIELD_PARTS) {
            if (isComponent(entry?.[part])) parts[part] = raw(entry[part])
        }

        return parts
    }

    // Object form `{ component, override }`; a Vue component itself never has a `component` option.
    const component = entry && typeof entry === 'object' && isComponent(entry.component) ? entry.component : entry

    if (isComponent(component)) parts.component = raw(component)

    return parts
}

export function createRegistry({ logger = console } = {}) {
    const builtins = {}
    const entries = {}
    const overrides = {}

    for (const kind of KINDS) {
        builtins[kind] = shallowReactive({})
        entries[kind] = shallowReactive({})
        overrides[kind] = shallowReactive({})
    }

    // Override journal (design §5.6): { kind, key, part, extension }.
    const overrideLog = shallowReactive([])
    // Parts that threw while rendering, `${kind}|${key}|${part}` -> extension name; they stay off for the page session.
    const crashed = shallowReactive({})
    // name -> { status: 'loading' | 'loaded' | 'failed', reason, requires }
    const extensions = reactive({})

    function assertKind(kind) {
        if (!KINDS.includes(kind)) throw new TypeError(`${PREFIX} Unknown registry kind "${kind}".`)
    }

    function registerBuiltin(kind, key, parts) {
        // Built-ins merge part by part (registerField() of 1.4 could set `display` and `form` in separate calls).
        builtins[kind][key] = { ...(builtins[kind][key] ?? {}), ...parts }

        return true
    }

    function registerOverride(kind, key, parts, extension) {
        if (!OVERRIDABLE[kind].includes(key)) {
            logger.warn(`${PREFIX} Extension "${extension}": the built-in ${kind} key "${key}" cannot be overridden.`)

            return false
        }

        if (kind === 'fields' && parts.panel) {
            logger.warn(`${PREFIX} Extension "${extension}": the "panel" part of "${key}" cannot be overridden.`)
            delete parts.panel
        }

        const current = { ...(overrides[kind][key] ?? {}) }
        let applied = false

        for (const [part, component] of Object.entries(parts)) {
            if (current[part]) {
                logger.error(`${PREFIX} Extension "${extension}": "${key}" (${part}) is already overridden by "${current[part].extension}".`)
                continue
            }

            current[part] = { component, extension }
            overrideLog.push({ kind, key, part, extension })
            applied = true
        }

        if (applied) overrides[kind][key] = current

        return applied
    }

    /**
     * register(kind, key, entry, { builtin, extension, override }) -> boolean (false = rejected)
     */
    function register(kind, key, entry, { builtin = false, extension = null, override = false } = {}) {
        assertKind(kind)

        const parts = partsOf(kind, entry)
        const owner = extension ?? 'unknown'

        if (Object.keys(parts).length === 0) {
            logger.error(`${PREFIX} ${builtin ? 'Built-in' : `Extension "${owner}":`} "${key}" has no component.`)

            return false
        }

        if (builtin) return registerBuiltin(kind, key, parts)

        if (!isExtensionKey(key)) {
            const reserved = key in builtins[kind] || OVERRIDABLE[kind].includes(key)

            if (!reserved) {
                logger.error(`${PREFIX} Extension "${owner}": key "${key}" must look like "vendor/name".`)

                return false
            }

            if (!override) {
                logger.warn(`${PREFIX} Extension "${owner}": "${key}" is a built-in key; use override: true to replace its UI.`)

                return false
            }

            return registerOverride(kind, key, parts, owner)
        }

        if (!KEY_PATTERN.test(key)) {
            logger.error(`${PREFIX} Extension "${owner}": key "${key}" must match ${KEY_PATTERN}.`)

            return false
        }

        if (entries[kind][key]) {
            logger.error(`${PREFIX} Extension "${owner}": key "${key}" is already registered by "${entries[kind][key].extension}".`)

            return false
        }

        entries[kind][key] = { parts, extension: owner }

        return true
    }

    // Removes everything an extension registered (failed `setup`, design §5.3 step 4).
    function unregisterExtension(name) {
        for (const kind of KINDS) {
            for (const [key, entry] of Object.entries(entries[kind])) {
                if (entry.extension === name) delete entries[kind][key]
            }

            for (const [key, parts] of Object.entries(overrides[kind])) {
                const kept = Object.fromEntries(Object.entries(parts).filter(([, value]) => value.extension !== name))

                if (Object.keys(kept).length === Object.keys(parts).length) continue
                if (Object.keys(kept).length) overrides[kind][key] = kept
                else delete overrides[kind][key]
            }
        }

        for (let index = overrideLog.length - 1; index >= 0; index--) {
            if (overrideLog[index].extension === name) overrideLog.splice(index, 1)
        }
    }

    const crashKey = (kind, key, part) => `${kind}|${key}|${part}`

    function markCrashed(kind, key, part = 'component') {
        crashed[crashKey(kind, key, part)] = true
    }

    function isCrashed(kind, key, part = 'component') {
        return crashed[crashKey(kind, key, part)] === true
    }

    // Parts to try, in order: `index`/`detail` fall back to `display`.
    function candidates(kind, part) {
        if (kind !== 'fields') return ['component']
        if (part === 'index' || part === 'detail') return [part, 'display']

        return [part]
    }

    function builtinFor(kind, key, part) {
        const entry = builtins[kind][key]

        if (!entry) return null

        for (const candidate of candidates(kind, part)) {
            if (entry[candidate]) return entry[candidate]
        }

        return null
    }

    /**
     * resolve(kind, key, part) -> { component, source: 'builtin' | 'extension' | 'override', extension, builtin } | null
     * `builtin` is the built-in component an override falls back to (null for extension keys).
     */
    function resolve(kind, key, part = kind === 'fields' ? 'display' : 'component') {
        assertKind(kind)

        const builtin = builtinFor(kind, key, part)
        const override = overrides[kind][key]

        if (override) {
            for (const candidate of candidates(kind, part)) {
                const found = override[candidate]

                if (found && !isCrashed(kind, key, candidate)) {
                    return { component: found.component, source: 'override', extension: found.extension, builtin, part: candidate }
                }

                // A display override that crashed must not hide a more specific built-in part.
                if (found) break
            }
        }

        const entry = entries[kind][key]

        if (entry) {
            for (const candidate of candidates(kind, part)) {
                if (entry.parts[candidate]) {
                    return { component: entry.parts[candidate], source: 'extension', extension: entry.extension, builtin: null, part: candidate }
                }
            }

            return null
        }

        return builtin ? { component: builtin, source: 'builtin', extension: null, builtin: null, part } : null
    }

    function has(kind, key) {
        return Boolean(entries[kind]?.[key] || builtins[kind]?.[key])
    }

    function setExtensionState(name, status, details = {}) {
        extensions[name] = { ...(extensions[name] ?? {}), status, reason: details.reason ?? null, ...details }
    }

    function extensionState(name) {
        return extensions[name] ?? null
    }

    // True while at least one extension is still loading: unknown `vendor/name` keys show a skeleton meanwhile.
    function isLoading() {
        return Object.values(extensions).some((state) => state.status === 'loading')
    }

    // Extension component is missing or crashed: the form must not submit its value (fail closed, ADR-032).
    function isUnavailable(kind, key, part) {
        if (!isExtensionKey(key)) return false

        const resolved = resolve(kind, key, part)

        return !resolved || isCrashed(kind, key, resolved.part)
    }

    return {
        register,
        unregisterExtension,
        resolve,
        has,
        markCrashed,
        isCrashed,
        isUnavailable,
        setExtensionState,
        extensionState,
        isLoading,
        extensions,
        overrides: overrideLog,
    }
}

// The panel's single registry instance (shared by app.js and the SDK chunk).
export const registry = createRegistry()
