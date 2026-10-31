import { isExtensionDefinition } from './define.js'
import { API_VERSION, satisfies } from './version.js'

// Extension loader (design 1.5 §5.3). The server lists the extensions the current user may load in the shared
// prop `larsadmin.extensions` = [{ name, script, style }]. Each one is imported once; failures are isolated:
// a failed extension is marked `failed`, its keys stay unregistered (fields fail closed) and the rest works.
//
// Failure reasons: 'import' (network / syntax), 'invalid' (no defineExtension() default export), 'name' (the
// module's name differs from the server's), 'incompatible' (requires), 'setup' (setup() threw), 'url'.

const PREFIX = '[larsadmin]'
const KINDS = ['fields', 'cards', 'filters', 'tools']

// Same-origin URLs only: extensions are local files served by the panel (no CDN, design §1).
function sameOrigin(url, base) {
    try {
        return new URL(url, base).origin === new URL(base).origin
    } catch {
        return false
    }
}

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

export function createLoader({
    registry,
    importer = (url) => import(/* @vite-ignore */ url),
    apiVersion = API_VERSION,
    logger = console,
    document: doc = typeof document === 'undefined' ? null : document,
    base = () => (typeof window === 'undefined' ? 'http://localhost/' : window.location.href),
    timeout = 5000,
    // An import that never settles (stalled proxy) fails after this many ms (CRX-08)
    importTimeout = 30000,
    isDebug = () => false,
    // (name, reason, details) -> void; called only in debug mode (Q-71)
    notify = () => {},
} = {}) {
    const started = new Map() // name -> promise
    const styles = new Set()
    const queuedSetups = [] // definitions registered before the Vue app existed
    let app = null

    function fail(name, reason, error = null, details = {}) {
        registry.unregisterExtension(name)
        registry.setExtensionState(name, 'failed', { reason, ...details })

        const message = details.message ?? reason

        if (error) logger.error(`${PREFIX} Extension "${name}" was not loaded (${message}).`, error)
        else logger.error(`${PREFIX} Extension "${name}" was not loaded (${message}).`)

        if (isDebug()) notify(name, reason, details)
    }

    function runSetup(definition) {
        if (typeof definition.setup !== 'function') return

        try {
            definition.setup({ app })
        } catch (error) {
            fail(definition.name, 'setup', error, { message: 'setup() threw' })
        }
    }

    function registerDefinition(definition) {
        const name = definition.name

        for (const kind of KINDS) {
            for (const [key, entry] of Object.entries(definition[kind] ?? {})) {
                registry.register(kind, key, entry, { extension: name, override: entry?.override === true })
            }
        }

        registry.setExtensionState(name, 'loaded', { requires: definition.requires })

        if (app) runSetup(definition)
        else queuedSetups.push(definition)
    }

    function addStyle(item) {
        if (!doc || !item.style || styles.has(item.style)) return

        if (!sameOrigin(item.style, base())) {
            logger.error(`${PREFIX} Extension "${item.name}": stylesheet URL is not same-origin, skipped.`)

            return
        }

        styles.add(item.style)

        const link = doc.createElement('link')
        link.rel = 'stylesheet'
        link.href = item.style
        link.dataset.larsadminExtension = item.name
        doc.head.appendChild(link)
    }

    // Starts fetching one extension (stylesheet + module). Resolves to { module } or { failure } and never rejects.
    // An import that does not settle within `importTimeout` ms fails as 'timeout' (CRX-08), so unknown keys do not
    // show a loading skeleton forever.
    function fetchOne(item) {
        registry.setExtensionState(item.name, 'loading')

        if (typeof item.script !== 'string' || !sameOrigin(item.script, base())) {
            return Promise.resolve({ failure: ['url', null, { message: 'the script URL is missing or not same-origin' }] })
        }

        addStyle(item)

        let timer = null
        const stalled = new Promise((resolve) => {
            if (importTimeout > 0) {
                timer = setTimeout(() => resolve({ failure: ['timeout', null, { message: `the import did not finish within ${importTimeout / 1000} s` }] }), importTimeout)
            }
        })
        const imported = Promise.resolve()
            .then(() => importer(item.script))
            .then((module) => ({ module }), (error) => ({ failure: ['import', error, { message: 'import failed' }] }))

        return Promise.race([imported, stalled]).finally(() => clearTimeout(timer))
    }

    // Validates and registers one fetched extension.
    function settle(item, { module, failure }) {
        const name = item.name

        if (failure) {
            fail(name, ...failure)

            return
        }

        const definition = module?.default

        if (!isExtensionDefinition(definition)) {
            fail(name, 'invalid', null, { message: 'the default export is not defineExtension({...})' })

            return
        }

        if (definition.name !== name) {
            fail(name, 'name', null, { message: `the module is named "${definition.name}"` })

            return
        }

        if (!satisfies(definition.requires, apiVersion)) {
            fail(name, 'incompatible', null, {
                message: `requires ${definition.requires}, the panel API is ${apiVersion}`,
                requires: definition.requires,
                version: apiVersion,
            })

            return
        }

        registerDefinition(definition)
    }

    // Registers in the server's list order (registration order in PHP), whatever order the imports finish in, so the
    // "first registration wins" rule for colliding keys and override parts is deterministic (SECX-07). Imports
    // still run in parallel; an extension waits only for the ones listed before it.
    let queue = Promise.resolve()

    /**
     * Starts loading every listed extension that was not started yet. Resolves when all of them settled, or after
     * `timeout` ms (the panel mounts anyway; late extensions replace their placeholders when they arrive).
     */
    function load(list) {
        const items = (Array.isArray(list) ? list : []).filter((item) => item && typeof item.name === 'string' && !started.has(item.name))

        if (items.length === 0) return Promise.resolve()

        const fetched = items.map((item) => fetchOne(item))
        const promises = items.map((item, index) => {
            queue = queue.then(() => fetched[index]).then((result) => settle(item, result)).catch((error) => fail(item.name, 'import', error))
            started.set(item.name, queue)

            return queue
        })

        const settled = Promise.allSettled(promises).then(() => logOverrides())

        return timeout > 0 ? Promise.race([settled, delay(timeout)]) : settled
    }

    let logged = 0

    // Debug: which extension replaced which built-in UI (design §5.6).
    function logOverrides() {
        if (!isDebug() || registry.overrides.length === logged) return

        logged = registry.overrides.length
        logger.info(
            `${PREFIX} Built-in components overridden by extensions:\n` +
                registry.overrides.map(({ kind, key, part, extension }) => `  ${kind} ${key} (${part}) <- ${extension}`).join('\n'),
        )
    }

    // The Vue app exists: run the setup() hooks of extensions that loaded before it.
    function attach(vueApp) {
        app = vueApp

        for (const definition of queuedSetups.splice(0)) {
            if (registry.extensionState(definition.name)?.status === 'loaded') runSetup(definition)
        }
    }

    return { load, attach }
}
