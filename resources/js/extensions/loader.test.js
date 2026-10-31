// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createRegistry } from './registry'
import { createLoader } from './loader'
import { defineExtension } from './define'

const component = (name) => ({ name, render: () => null })
const BuiltinDate = component('BuiltinDate')

let logger
let registry
let modules
let notify

// Fake `import()`: URL -> module (or a thrown error).
function importer(url) {
    const module = modules[url]

    if (module instanceof Error) return Promise.reject(module)
    if (module === undefined) return Promise.reject(new TypeError(`Failed to fetch dynamically imported module: ${url}`))

    return Promise.resolve(module)
}

function makeLoader(options = {}) {
    return createLoader({ registry, importer, logger, notify, base: () => 'http://panel.test/admin', apiVersion: '1.5', ...options })
}

const item = (name, extra = {}) => ({ name, script: `/admin/extensions/${name}/abc/extension.js`, style: null, ...extra })

beforeEach(() => {
    document.head.innerHTML = ''
    logger = { error: vi.fn(), warn: vi.fn(), info: vi.fn() }
    registry = createRegistry({ logger })
    registry.register('fields', 'date-field', { form: BuiltinDate }, { builtin: true })
    modules = {}
    notify = vi.fn()
})

describe('loader', () => {
    it('loads a defineExtension module, registers its components and runs setup once with the app', async () => {
        const Rating = component('Rating')
        const setup = vi.fn()

        modules['/admin/extensions/acme/rating/abc/extension.js'] = {
            default: defineExtension({ name: 'acme/rating', requires: '^1.5', fields: { 'acme/rating': { form: Rating } }, setup }),
        }

        const loader = makeLoader()
        await loader.load([item('acme/rating')])

        expect(registry.resolve('fields', 'acme/rating', 'form').component).toBe(Rating)
        expect(registry.extensionState('acme/rating').status).toBe('loaded')
        expect(setup).not.toHaveBeenCalled()

        const app = { component: vi.fn() }
        loader.attach(app)

        expect(setup).toHaveBeenCalledTimes(1)
        expect(setup).toHaveBeenCalledWith({ app })
    })

    it('marks an extension failed when the import fails, without a toast outside debug', async () => {
        modules['/admin/extensions/acme/broken/abc/extension.js'] = new SyntaxError('Unexpected token')

        await makeLoader().load([item('acme/broken')])

        expect(registry.extensionState('acme/broken')).toMatchObject({ status: 'failed', reason: 'import' })
        expect(logger.error).toHaveBeenCalledWith(expect.stringContaining('acme/broken'), expect.any(SyntaxError))
        expect(notify).not.toHaveBeenCalled()
    })

    it('notifies (toast) only in debug mode', async () => {
        await makeLoader({ isDebug: () => true }).load([item('acme/missing')])

        expect(notify).toHaveBeenCalledWith('acme/missing', 'import', expect.any(Object))
    })

    it('skips an incompatible extension (requires mismatch) and keeps its fields unavailable', async () => {
        modules['/admin/extensions/acme/future/abc/extension.js'] = {
            default: defineExtension({ name: 'acme/future', requires: '^1.9', fields: { 'acme/future': { form: component('F') } } }),
        }

        await makeLoader({ isDebug: () => true }).load([item('acme/future')])

        expect(registry.extensionState('acme/future')).toMatchObject({ status: 'failed', reason: 'incompatible', requires: '^1.9', version: '1.5' })
        expect(registry.resolve('fields', 'acme/future', 'form')).toBeNull()
        expect(registry.isUnavailable('fields', 'acme/future', 'form')).toBe(true)
        expect(notify).toHaveBeenCalledWith('acme/future', 'incompatible', expect.objectContaining({ requires: '^1.9', version: '1.5' }))
    })

    it('rejects a module without a defineExtension default export', async () => {
        modules['/admin/extensions/acme/plain/abc/extension.js'] = { default: { name: 'acme/plain', requires: '^1.5' } }

        await makeLoader().load([item('acme/plain')])

        expect(registry.extensionState('acme/plain')).toMatchObject({ status: 'failed', reason: 'invalid' })
    })

    it('rejects a module whose name differs from the server name', async () => {
        modules['/admin/extensions/acme/a/abc/extension.js'] = { default: defineExtension({ name: 'acme/b', requires: '^1.5', cards: { 'acme/b': component('B') } }) }

        await makeLoader().load([item('acme/a')])

        expect(registry.extensionState('acme/a')).toMatchObject({ status: 'failed', reason: 'name' })
        expect(registry.resolve('cards', 'acme/b')).toBeNull()
    })

    it('rejects scripts and styles from another origin', async () => {
        await makeLoader().load([item('acme/remote', { script: 'https://cdn.example.com/x.js' })])

        expect(registry.extensionState('acme/remote')).toMatchObject({ status: 'failed', reason: 'url' })
    })

    it('unregisters every key when setup throws', async () => {
        modules['/admin/extensions/acme/setup/abc/extension.js'] = {
            default: defineExtension({
                name: 'acme/setup',
                requires: '^1.5',
                fields: { 'acme/setup': { form: component('S') }, 'date-field': { form: component('Fancy'), override: true } },
                setup() {
                    throw new Error('boom')
                },
            }),
        }

        const loader = makeLoader()
        loader.attach({})
        await loader.load([item('acme/setup')])

        expect(registry.extensionState('acme/setup')).toMatchObject({ status: 'failed', reason: 'setup' })
        expect(registry.resolve('fields', 'acme/setup', 'form')).toBeNull()
        expect(registry.resolve('fields', 'date-field', 'form').component).toBe(BuiltinDate)
    })

    it('isolates failures: other extensions still load', async () => {
        const Good = component('Good')

        modules['/admin/extensions/acme/good/abc/extension.js'] = { default: defineExtension({ name: 'acme/good', requires: '^1.5', cards: { 'acme/good': Good } }) }

        await makeLoader().load([item('acme/bad'), item('acme/good')])

        expect(registry.extensionState('acme/bad').status).toBe('failed')
        expect(registry.resolve('cards', 'acme/good').component).toBe(Good)
    })

    it('loads each extension once and picks up new ones after a navigation', async () => {
        const spy = vi.fn(importer)
        modules['/admin/extensions/acme/one/abc/extension.js'] = { default: defineExtension({ name: 'acme/one', requires: '^1.5' }) }
        modules['/admin/extensions/acme/two/abc/extension.js'] = { default: defineExtension({ name: 'acme/two', requires: '^1.5' }) }

        const loader = makeLoader({ importer: spy })
        await loader.load([item('acme/one')])
        await loader.load([item('acme/one'), item('acme/two')])

        expect(spy).toHaveBeenCalledTimes(2)
        expect(registry.extensionState('acme/two').status).toBe('loaded')
    })

    it('adds each stylesheet once', async () => {
        modules['/admin/extensions/acme/css/abc/extension.js'] = { default: defineExtension({ name: 'acme/css', requires: '^1.5' }) }
        const style = '/admin/extensions/acme/css/abc/extension.css'

        const loader = makeLoader()
        await loader.load([item('acme/css', { style })])
        await loader.load([item('acme/css', { style })])

        const links = document.head.querySelectorAll('link[rel="stylesheet"]')
        expect(links).toHaveLength(1)
        expect(links[0].getAttribute('href')).toBe(style)
        expect(links[0].dataset.larsadminExtension).toBe('acme/css')
    })

    it('stops waiting after the timeout; the late extension still registers', async () => {
        vi.useFakeTimers()

        let resolveModule
        const Late = component('Late')
        const slowImporter = () => new Promise((resolve) => (resolveModule = resolve))
        const loader = makeLoader({ importer: slowImporter, timeout: 5000 })

        let done = false
        loader.load([item('acme/late')]).then(() => (done = true))

        expect(registry.isLoading()).toBe(true)
        await vi.advanceTimersByTimeAsync(5000)
        expect(done).toBe(true)
        expect(registry.isLoading()).toBe(true)

        resolveModule({ default: defineExtension({ name: 'acme/late', requires: '^1.5', cards: { 'acme/late': Late } }) })
        await vi.runAllTimersAsync()

        expect(registry.resolve('cards', 'acme/late').component).toBe(Late)
        expect(registry.isLoading()).toBe(false)
        vi.useRealTimers()
    })

    it('registers in server list order whatever order the imports finish in (SECX-07)', async () => {
        const First = component('First')
        const Second = component('Second')
        const resolvers = {}
        const deferred = (url) => new Promise((resolve) => (resolvers[url] = resolve))
        const url = (name) => `/admin/extensions/${name}/abc/extension.js`

        const loader = makeLoader({ importer: deferred })
        const done = loader.load([item('acme/first'), item('acme/second')])
        await Promise.resolve()

        // The second import finishes first.
        resolvers[url('acme/second')]({ default: defineExtension({ name: 'acme/second', requires: '^1.5', cards: { 'acme/shared': Second }, fields: { 'date-field': { form: Second, override: true } } }) })
        await Promise.resolve()
        await new Promise((resolve) => setTimeout(resolve, 0))
        expect(registry.resolve('cards', 'acme/shared')).toBeNull()

        resolvers[url('acme/first')]({ default: defineExtension({ name: 'acme/first', requires: '^1.5', cards: { 'acme/shared': First }, fields: { 'date-field': { form: First, override: true } } }) })
        await done

        expect(registry.resolve('cards', 'acme/shared').component).toBe(First)
        expect(registry.resolve('fields', 'date-field', 'form').component).toBe(First)
        expect(registry.extensionState('acme/second').status).toBe('loaded')
    })

    it('fails an import that never settles as timeout (CRX-08)', async () => {
        vi.useFakeTimers()

        const loader = makeLoader({ importer: () => new Promise(() => {}), timeout: 5000, importTimeout: 30000 })
        loader.load([item('acme/stalled')])

        await vi.advanceTimersByTimeAsync(29000)
        expect(registry.isLoading()).toBe(true)

        await vi.advanceTimersByTimeAsync(1000)
        expect(registry.extensionState('acme/stalled')).toMatchObject({ status: 'failed', reason: 'timeout' })
        expect(registry.isLoading()).toBe(false)
        vi.useRealTimers()
    })

    it('logs overrides in debug mode', async () => {
        modules['/admin/extensions/acme/over/abc/extension.js'] = {
            default: defineExtension({ name: 'acme/over', requires: '^1.5', fields: { 'date-field': { form: component('Fancy'), override: true } } }),
        }

        await makeLoader({ isDebug: () => true }).load([item('acme/over')])

        expect(logger.info).toHaveBeenCalledWith(expect.stringContaining('fields date-field (form) <- acme/over'))
    })
})
