import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createNotificationsStore } from './useNotifications'
import { createToasts } from './useToasts'

const URLS = { list: '/admin/api/notifications', status: '/admin/api/notifications/status', runs: '/admin/api/runs' }
const PROPS = { unread: 1, poll: 60, urls: URLS, realtime: null }

function item(id, createdAt, extra = {}) {
    return { id, title: `T ${id}`, body: null, level: 'info', icon: null, url: null, urlLabel: null, download: false, read: false, createdAt, run: null, ...extra }
}

function run(id, status, extra = {}) {
    return { id, action: 'Export', entity: { uriKey: 'posts', label: 'Posts' }, status, total: 10, processed: 0, skipped: 0, level: null, message: null, url: null, urlLabel: null, download: false, cancellable: true, cancelRequested: false, createdAt: '2026-11-02T12:00:00Z', ...extra }
}

// A fake server: `routes['GET /path']` is a value or a function returning { status, data } / data.
function setup({ visible = true, realtime = null } = {}) {
    const routes = {
        [`GET ${URLS.status}`]: { unread: 1, latest: '2026-11-02T10:00:00Z', activeRuns: 0 },
        [`GET ${URLS.list}`]: { items: [item('n1', '2026-11-02T10:00:00Z')], unread: 1, nextCursor: null, timezone: 'Europe/Kyiv' },
        [`GET ${URLS.runs}`]: { runs: [] },
    }
    const calls = []
    const request = {
        send: vi.fn(async (method, url, body) => {
            calls.push(`${method} ${url}`)

            const route = routes[`${method} ${url}`]
            const value = typeof route === 'function' ? await route(body) : route

            if (value === undefined) return { ok: false, status: 404, data: null }
            if (value && value.__expired) return { ok: false, status: value.__expired, data: null, expired: true }
            if (value && value.__status) return { ok: value.__status < 300, status: value.__status, data: value.data ?? null }

            return { ok: true, status: 200, data: value }
        }),
    }

    let visibility = visible
    let visibilityListener = () => {}
    const lifecycle = {
        visible: () => visibility,
        onVisibilityChange: (callback) => {
            visibilityListener = callback

            return () => (visibilityListener = () => {})
        },
        onUnload: () => () => {},
    }

    const fakeRealtime = realtime ?? { connect: vi.fn(async () => true), disconnect: vi.fn() }
    const toasts = createToasts({ max: 10 })
    const pushed = []
    const push = toasts.push

    // Every toast ever pushed (shown ones expire after their timeout).
    toasts.push = (toast) => {
        const id = push(toast)

        if (id !== null) pushed.push(toast)

        return id
    }
    const store = createNotificationsStore({ request, toasts, realtime: fakeRealtime, lifecycle, now: () => Date.now() })
    const t = (key, params) => (params ? `${key}:${JSON.stringify(params)}` : key)

    return {
        store,
        routes,
        calls,
        request,
        toasts,
        pushed,
        realtime: fakeRealtime,
        i18n: { t, tc: (key, count) => `${key}#${count}` },
        setVisible(value) {
            visibility = value
            visibilityListener(value)
        },
        count: (call) => calls.filter((entry) => entry === call).length,
    }
}

const flush = () => vi.advanceTimersByTimeAsync(0)

describe('useNotifications', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        vi.setSystemTime(new Date('2026-11-02T12:00:30Z'))
    })

    afterEach(() => vi.useRealTimers())

    describe('polling', () => {
        it('takes the count from the prop, checks status once, then every poll seconds', async () => {
            const env = setup()

            env.store.init(PROPS, env.i18n)
            expect(env.store.state.unread).toBe(1)
            await flush()
            expect(env.count(`GET ${URLS.status}`)).toBe(1)

            await vi.advanceTimersByTimeAsync(59_999)
            expect(env.count(`GET ${URLS.status}`)).toBe(1)
            await vi.advanceTimersByTimeAsync(1)
            expect(env.count(`GET ${URLS.status}`)).toBe(2)
        })

        it('polls only while the tab is visible and checks at once on return', async () => {
            const env = setup()

            env.store.init(PROPS, env.i18n)
            await flush()

            env.setVisible(false)
            await vi.advanceTimersByTimeAsync(10 * 60_000)
            expect(env.count(`GET ${URLS.status}`)).toBe(1)

            env.setVisible(true)
            await flush()
            expect(env.count(`GET ${URLS.status}`)).toBe(2)
        })

        it('does not start polling in a hidden tab until it becomes visible', async () => {
            const env = setup({ visible: false })

            env.store.init(PROPS, env.i18n)
            await vi.advanceTimersByTimeAsync(5 * 60_000)
            expect(env.calls).toEqual([])

            env.setVisible(true)
            await flush()
            expect(env.count(`GET ${URLS.status}`)).toBe(1)
        })

        it('backs off after failures and resets after a success', async () => {
            const env = setup()
            let fail = true

            env.routes[`GET ${URLS.status}`] = () => (fail ? { __status: 500 } : { unread: 0, latest: null, activeRuns: 0 })
            env.store.init(PROPS, env.i18n)
            await flush() // fails (1)

            await vi.advanceTimersByTimeAsync(119_999)
            expect(env.count(`GET ${URLS.status}`)).toBe(1)
            await vi.advanceTimersByTimeAsync(1) // fails (2)
            expect(env.count(`GET ${URLS.status}`)).toBe(2)

            fail = false
            await vi.advanceTimersByTimeAsync(240_000) // succeeds (3)
            expect(env.count(`GET ${URLS.status}`)).toBe(3)
            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.count(`GET ${URLS.status}`)).toBe(4)
        })

        it('poll 0: only the initial check (page loads update the count)', async () => {
            const env = setup()

            env.store.init({ ...PROPS, poll: 0 }, env.i18n)
            await vi.advanceTimersByTimeAsync(10 * 60_000)
            expect(env.count(`GET ${URLS.status}`)).toBe(1)
        })

        it('re-init on navigation keeps one timer and takes the fresh count', async () => {
            const env = setup()

            env.store.init(PROPS, env.i18n)
            await flush()
            env.store.init({ ...PROPS, unread: 5 }, env.i18n)
            env.store.init({ ...PROPS, unread: 6 }, env.i18n)
            expect(env.store.state.unread).toBe(6)

            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.count(`GET ${URLS.status}`)).toBe(2)
        })

        it('a null prop disables the store: timers stop, realtime disconnects, no requests', async () => {
            const env = setup()

            env.store.init(PROPS, env.i18n)
            await flush()
            env.store.init(null)

            expect(env.store.state.enabled).toBe(false)
            expect(env.realtime.disconnect).toHaveBeenCalled()
            await vi.advanceTimersByTimeAsync(10 * 60_000)
            expect(env.count(`GET ${URLS.status}`)).toBe(1)
        })
    })

    describe('new notifications', () => {
        it('toasts only items newer than the baseline and only once', async () => {
            const env = setup()

            env.store.init(PROPS, env.i18n)
            await flush() // baseline: latest 10:00, no list request
            expect(env.count(`GET ${URLS.list}`)).toBe(0)

            env.routes[`GET ${URLS.status}`] = { unread: 2, latest: '2026-11-02T12:00:00Z', activeRuns: 0 }
            env.routes[`GET ${URLS.list}`] = {
                items: [item('n2', '2026-11-02T12:00:00Z', { level: 'success', url: '/admin/entities/posts', urlLabel: 'View' }), item('n1', '2026-11-02T10:00:00Z')],
                unread: 2,
                nextCursor: null,
            }

            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.count(`GET ${URLS.list}`)).toBe(1)
            expect(env.toasts.state.items.map((toast) => toast.title)).toEqual(['T n2'])
            expect(env.toasts.state.items[0]).toMatchObject({ type: 'success', link: { href: '/admin/entities/posts', label: 'View' } })
            expect(env.store.state.unread).toBe(2)

            // Same latest: no list request, no toast.
            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.count(`GET ${URLS.list}`)).toBe(1)
            expect(env.pushed).toHaveLength(1)
        })

        it('drops unsafe links from toasts and summarizes more than three', async () => {
            const env = setup()

            env.store.init(PROPS, env.i18n)
            await flush()

            env.routes[`GET ${URLS.status}`] = { unread: 6, latest: '2026-11-02T12:00:05Z', activeRuns: 0 }
            env.routes[`GET ${URLS.list}`] = {
                items: [5, 4, 3, 2, 1].map((n) => item(`x${n}`, `2026-11-02T12:00:0${n}Z`, { url: n === 5 ? 'javascript:alert(1)' : null })),
                unread: 6,
                nextCursor: null,
            }

            await vi.advanceTimersByTimeAsync(60_000)

            const items = env.toasts.state.items

            expect(items).toHaveLength(4)
            expect(items[0]).toMatchObject({ title: 'T x5', link: null })
            expect(items[3].message).toBe('notifications_more_toasts#2')
        })
    })

    describe('list and mutations', () => {
        it('opens: loads the first page and runs; loads more with the cursor', async () => {
            const env = setup()

            env.routes[`GET ${URLS.list}`] = { items: [item('n2', '2026-11-02T11:00:00Z')], unread: 1, nextCursor: 'c1', timezone: 'Europe/Kyiv' }
            env.routes[`GET ${URLS.list}?cursor=c1`] = { items: [item('n1', '2026-11-02T10:00:00Z')], unread: 1, nextCursor: null }
            env.store.init(PROPS, env.i18n)

            const opening = env.store.open()

            expect(env.store.state.listStatus).toBe('loading')
            await opening
            expect(env.store.state.items.map((entry) => entry.id)).toEqual(['n2'])
            expect(env.store.state.timezone).toBe('Europe/Kyiv')
            expect(env.count(`GET ${URLS.runs}`)).toBe(1)

            const added = await env.store.loadMore()

            expect(added.map((entry) => entry.id)).toEqual(['n1'])
            expect(env.store.state.items.map((entry) => entry.id)).toEqual(['n2', 'n1'])
            expect(env.store.state.nextCursor).toBeNull()
            expect(env.toasts.state.items).toHaveLength(0) // opening never toasts
        })

        it('shows the error state and retries', async () => {
            const env = setup()

            env.routes[`GET ${URLS.list}`] = { __status: 500 }
            env.store.init(PROPS, env.i18n)
            await env.store.open()
            expect(env.store.state.listStatus).toBe('error')

            env.routes[`GET ${URLS.list}`] = { items: [], unread: 0, nextCursor: null }
            await env.store.retry()
            expect(env.store.state.listStatus).toBe('idle')
            expect(env.store.state.loaded).toBe(true)
        })

        it('marks one / all as read and deletes, taking the count from the server', async () => {
            const env = setup()

            env.routes[`GET ${URLS.list}`] = { items: [item('a', '2026-11-02T11:00:00Z'), item('b', '2026-11-02T10:00:00Z')], unread: 2, nextCursor: null }
            env.routes[`POST ${URLS.list}/a/read`] = { unread: 1 }
            env.routes[`POST ${URLS.list}/read`] = { unread: 0 }
            env.routes[`DELETE ${URLS.list}/b`] = { unread: 0 }
            env.store.init(PROPS, env.i18n)
            await env.store.open()

            expect(await env.store.markRead('a')).toBe(true)
            expect(env.store.state.items[0].read).toBe(true)
            expect(env.store.state.unread).toBe(1)

            expect(await env.store.markAllRead()).toBe(true)
            expect(env.store.state.items.every((entry) => entry.read)).toBe(true)
            expect(env.store.state.unread).toBe(0)

            expect(await env.store.remove('b')).toBe(true)
            expect(env.store.state.items.map((entry) => entry.id)).toEqual(['a'])
        })

        it('reverts a failed "mark as read" and tells the user; a 404 drops the row', async () => {
            const env = setup()

            env.routes[`GET ${URLS.list}`] = { items: [item('a', '2026-11-02T11:00:00Z'), item('b', '2026-11-02T10:00:00Z')], unread: 2, nextCursor: null }
            env.routes[`POST ${URLS.list}/a/read`] = { __status: 500 }
            env.store.init(PROPS, env.i18n)
            await env.store.open()

            expect(await env.store.markRead('a')).toBe(false)
            expect(env.store.state.items[0].read).toBe(false)
            expect(env.toasts.state.items.at(-1)).toMatchObject({ type: 'error', message: 'notifications_action_failed' })

            expect(await env.store.markRead('b')).toBe(false) // no route: 404
            expect(env.store.state.items.map((entry) => entry.id)).toEqual(['a'])
        })
    })

    describe('runs', () => {
        it('follows a dispatched run every 3 s until it finishes, then toasts once', async () => {
            const env = setup()
            let current = run('r1', 'queued')

            env.routes[`GET ${URLS.runs}`] = () => ({ runs: [current] })
            env.store.init(PROPS, env.i18n)
            await flush()

            env.store.trackRun('r1')
            await flush()
            expect(env.count(`GET ${URLS.runs}`)).toBe(1)
            expect(env.store.hasActiveRuns.value).toBe(true)

            current = run('r1', 'running', { processed: 4 })
            await vi.advanceTimersByTimeAsync(3_000)
            expect(env.count(`GET ${URLS.runs}`)).toBe(2)
            expect(env.store.state.runs[0].processed).toBe(4)
            expect(env.toasts.state.items).toHaveLength(0)

            current = run('r1', 'completed', { processed: 10, level: 'success', message: 'Done.', url: '/admin/runs/r1/download', urlLabel: 'Download posts.csv', download: true, cancellable: false })
            await vi.advanceTimersByTimeAsync(3_000)

            expect(env.toasts.state.items).toHaveLength(1)
            expect(env.toasts.state.items[0]).toMatchObject({ title: 'Export', message: 'Done.', type: 'success', link: { href: '/admin/runs/r1/download', download: true } })
            expect(env.store.hasActiveRuns.value).toBe(false)

            // The finish refreshes the status; the run's own notification is not toasted a second time.
            env.routes[`GET ${URLS.status}`] = { unread: 2, latest: '2026-11-02T12:01:00Z', activeRuns: 0 }
            env.routes[`GET ${URLS.list}`] = { items: [item('n9', '2026-11-02T12:01:00Z', { run: 'r1' })], unread: 2, nextCursor: null }
            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.pushed).toHaveLength(1)

            // No more run polling once nothing is active.
            const runsCalls = env.count(`GET ${URLS.runs}`)

            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.count(`GET ${URLS.runs}`)).toBe(runsCalls)
        })

        it('slows run polling to 10 s after two minutes', async () => {
            const env = setup()

            env.routes[`GET ${URLS.runs}`] = { runs: [run('r1', 'running')] }
            env.store.init(PROPS, env.i18n)
            await flush()
            env.store.trackRun('r1')
            await flush()

            await vi.advanceTimersByTimeAsync(120_000)

            const before = env.count(`GET ${URLS.runs}`)

            await vi.advanceTimersByTimeAsync(9_999)
            expect(env.count(`GET ${URLS.runs}`)).toBe(before)
            await vi.advanceTimersByTimeAsync(1)
            expect(env.count(`GET ${URLS.runs}`)).toBe(before + 1)
        })

        it('picks up runs still active from earlier pages (status activeRuns)', async () => {
            const env = setup()

            env.routes[`GET ${URLS.status}`] = { unread: 0, latest: null, activeRuns: 1 }
            env.routes[`GET ${URLS.runs}`] = { runs: [run('r1', 'running')] }
            env.store.init(PROPS, env.i18n)
            await flush()

            expect(env.count(`GET ${URLS.runs}`)).toBe(1)
            expect(env.store.activeRuns.value.map((entry) => entry.id)).toEqual(['r1'])
        })

        it('cancels a run and refreshes on 409', async () => {
            const env = setup()

            env.routes[`GET ${URLS.runs}`] = { runs: [run('r1', 'running'), run('r2', 'running')] }
            env.routes[`POST ${URLS.runs}/r1/cancel`] = run('r1', 'running', { cancelRequested: true })
            env.routes[`POST ${URLS.runs}/r2/cancel`] = { __status: 409 }
            env.store.init(PROPS, env.i18n)
            await env.store.refreshRuns()

            expect(await env.store.cancelRun('r1')).toBe(true)
            expect(env.store.state.runs.find((entry) => entry.id === 'r1').cancelRequested).toBe(true)
            expect(env.store.state.cancelling).toEqual({})

            const before = env.count(`GET ${URLS.runs}`)

            expect(await env.store.cancelRun('r2')).toBe(false)
            expect(env.count(`GET ${URLS.runs}`)).toBe(before + 1)
        })
    })

    describe('realtime', () => {
        const REALTIME = { broadcaster: 'reverb', key: 'k', channel: 'larsa_inbox.t', basePath: '/admin', authEndpoint: '/admin/broadcasting/auth' }

        it('connects when the server sends a config, and only once across navigations', async () => {
            const env = setup()

            env.store.init({ ...PROPS, realtime: REALTIME }, env.i18n)
            env.store.init({ ...PROPS, realtime: { ...REALTIME } }, env.i18n)
            await flush()

            expect(env.realtime.connect).toHaveBeenCalledOnce()
            expect(env.realtime.connect.mock.calls[0][0]).toEqual(REALTIME)
            expect(env.store.state.realtime).toBe(true)
        })

        it('does not connect without a config', async () => {
            const env = setup()

            env.store.init(PROPS, env.i18n)
            await flush()
            expect(env.realtime.connect).not.toHaveBeenCalled()
        })

        it('a notification signal fetches the first page and toasts the new item', async () => {
            const env = setup()

            env.store.init({ ...PROPS, realtime: REALTIME }, env.i18n)
            await flush()

            const onSignal = env.realtime.connect.mock.calls[0][1]

            env.routes[`GET ${URLS.list}`] = { items: [item('n5', '2026-11-02T12:00:10Z', { title: 'Hello' })], unread: 2, nextCursor: null }
            onSignal({ kind: 'notification', id: 'n5' })
            onSignal({ kind: 'notification', id: 'n5' }) // a burst is coalesced
            await vi.advanceTimersByTimeAsync(300)

            expect(env.count(`GET ${URLS.list}`)).toBe(1)
            expect(env.toasts.state.items.map((toast) => toast.title)).toEqual(['Hello'])
            expect(env.store.state.unread).toBe(2)
        })

        it('run signals update progress in place; the final one fetches the run list', async () => {
            const env = setup()

            env.routes[`GET ${URLS.runs}`] = { runs: [run('r1', 'running', { processed: 1 })] }
            env.store.init({ ...PROPS, realtime: REALTIME }, env.i18n)
            await env.store.refreshRuns()

            const onSignal = env.realtime.connect.mock.calls[0][1]
            const before = env.count(`GET ${URLS.runs}`)

            onSignal({ kind: 'run', id: 'r1', status: 'running', processed: 7, total: 10 })
            expect(env.store.state.runs[0].processed).toBe(7)
            expect(env.count(`GET ${URLS.runs}`)).toBe(before)

            env.routes[`GET ${URLS.runs}`] = { runs: [run('r1', 'completed', { processed: 10, level: 'success', message: 'OK' })] }
            onSignal({ kind: 'run', id: 'r1', status: 'completed', processed: 10, total: 10 })
            await flush()
            expect(env.count(`GET ${URLS.runs}`)).toBe(before + 1)
            expect(env.toasts.state.items.map((toast) => toast.message)).toEqual(['OK'])
        })

        it('ignores malformed signals', async () => {
            const env = setup()

            env.store.init({ ...PROPS, realtime: REALTIME }, env.i18n)
            await flush()

            const onSignal = env.realtime.connect.mock.calls[0][1]
            const before = env.calls.length

            onSignal({ kind: 'notification', title: '<img onerror>' })
            onSignal('junk')
            await vi.advanceTimersByTimeAsync(1_000)
            expect(env.calls.length).toBe(before)
        })

        it('with a live socket polls active runs only every 30 s; a reconnect checks the status once', async () => {
            const env = setup()

            env.routes[`GET ${URLS.runs}`] = { runs: [run('r1', 'running')] }
            env.store.init({ ...PROPS, realtime: REALTIME }, env.i18n)
            await flush()

            const { onState } = env.realtime.connect.mock.calls[0][2]

            onState('connected')
            env.store.trackRun('r1')
            await flush()

            const runs = env.count(`GET ${URLS.runs}`)

            await vi.advanceTimersByTimeAsync(29_999)
            expect(env.count(`GET ${URLS.runs}`)).toBe(runs)
            await vi.advanceTimersByTimeAsync(1)
            expect(env.count(`GET ${URLS.runs}`)).toBe(runs + 1)

            const status = env.count(`GET ${URLS.status}`)

            onState('unavailable')
            onState('connected')
            await flush()
            expect(env.count(`GET ${URLS.status}`)).toBe(status + 1)
        })
    })

    describe('races with mutations (CRN-06)', () => {
        function deferred() {
            let resolve
            const promise = new Promise((r) => (resolve = r))

            return { promise, resolve }
        }

        async function opened(env) {
            env.routes[`GET ${URLS.list}`] = { items: [item('b', '2026-11-02T11:00:00Z'), item('a', '2026-11-02T10:00:00Z')], unread: 2, nextCursor: null }
            env.store.init(PROPS, env.i18n)
            await env.store.open()
        }

        it('a first page in flight during "mark as read" / delete does not undo them', async () => {
            const env = setup()

            await opened(env)

            const gate = deferred()

            env.routes[`GET ${URLS.list}`] = () => gate.promise
            env.routes[`POST ${URLS.list}/b/read`] = { unread: 1 }
            env.routes[`DELETE ${URLS.list}/a`] = { unread: 1 }

            const stale = env.store.open() // GET sent before the mutations
            await env.store.markRead('b')
            await env.store.remove('a')

            gate.resolve({ items: [item('b', '2026-11-02T11:00:00Z'), item('a', '2026-11-02T10:00:00Z')], unread: 2, nextCursor: null })
            await stale

            expect(env.store.state.items.map((entry) => [entry.id, entry.read])).toEqual([['b', true]])
            expect(env.store.state.unread).toBe(1) // the stale count is ignored
        })

        it('a first page in flight during "mark all as read" does not undo it', async () => {
            const env = setup()

            await opened(env)

            const gate = deferred()

            env.routes[`GET ${URLS.list}`] = () => gate.promise
            env.routes[`POST ${URLS.list}/read`] = { unread: 0 }

            const stale = env.store.open()
            await env.store.markAllRead()

            gate.resolve({ items: [item('b', '2026-11-02T11:00:00Z'), item('a', '2026-11-02T10:00:00Z')], unread: 2, nextCursor: null })
            await stale

            expect(env.store.state.items.every((entry) => entry.read)).toBe(true)
            expect(env.store.state.unread).toBe(0)
        })

        it('a "Show more" page that resolves after retry() is dropped', async () => {
            const env = setup()
            const gate = deferred()

            env.routes[`GET ${URLS.list}`] = { items: [item('b', '2026-11-02T11:00:00Z')], unread: 1, nextCursor: 'c1' }
            env.routes[`GET ${URLS.list}?cursor=c1`] = () => gate.promise
            env.store.init(PROPS, env.i18n)
            await env.store.open()

            const more = env.store.loadMore()

            env.routes[`GET ${URLS.list}`] = { items: [item('z', '2026-11-02T12:00:00Z')], unread: 1, nextCursor: null }
            await env.store.retry()
            gate.resolve({ items: [item('old', '2026-11-01T10:00:00Z')], unread: 1, nextCursor: 'c2' })

            expect(await more).toEqual([])
            expect(env.store.state.items.map((entry) => entry.id)).toEqual(['z'])
            expect(env.store.state.nextCursor).toBeNull()
        })
    })

    describe('expired session (CRN-07)', () => {
        const REALTIME = { broadcaster: 'reverb', key: 'k', channel: 'larsa_inbox.t', basePath: '/admin' }

        it('a 401 on a background poll stops polling and realtime and shows one toast, without reloading', async () => {
            const env = setup()

            env.store.init({ ...PROPS, realtime: REALTIME }, env.i18n)
            await flush()
            env.routes[`GET ${URLS.status}`] = { __expired: 401 }

            await vi.advanceTimersByTimeAsync(60_000)

            expect(env.store.state.sessionExpired).toBe(true)
            expect(env.realtime.disconnect).toHaveBeenCalled()
            expect(env.pushed.filter((toast) => toast.message === 'notifications_session_expired')).toHaveLength(1)

            const calls = env.calls.length

            await vi.advanceTimersByTimeAsync(30 * 60_000)
            env.setVisible(false)
            env.setVisible(true)
            await flush()
            expect(env.calls.length).toBe(calls)
        })

        it('a 419 on the runs poll stops run polling too', async () => {
            const env = setup()

            env.routes[`GET ${URLS.runs}`] = { __expired: 419 }
            env.store.init(PROPS, env.i18n)
            await flush()
            env.store.trackRun('r1')
            await flush()

            expect(env.store.state.sessionExpired).toBe(true)

            const runs = env.count(`GET ${URLS.runs}`)

            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.count(`GET ${URLS.runs}`)).toBe(runs)
        })

        it('the next page with the prop (session valid again) resumes polling and drops the toast', async () => {
            const env = setup()

            env.store.init(PROPS, env.i18n)
            await flush()
            env.routes[`GET ${URLS.status}`] = { __expired: 401 }
            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.toasts.state.items.some((toast) => toast.message === 'notifications_session_expired')).toBe(true)

            env.routes[`GET ${URLS.status}`] = { unread: 0, latest: null, activeRuns: 0 }
            env.store.init(PROPS, env.i18n)
            await flush()

            expect(env.store.state.sessionExpired).toBe(false)
            expect(env.toasts.state.items.some((toast) => toast.message === 'notifications_session_expired')).toBe(false)

            const status = env.count(`GET ${URLS.status}`)

            await vi.advanceTimersByTimeAsync(60_000)
            expect(env.count(`GET ${URLS.status}`)).toBe(status + 1)
        })
    })

    describe('failed realtime subscription (CRN-05)', () => {
        it('keeps polling active runs every 3 s when the channel subscription failed', async () => {
            const env = setup()

            env.routes[`GET ${URLS.runs}`] = { runs: [run('r1', 'running')] }
            env.store.init({ ...PROPS, realtime: { broadcaster: 'reverb', key: 'k', channel: 'larsa_inbox.t', basePath: '/admin' } }, env.i18n)
            await flush()

            const { onState } = env.realtime.connect.mock.calls[0][2]

            onState('failed')
            env.store.trackRun('r1')
            await flush()

            const runs = env.count(`GET ${URLS.runs}`)

            await vi.advanceTimersByTimeAsync(3_000)
            expect(env.count(`GET ${URLS.runs}`)).toBe(runs + 1)
            expect(env.store.state.connected).toBe(false)
        })
    })

    describe('unknown and abandoned run statuses', () => {
        it('a run that ends abandoned gets an error toast with its label and stops polling', async () => {
            const env = setup()
            let current = run('r1', 'running')

            env.routes[`GET ${URLS.runs}`] = () => ({ runs: [current] })
            env.store.init(PROPS, env.i18n)
            await flush()
            env.store.trackRun('r1')
            await flush()

            current = run('r1', 'abandoned', { cancellable: true })
            await vi.advanceTimersByTimeAsync(3_000)

            expect(env.pushed.at(-1)).toMatchObject({ title: 'Export', type: 'error', message: 'runs_status_abandoned' })
            expect(env.store.hasActiveRuns.value).toBe(false)
        })
    })
})
