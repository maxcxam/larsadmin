import { isSafePath } from '../components/search/palette'
import { readCookie } from '../composables/useJsonRequest'

// The panel's own realtime connection (ADR-027). It exists only on panel pages, subscribes only to the
// panel's `{prefix}…` private channel and authorizes it only through the panel's `{path}/broadcasting/auth`.
// laravel-echo + pusher-js live in ./echo.js, loaded lazily (a separate chunk) on the first connect().
// Nothing here reads or writes window.Echo / window.Pusher.
//
// Server config (shared prop larsadmin.notifications.realtime):
//   { broadcaster: 'reverb'|'pusher', key, cluster?, wsHost?, wsPort?, wssPort?, forceTLS?, authEndpoint,
//     channel: '{prefix}inbox.{token}', basePath: '/admin' }
// `prefix` + `token` are accepted instead of `channel`.

export const SIGNAL_EVENT = '.larsadmin.signal'
export const BROADCASTERS = ['reverb', 'pusher']

const PREFIX = /^[a-z][a-z0-9_]{0,30}_$/
const CHANNEL = /^([a-z][a-z0-9_]{0,30}_)[A-Za-z0-9_.-]{1,160}$/

function normalizeBase(basePath) {
    if (typeof basePath !== 'string' || !isSafePath(basePath)) return null

    return basePath.replace(/\/+$/, '') || '/'
}

// True when `pathname` is the panel path or below it ("/admin", "/admin/…", but not "/administrator").
export function isPanelPath(pathname, basePath) {
    const base = normalizeBase(basePath)

    if (base === null || typeof pathname !== 'string') return false
    if (base === '/') return pathname.startsWith('/')

    return pathname === base || pathname.startsWith(`${base}/`)
}

// The channel name without Echo's "private-" (Echo adds it): "{prefix}inbox.{token}", or null when it does
// not carry a valid package prefix (the client never subscribes to anything else).
export function channelName(config) {
    let name = null

    if (typeof config?.channel === 'string') {
        name = config.channel.replace(/^private-/, '')
    } else if (typeof config?.prefix === 'string' && typeof config?.token === 'string' && PREFIX.test(config.prefix)) {
        name = `${config.prefix}inbox.${config.token}`
    }

    if (!name) return null

    if (!CHANNEL.test(name)) return null
    if (typeof config?.prefix === 'string' && (!PREFIX.test(config.prefix) || !name.startsWith(config.prefix))) return null

    return name
}

// The panel's authorization endpoint: the server value when it is a same-origin path under the panel,
// else "{basePath}/broadcasting/auth".
export function authEndpoint(config) {
    const base = normalizeBase(config?.basePath)
    const fallback = base === null ? null : `${base === '/' ? '' : base}/broadcasting/auth`
    const value = config?.authEndpoint

    if (typeof value === 'string' && isSafePath(value) && base !== null && isPanelPath(value.split('?')[0], base)) return value

    return fallback
}

// Pusher's channelAuthorization.customHandler: POSTs to the panel endpoint with the XSRF cookie and refuses
// (locally) any channel that is not `private-{expected}`.
export function createAuthorizer({ endpoint, expectedChannel, fetch: fetchImpl = (...args) => globalThis.fetch(...args), cookie = () => document.cookie }) {
    return async ({ socketId, channelName: requested }, callback) => {
        if (requested !== `private-${expectedChannel}`) {
            callback(new Error('larsadmin: refusing to authorize a foreign channel'), null)

            return
        }

        try {
            const headers = {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            }
            const token = readCookie('XSRF-TOKEN', cookie())

            if (token) headers['X-XSRF-TOKEN'] = token

            const response = await fetchImpl(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers,
                body: new URLSearchParams({ socket_id: socketId, channel_name: requested }).toString(),
            })

            if (!response.ok) {
                callback(new Error(`larsadmin: channel authorization failed (${response.status})`), null)

                return
            }

            callback(null, await response.json())
        } catch (error) {
            callback(error instanceof Error ? error : new Error('larsadmin: channel authorization failed'), null)
        }
    }
}

// Echo/Pusher options built from the server config (public values only).
export function echoOptions(config, authorizer) {
    const options = {
        broadcaster: config.broadcaster,
        key: config.key,
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        channelAuthorization: { customHandler: authorizer },
    }

    if (config.broadcaster === 'reverb') {
        Object.assign(options, {
            wsHost: config.wsHost,
            wsPort: config.wsPort ?? 80,
            wssPort: config.wssPort ?? config.wsPort ?? 443,
            forceTLS: !!config.forceTLS,
            cluster: config.cluster ?? '',
        })
    } else {
        options.cluster = config.cluster ?? 'mt1'
        options.forceTLS = config.forceTLS ?? true
        if (config.wsHost) Object.assign(options, { wsHost: config.wsHost, wsPort: config.wsPort, wssPort: config.wssPort ?? config.wsPort })
    }

    return options
}

export function isUsableConfig(config) {
    return !!config && typeof config === 'object' && BROADCASTERS.includes(config.broadcaster) && typeof config.key === 'string' && config.key !== ''
}

// One connection per tab. `connect()` resolves to true when subscribed (or already connected to the same
// channel), false when the config is unusable, the page is not a panel page or loading failed.
export function createRealtime({
    load = () => import('./echo.js'),
    location = () => (typeof window === 'undefined' ? null : window.location),
    fetch: fetchImpl,
    cookie,
} = {}) {
    let echo = null
    let channel = null
    let current = null // channel name
    let pending = null
    let generation = 0

    function disconnect() {
        generation++
        pending = null

        if (echo) {
            try {
                if (current) echo.leave(current)
                echo.disconnect()
            } catch {
                // a broken socket must not break logout / navigation
            }
        }

        echo = null
        channel = null
        current = null
    }

    async function connect(config, onSignal, { onState = () => {} } = {}) {
        const name = channelName(config)
        const endpoint = authEndpoint(config)
        const pathname = location()?.pathname

        if (!isUsableConfig(config) || !name || !endpoint || !isPanelPath(pathname, config.basePath)) {
            disconnect()

            return false
        }

        if (echo && current === name) return true
        if (pending && pending.name === name) return pending.promise

        disconnect()

        const mine = ++generation
        const promise = (async () => {
            let module

            try {
                module = await load()
            } catch {
                return false
            }

            if (mine !== generation) return false

            const authorizer = createAuthorizer({ endpoint, expectedChannel: name, ...(fetchImpl ? { fetch: fetchImpl } : {}), ...(cookie ? { cookie } : {}) })

            // "connected" is reported only while the socket is up AND the private channel is subscribed: a
            // socket whose channel authorization failed (403/419) delivers no signals, so the caller must keep
            // polling at the normal rate (CRN-05). Pusher re-subscribes after a reconnect and fires
            // subscription_succeeded again.
            let socketUp = false
            let subscribed = false
            let live = false

            const report = (failed = false) => {
                if (mine !== generation) return

                const next = socketUp && subscribed

                if (next === live && !failed) return

                live = next
                onState(live ? 'connected' : failed ? 'failed' : 'disconnected')
            }

            try {
                echo = module.createEcho(echoOptions(config, authorizer))
                current = name
                channel = echo.private(name)
                channel.listen(SIGNAL_EVENT, (payload) => onSignal(payload))
                channel.subscribed?.(() => {
                    subscribed = true
                    report()
                })
                channel.error?.(() => {
                    subscribed = false
                    report(true)
                })

                const connection = echo.connector?.pusher?.connection

                socketUp = connection?.state === 'connected'
                connection?.bind?.('state_change', ({ current: state } = {}) => {
                    socketUp = state === 'connected'
                    if (!socketUp) subscribed = false
                    report()
                })
            } catch {
                disconnect()

                return false
            }

            pending = null

            return true
        })()

        pending = { name, promise }

        return promise
    }

    return {
        connect,
        disconnect,
        get connected() {
            return echo !== null
        },
        get channel() {
            return current
        },
    }
}

let shared = null

export function useRealtime() {
    shared ??= createRealtime()

    return shared
}
