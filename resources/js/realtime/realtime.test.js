import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { authEndpoint, channelName, createAuthorizer, createRealtime, echoOptions, isPanelPath, SIGNAL_EVENT } from './realtime'

// A fake Echo: records the options, the private channel, the listener and leave/disconnect.
const fake = vi.hoisted(() => {
    const state = { instances: [] }

    function createEcho(options) {
        const handlers = {}
        const stateListeners = []
        const echo = {
            options,
            channels: [],
            left: [],
            disconnected: false,
            private(name) {
                const channel = {
                    name,
                    listen(event, handler) {
                        handlers[event] = handler

                        return channel
                    },
                    subscribed(callback) {
                        handlers['pusher:subscription_succeeded'] = callback

                        return channel
                    },
                    error(callback) {
                        handlers['pusher:subscription_error'] = callback

                        return channel
                    },
                }
                echo.channels.push(channel)

                return channel
            },
            leave(name) {
                echo.left.push(name)
            },
            disconnect() {
                echo.disconnected = true
            },
            connector: { pusher: { connection: { bind: (event, callback) => event === 'state_change' && stateListeners.push(callback) } } },
            emit(event, payload) {
                handlers[event]?.(payload)
            },
            setState(current, previous) {
                for (const listener of stateListeners) listener({ current, previous })
            },
        }
        state.instances.push(echo)

        return echo
    }

    return { state, createEcho: vi.fn(createEcho) }
})

vi.mock('./echo.js', () => ({ createEcho: fake.createEcho }))

const CONFIG = {
    broadcaster: 'reverb',
    key: 'app-key',
    wsHost: 'ws.app.test',
    wsPort: 8080,
    wssPort: 443,
    forceTLS: true,
    authEndpoint: '/admin/broadcasting/auth',
    channel: 'larsa_inbox.0123456789abcdef0123456789abcdef01234567',
    basePath: '/admin',
}

const at = (pathname) => () => ({ pathname })

describe('helpers', () => {
    it('isPanelPath: the panel path and below only', () => {
        expect(isPanelPath('/admin', '/admin')).toBe(true)
        expect(isPanelPath('/admin/entities/posts', '/admin/')).toBe(true)
        expect(isPanelPath('/administrator', '/admin')).toBe(false)
        expect(isPanelPath('/', '/admin')).toBe(false)
        expect(isPanelPath('/admin', '//evil')).toBe(false)
        expect(isPanelPath('/admin', null)).toBe(false)
    })

    it('channelName: accepts only prefixed package channels, without "private-"', () => {
        expect(channelName(CONFIG)).toBe(CONFIG.channel)
        expect(channelName({ channel: `private-${CONFIG.channel}` })).toBe(CONFIG.channel)
        expect(channelName({ prefix: 'larsa_', token: 'abc123' })).toBe('larsa_inbox.abc123')
        expect(channelName({ prefix: 'acme_', channel: 'acme_inbox.abc' })).toBe('acme_inbox.abc')
        expect(channelName({ prefix: 'larsa_', channel: 'other_inbox.abc' })).toBeNull()
        expect(channelName({ channel: 'App.Models.User.1' })).toBeNull()
        expect(channelName({ channel: 'presence-larsa_inbox.x' })).toBeNull()
        expect(channelName({ prefix: 'Bad', token: 'x' })).toBeNull()
        expect(channelName({})).toBeNull()
    })

    it('authEndpoint: the panel endpoint, never a foreign one', () => {
        expect(authEndpoint(CONFIG)).toBe('/admin/broadcasting/auth')
        expect(authEndpoint({ ...CONFIG, authEndpoint: '/broadcasting/auth' })).toBe('/admin/broadcasting/auth')
        expect(authEndpoint({ ...CONFIG, authEndpoint: 'https://evil.test/admin/broadcasting/auth' })).toBe('/admin/broadcasting/auth')
        expect(authEndpoint({ ...CONFIG, authEndpoint: undefined })).toBe('/admin/broadcasting/auth')
        expect(authEndpoint({ authEndpoint: '/admin/broadcasting/auth' })).toBeNull()
    })

    it('echoOptions: public values only, WebSocket transports, explicit authorizer', () => {
        const authorizer = () => {}
        const options = echoOptions({ ...CONFIG, secret: 'nope' }, authorizer)

        expect(options).toMatchObject({
            broadcaster: 'reverb',
            key: 'app-key',
            wsHost: 'ws.app.test',
            wsPort: 8080,
            wssPort: 443,
            forceTLS: true,
            enabledTransports: ['ws', 'wss'],
            channelAuthorization: { customHandler: authorizer },
        })
        expect(options).not.toHaveProperty('secret')
        expect(echoOptions({ broadcaster: 'pusher', key: 'k', cluster: 'eu' }, authorizer)).toMatchObject({ cluster: 'eu', forceTLS: true })
    })
})

describe('createAuthorizer', () => {
    it('POSTs socket_id + channel_name with the XSRF cookie to the panel endpoint', async () => {
        const fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ auth: 'key:sig' }) }))
        const authorize = createAuthorizer({ endpoint: '/admin/broadcasting/auth', expectedChannel: 'larsa_inbox.t', fetch, cookie: () => 'XSRF-TOKEN=tok%3D' })
        const callback = vi.fn()

        await authorize({ socketId: '1.2', channelName: 'private-larsa_inbox.t' }, callback)

        expect(callback).toHaveBeenCalledWith(null, { auth: 'key:sig' })

        const [url, init] = fetch.mock.calls[0]

        expect(url).toBe('/admin/broadcasting/auth')
        expect(init.method).toBe('POST')
        expect(init.headers['X-XSRF-TOKEN']).toBe('tok=')
        expect(init.body).toBe('socket_id=1.2&channel_name=private-larsa_inbox.t')
    })

    it('refuses other channels without a request, and reports HTTP failures', async () => {
        const fetch = vi.fn(async () => ({ ok: false, status: 403, json: async () => ({}) }))
        const authorize = createAuthorizer({ endpoint: '/admin/broadcasting/auth', expectedChannel: 'larsa_inbox.t', fetch, cookie: () => '' })
        const callback = vi.fn()

        await authorize({ socketId: '1.2', channelName: 'private-App.Models.User.1' }, callback)
        expect(fetch).not.toHaveBeenCalled()
        expect(callback.mock.calls[0][0]).toBeInstanceOf(Error)

        await authorize({ socketId: '1.2', channelName: 'private-larsa_inbox.t' }, callback)
        expect(fetch).toHaveBeenCalledOnce()
        expect(callback.mock.calls[1][0]).toBeInstanceOf(Error)
        expect(callback.mock.calls[1][1]).toBeNull()
    })
})

describe('createRealtime', () => {
    beforeEach(() => {
        fake.state.instances.length = 0
        fake.createEcho.mockClear()
    })

    afterEach(() => {
        delete globalThis.window
    })

    it('subscribes to the private package channel and forwards signals', async () => {
        const realtime = createRealtime({ location: at('/admin/entities/posts') })
        const onSignal = vi.fn()

        expect(await realtime.connect(CONFIG, onSignal)).toBe(true)

        const echo = fake.state.instances[0]

        expect(echo.channels.map((channel) => channel.name)).toEqual([CONFIG.channel])
        expect(echo.options.channelAuthorization.customHandler).toBeTypeOf('function')
        expect(echo.options.enabledTransports).toEqual(['ws', 'wss'])

        echo.emit(SIGNAL_EVENT, { kind: 'notification', id: 'n1' })
        expect(onSignal).toHaveBeenCalledWith({ kind: 'notification', id: 'n1' })
        expect(SIGNAL_EVENT).toBe('.larsadmin.signal')
    })

    it('reports "connected" only once the socket is up AND the channel is subscribed (CRN-05)', async () => {
        const realtime = createRealtime({ location: at('/admin') })
        const onState = vi.fn()

        await realtime.connect(CONFIG, () => {}, { onState })

        const echo = fake.state.instances[0]

        echo.setState('connected', 'connecting')
        expect(onState).not.toHaveBeenCalled() // socket up, channel not authorized yet

        echo.emit('pusher:subscription_succeeded')
        expect(onState).toHaveBeenLastCalledWith('connected')
    })

    it('a failed channel authorization (403/419) reports "failed", never "connected"', async () => {
        const realtime = createRealtime({ location: at('/admin') })
        const onState = vi.fn()

        await realtime.connect(CONFIG, () => {}, { onState })

        const echo = fake.state.instances[0]

        echo.setState('connected', 'connecting')
        echo.emit('pusher:subscription_error', { status: 403 })

        expect(onState.mock.calls.map(([state]) => state)).toEqual(['failed'])
    })

    it('a dropped socket reports "disconnected"; the re-subscription after reconnect reports "connected" again', async () => {
        const realtime = createRealtime({ location: at('/admin') })
        const onState = vi.fn()

        await realtime.connect(CONFIG, () => {}, { onState })

        const echo = fake.state.instances[0]

        echo.setState('connected', 'connecting')
        echo.emit('pusher:subscription_succeeded')
        echo.setState('unavailable', 'connected')
        echo.setState('connected', 'connecting') // not yet re-subscribed
        echo.emit('pusher:subscription_succeeded')

        expect(onState.mock.calls.map(([state]) => state)).toEqual(['connected', 'disconnected', 'connected'])
    })

    it('ignores channel callbacks after disconnect', async () => {
        const realtime = createRealtime({ location: at('/admin') })
        const onState = vi.fn()

        await realtime.connect(CONFIG, () => {}, { onState })

        const echo = fake.state.instances[0]

        realtime.disconnect()
        echo.setState('connected', 'connecting')
        echo.emit('pusher:subscription_succeeded')

        expect(onState).not.toHaveBeenCalled()
    })

    it('never connects outside the panel, without a usable config or with a foreign channel', async () => {
        expect(await createRealtime({ location: at('/') }).connect(CONFIG, () => {})).toBe(false)
        expect(await createRealtime({ location: at('/administrator') }).connect(CONFIG, () => {})).toBe(false)
        expect(await createRealtime({ location: at('/admin') }).connect(null, () => {})).toBe(false)
        expect(await createRealtime({ location: at('/admin') }).connect({ ...CONFIG, broadcaster: 'ably' }, () => {})).toBe(false)
        expect(await createRealtime({ location: at('/admin') }).connect({ ...CONFIG, key: '' }, () => {})).toBe(false)
        expect(await createRealtime({ location: at('/admin') }).connect({ ...CONFIG, channel: 'App.Models.User.1' }, () => {})).toBe(false)
        expect(fake.createEcho).not.toHaveBeenCalled()
    })

    it('connects once per channel and disconnects (leave + disconnect)', async () => {
        const realtime = createRealtime({ location: at('/admin') })

        await Promise.all([realtime.connect(CONFIG, () => {}), realtime.connect(CONFIG, () => {})])
        await realtime.connect(CONFIG, () => {})
        expect(fake.createEcho).toHaveBeenCalledOnce()
        expect(realtime.connected).toBe(true)

        realtime.disconnect()

        const echo = fake.state.instances[0]

        expect(echo.left).toEqual([CONFIG.channel])
        expect(echo.disconnected).toBe(true)
        expect(realtime.connected).toBe(false)
    })

    it('a disconnect while the chunk loads cancels the connection', async () => {
        let release
        const gate = new Promise((resolve) => (release = resolve))
        const realtime = createRealtime({ location: at('/admin'), load: () => gate.then(() => ({ createEcho: fake.createEcho })) })
        const pending = realtime.connect(CONFIG, () => {})

        realtime.disconnect()
        release()

        expect(await pending).toBe(false)
        expect(fake.createEcho).not.toHaveBeenCalled()
    })

    it('resolves false when the chunk fails to load', async () => {
        const realtime = createRealtime({ location: at('/admin'), load: () => Promise.reject(new Error('offline')) })

        expect(await realtime.connect(CONFIG, () => {})).toBe(false)
    })

    it('never reads or writes window.Echo / window.Pusher', async () => {
        const host = {}
        const echoGetter = vi.fn(() => 'host-echo')
        const pusherGetter = vi.fn(() => 'host-pusher')

        Object.defineProperty(host, 'Echo', { get: echoGetter, set: () => { throw new Error('write window.Echo') }, configurable: true })
        Object.defineProperty(host, 'Pusher', { get: pusherGetter, set: () => { throw new Error('write window.Pusher') }, configurable: true })
        globalThis.window = host

        const realtime = createRealtime({ location: at('/admin') })

        await realtime.connect(CONFIG, () => {})
        realtime.disconnect()

        expect(echoGetter).not.toHaveBeenCalled()
        expect(pusherGetter).not.toHaveBeenCalled()
    })
})

describe('pusherGuard', () => {
    afterEach(() => {
        delete globalThis.window
        vi.resetModules()
    })

    it('puts back the page window.Pusher that pusher-js overwrote while loading', async () => {
        globalThis.window = { Pusher: 'host-pusher' }
        vi.resetModules()

        const { restoreWindowPusher } = await import('./pusherGuard.js')

        window.Pusher = 'library-pusher' // what pusher-js's runtime setup does
        restoreWindowPusher()
        expect(window.Pusher).toBe('host-pusher')
    })

    it('removes window.Pusher when the page had none', async () => {
        globalThis.window = {}
        vi.resetModules()

        const { restoreWindowPusher } = await import('./pusherGuard.js')

        window.Pusher = 'library-pusher'
        restoreWindowPusher()
        expect('Pusher' in window).toBe(false)
    })
})
