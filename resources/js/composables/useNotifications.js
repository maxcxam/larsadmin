import { computed, reactive } from 'vue'
import { useJsonRequest } from './useJsonRequest'
import { useToasts } from './useToasts'
import { useRealtime } from '../realtime/realtime'
import {
    applyLocalChanges,
    applyRunSignal,
    isActiveRun,
    isFailedRun,
    isFinishedRun,
    memberUrl,
    mergeItems,
    newSince,
    nextPollDelay,
    normalizeLevel,
    parseSignal,
    runStatusKey,
    runsPollDelay,
    safeHref,
    withCursor,
} from '../components/notifications/notifications'

// The bell's store: a module-level singleton, so its state and timers survive Inertia navigations
// (AppLayout is a persistent layout and calls init() with the shared prop on every page).
//
// Without realtime: GET status every `poll` seconds while the tab is visible (exponential backoff after
// failures), at once when the tab becomes visible again; active runs every 3 s (10 s after two minutes).
// With realtime: signals trigger the same requests; status polling stays on as a safety net and active
// runs are polled every 30 s.

const MAX_TOASTS_PER_BATCH = 3
const SIGNAL_DEBOUNCE_MS = 250

function browserLifecycle() {
    const hasDocument = typeof document !== 'undefined'

    return {
        visible: () => !hasDocument || document.visibilityState !== 'hidden',
        onVisibilityChange(callback) {
            if (!hasDocument) return () => {}
            const handler = () => callback(document.visibilityState !== 'hidden')
            document.addEventListener('visibilitychange', handler)

            return () => document.removeEventListener('visibilitychange', handler)
        },
        onUnload(callback) {
            if (typeof window === 'undefined') return () => {}
            window.addEventListener('pagehide', callback)

            return () => window.removeEventListener('pagehide', callback)
        },
    }
}

function toastType(level) {
    return { success: 'success', warning: 'warning', danger: 'error' }[normalizeLevel(level)] ?? 'info'
}

function runToastType(run) {
    if (run.level === 'danger' || isFailedRun(run)) return 'error'
    if (run.level === 'warning' || run.status === 'cancelled') return 'warning'

    return 'success'
}

function latestOf(items, fallback = null) {
    let latest = fallback
    let latestTime = fallback ? Date.parse(fallback) || 0 : 0

    for (const item of items ?? []) {
        const time = Date.parse(item?.createdAt) || 0

        if (time > latestTime) {
            latest = item.createdAt
            latestTime = time
        }
    }

    return latest
}

export function createNotificationsStore({
    request = useJsonRequest(),
    toasts = useToasts(),
    realtime = useRealtime(),
    setTimer = (fn, ms) => setTimeout(fn, ms),
    clearTimer = (id) => clearTimeout(id),
    now = () => Date.now(),
    lifecycle = browserLifecycle(),
} = {}) {
    const state = reactive({
        enabled: false,
        unread: null,
        items: [],
        nextCursor: null,
        loaded: false,
        listStatus: 'idle', // idle | loading | error
        loadingMore: false,
        moreError: false,
        timezone: null,
        runs: [],
        cancelling: {}, // run id -> true while the cancel request is in flight
        realtime: false, // realtime configured by the server
        connected: false, // socket connected and subscribed
        latest: null,
        activeRunsHint: 0, // activeRuns from the last status response
        sessionExpired: false, // a background request got 401/419: polling stopped, no reload (CRN-07)
    })

    let config = { urls: {}, poll: 60, realtime: null }
    let translate = { t: (key) => key, tc: (key) => key }
    let statusTimer = null
    let runsTimer = null
    let signalTimer = null
    let failures = 0
    let activeSince = null
    let baselineReady = false
    let everConnected = false
    let unsubscribers = []
    let realtimeKey = null
    const knownIds = new Set()
    const tracked = new Set()
    const toastedRuns = new Set()
    const runStatuses = new Map() // id -> last seen status
    // CRN-06: list/status responses that were in flight during a mutation must not undo it. Local changes
    // are re-applied to every list response; counts from responses started before a mutation are ignored.
    const local = { read: new Set(), deleted: new Set(), readUntil: null }
    let mutations = 0
    let expiredToast = null
    let listGeneration = 0 // bumped by retry(): older first-page / load-more responses are dropped

    const activeRuns = computed(() => state.runs.filter(isActiveRun))
    const hasActiveRuns = computed(() => activeRuns.value.length > 0)

    // ---- timers ---------------------------------------------------------------------------------

    function clearStatusTimer() {
        if (statusTimer !== null) clearTimer(statusTimer)
        statusTimer = null
    }

    function clearRunsTimer() {
        if (runsTimer !== null) clearTimer(runsTimer)
        runsTimer = null
    }

    function scheduleStatus() {
        clearStatusTimer()

        const seconds = Number(config.poll)

        if (!state.enabled || state.sessionExpired || !(seconds > 0) || !lifecycle.visible()) return

        statusTimer = setTimer(() => {
            statusTimer = null
            refresh()
        }, nextPollDelay(failures, seconds * 1000))
    }

    function wantsRunsPolling() {
        return state.enabled && !state.sessionExpired && (hasActiveRuns.value || tracked.size > 0 || state.activeRunsHint > 0)
    }

    function scheduleRuns() {
        clearRunsTimer()

        if (!wantsRunsPolling() || !lifecycle.visible() || !config.urls.runs) {
            activeSince = wantsRunsPolling() ? activeSince : null

            return
        }

        activeSince ??= now()
        runsTimer = setTimer(() => {
            runsTimer = null
            refreshRuns()
        }, runsPollDelay(now() - activeSince, state.connected))
    }

    // ---- notifications --------------------------------------------------------------------------

    function toastItems(items) {
        const fresh = items.filter((item) => !(item.run && toastedRuns.has(String(item.run))))

        for (const item of fresh.slice(0, MAX_TOASTS_PER_BATCH)) {
            const href = safeHref(item.url)

            toasts.push({
                key: `notification:${item.id}`,
                type: toastType(item.level),
                title: item.title,
                message: item.body ?? '',
                timeout: 8000,
                link: href
                    ? {
                          href,
                          download: !!item.download,
                          label: item.urlLabel || translate.t(item.download ? 'notifications_download' : 'notifications_open'),
                          onOpen: () => markRead(item.id),
                      }
                    : null,
            })
        }

        if (fresh.length > MAX_TOASTS_PER_BATCH) {
            toasts.push({ type: 'info', message: translate.tc('notifications_more_toasts', fresh.length - MAX_TOASTS_PER_BATCH), timeout: 8000 })
        }
    }

    function remember(items) {
        for (const item of items) knownIds.add(String(item.id))
    }

    // A background request answered 401/419: stop polling and realtime, say so once, never reload (CRN-07).
    // The next Inertia visit sends the user to the login page.
    function expire() {
        if (state.sessionExpired) return

        state.sessionExpired = true
        clearStatusTimer()
        clearRunsTimer()
        realtime.disconnect()
        realtimeKey = null
        state.connected = false
        expiredToast = toasts.push({ type: 'warning', message: translate.t('notifications_session_expired'), timeout: 0 })
    }

    // `started`: the mutation counter when the request was sent; an older count is not applied.
    function acceptPage(data, started) {
        const items = Array.isArray(data?.items) ? data.items.filter((item) => item && item.id !== undefined && item.id !== null) : []

        if (typeof data?.unread === 'number' && started === mutations) state.unread = data.unread
        if (typeof data?.timezone === 'string') state.timezone = data.timezone

        return applyLocalChanges(items, local)
    }

    // First page: merges new items in; toasts the ones this tab has not seen (after the baseline).
    async function fetchFirstPage({ toast = true, background = true } = {}) {
        if (!config.urls.list || state.sessionExpired) return false

        if (!background) state.listStatus = 'loading'

        const started = mutations
        const generation = listGeneration
        const result = await request.send('GET', config.urls.list)
        const { ok, data } = result

        if (!state.enabled || generation !== listGeneration) return false

        if (result.expired) {
            expire()
            if (!state.loaded) state.listStatus = 'error'

            return false
        }

        if (!ok || !data || !Array.isArray(data.items)) {
            if (!background || !state.loaded) state.listStatus = 'error'

            return false
        }

        const items = acceptPage(data, started)

        if (toast && baselineReady) toastItems(newSince(items, knownIds, state.latest))

        remember(items)
        state.latest = latestOf(items, state.latest)

        if (state.loaded) {
            state.items = applyLocalChanges(mergeItems(state.items, items), local)
        } else {
            state.items = mergeItems([], items)
            state.nextCursor = data.nextCursor ?? null
            state.loaded = true
        }

        state.listStatus = 'idle'

        return true
    }

    // GET status: the badge count, and a first-page fetch when something newer arrived.
    async function refresh() {
        if (!state.enabled || state.sessionExpired || !config.urls.status) return

        clearStatusTimer()

        const started = mutations
        const result = await request.send('GET', config.urls.status)
        const { ok, data } = result

        if (!state.enabled) return

        if (result.expired) {
            expire()

            return
        }

        if (!ok || !data) {
            failures++
            scheduleStatus()

            return
        }

        failures = 0

        if ((typeof data.unread === 'number' || data.unread === null) && started === mutations) state.unread = data.unread
        state.activeRunsHint = Number(data.activeRuns) || 0

        const latest = typeof data.latest === 'string' ? data.latest : null

        if (!baselineReady) {
            state.latest = latest ?? state.latest
            baselineReady = true
        } else if (latest && (!state.latest || Date.parse(latest) > Date.parse(state.latest))) {
            await fetchFirstPage()
        }

        if (state.activeRunsHint > 0 && !hasActiveRuns.value && runsTimer === null) refreshRuns()
        else scheduleRuns()

        scheduleStatus()
    }

    async function open() {
        if (!state.enabled) return

        const list = state.loaded ? fetchFirstPage({ toast: false }) : fetchFirstPage({ toast: false, background: false })

        await Promise.all([list, refreshRuns()])
    }

    async function retry() {
        listGeneration++
        state.loaded = false
        state.loadingMore = false
        state.moreError = false
        state.items = []
        state.nextCursor = null
        await fetchFirstPage({ toast: false, background: false })
    }

    async function loadMore() {
        if (!state.enabled || !state.nextCursor || state.loadingMore) return []

        state.loadingMore = true
        state.moreError = false

        const started = mutations
        const generation = listGeneration
        const result = await request.send('GET', withCursor(config.urls.list, state.nextCursor))
        const { ok, data } = result

        // retry() started over meanwhile: this page belongs to the old list.
        if (generation !== listGeneration) return []

        state.loadingMore = false

        if (result.expired) expire()

        if (!ok || !data || !Array.isArray(data.items)) {
            state.moreError = true

            return []
        }

        const items = acceptPage(data, started)
        const added = items.filter((item) => !state.items.some((existing) => String(existing.id) === String(item.id)))

        remember(items)
        state.items = applyLocalChanges(mergeItems(state.items, items), local)
        state.nextCursor = data.nextCursor ?? null

        return added
    }

    function findItem(id) {
        return state.items.find((item) => String(item.id) === String(id)) ?? null
    }

    function removeLocal(id) {
        const index = state.items.findIndex((item) => String(item.id) === String(id))

        if (index !== -1) state.items.splice(index, 1)
    }

    async function markRead(id) {
        const item = findItem(id)
        const key = String(id)

        if (item?.read) return true
        if (item) item.read = true

        mutations++
        local.read.add(key)

        const { ok, status, data } = await request.send('POST', memberUrl(config.urls.list, id, 'read'))

        if (ok) {
            if (typeof data?.unread === 'number') state.unread = data.unread

            return true
        }

        local.read.delete(key)

        if (status === 404) {
            local.deleted.add(key)
            removeLocal(id)

            return false
        }

        if (item) item.read = false
        toasts.push({ type: 'error', message: translate.t('notifications_action_failed') })

        return false
    }

    async function markAllRead() {
        // Everything this tab knows of is covered; a newer notification arriving later stays unread.
        const until = latestOf(state.items, state.latest)

        mutations++

        const { ok, data } = await request.send('POST', `${String(config.urls.list).replace(/\/+$/, '')}/read`)

        if (!ok) {
            toasts.push({ type: 'error', message: translate.t('notifications_action_failed') })

            return false
        }

        mutations++
        if (until) local.readUntil = latestOf([{ createdAt: until }], local.readUntil)
        for (const item of state.items) item.read = true
        state.unread = typeof data?.unread === 'number' ? data.unread : 0

        return true
    }

    async function remove(id) {
        mutations++

        const { ok, status, data } = await request.send('DELETE', memberUrl(config.urls.list, id))

        if (ok || status === 404) {
            mutations++
            local.deleted.add(String(id))
            removeLocal(id)
            if (typeof data?.unread === 'number') state.unread = data.unread

            return true
        }

        toasts.push({ type: 'error', message: translate.t('notifications_action_failed') })

        return false
    }

    // ---- runs -----------------------------------------------------------------------------------

    function toastRun(run) {
        if (toastedRuns.has(String(run.id))) return

        toastedRuns.add(String(run.id))

        const href = safeHref(run.url)

        toasts.push({
            key: `run:${run.id}`,
            type: runToastType(run),
            title: run.action,
            message: run.message || translate.t(runStatusKey(run, now())),
            timeout: 8000,
            link: href ? { href, download: !!run.download, label: run.urlLabel || translate.t(run.download ? 'notifications_download' : 'notifications_open') } : null,
        })
    }

    // A run seen active before (or tracked after dispatch) that is finished now gets one completion toast.
    function observeRun(run) {
        const id = String(run.id)
        const previous = runStatuses.get(id)

        runStatuses.set(id, run.status)

        if (isFinishedRun(run) && ((previous && previous !== run.status && !isFinishedRun({ status: previous })) || tracked.has(id))) {
            tracked.delete(id)
            toastRun(run)

            return true
        }

        if (isFinishedRun(run)) tracked.delete(id)

        return false
    }

    function acceptRuns(runs) {
        let finished = false

        for (const run of runs) finished = observeRun(run) || finished

        state.runs = runs
        if (!hasActiveRuns.value) state.activeRunsHint = 0

        return finished
    }

    async function refreshRuns() {
        if (!state.enabled || state.sessionExpired || !config.urls.runs) return

        clearRunsTimer()

        const result = await request.send('GET', config.urls.runs)
        const { ok, data } = result

        if (!state.enabled) return

        if (result.expired) {
            expire()

            return
        }

        if (ok && Array.isArray(data?.runs)) {
            const runs = data.runs.filter((run) => run && run.id !== undefined && run.id !== null)
            const listed = new Set(runs.map((run) => String(run.id)))

            // A tracked run the list does not return (should not happen) stops being tracked.
            for (const id of [...tracked]) if (!listed.has(id)) tracked.delete(id)

            if (acceptRuns(runs)) refresh()
        }

        scheduleRuns()
    }

    function upsertRun(run) {
        const index = state.runs.findIndex((item) => String(item.id) === String(run.id))
        const runs = [...state.runs]

        if (index === -1) runs.unshift(run)
        else runs[index] = run

        if (acceptRuns(runs)) refresh()
    }

    function trackRun(id) {
        if (!state.enabled || id === null || id === undefined || id === '') return

        tracked.add(String(id))
        refreshRuns()
    }

    async function cancelRun(id) {
        const key = String(id)

        if (state.cancelling[key]) return false

        state.cancelling = { ...state.cancelling, [key]: true }

        const { ok, status, data } = await request.send('POST', memberUrl(config.urls.runs, id, 'cancel'))
        const { [key]: _, ...rest } = state.cancelling

        state.cancelling = rest

        if (ok && data && data.id !== undefined) {
            upsertRun(data)
            scheduleRuns()

            return true
        }

        if (status === 409 || status === 404) {
            await refreshRuns()

            return false
        }

        toasts.push({ type: 'error', message: translate.t('notifications_action_failed') })

        return false
    }

    // ---- realtime -------------------------------------------------------------------------------

    function onSignal(payload) {
        const signal = parseSignal(payload)

        if (!signal || !state.enabled) return

        if (signal.kind === 'notification') {
            if (signalTimer !== null) clearTimer(signalTimer)
            signalTimer = setTimer(() => {
                signalTimer = null
                fetchFirstPage()
            }, SIGNAL_DEBOUNCE_MS)

            return
        }

        const run = state.runs.find((item) => String(item.id) === signal.id)

        if (!run || (signal.status && isFinishedRun({ status: signal.status }))) {
            // Unknown run, or the final state: fetch the full Run (message, url) from the panel.
            if (!run) tracked.add(signal.id)
            refreshRuns()

            return
        }

        upsertRun(applyRunSignal(run, signal))
        scheduleRuns()
    }

    // 'connected' = socket up and channel subscribed; anything else (disconnected, failed subscription)
    // falls back to the normal polling rates.
    function onConnectionState(current) {
        if (state.sessionExpired) return

        const wasConnected = state.connected

        state.connected = current === 'connected'

        if (state.connected && !wasConnected) {
            // After a reconnect, one status request catches up with signals missed while offline.
            if (everConnected) refresh()
            everConnected = true
        }

        if (state.connected !== wasConnected) scheduleRuns()
    }

    async function connectRealtime() {
        const settings = config.realtime
        const key = settings ? JSON.stringify(settings) : null

        if (key === realtimeKey) return

        realtimeKey = key
        state.realtime = !!settings

        if (!settings) {
            realtime.disconnect()
            state.connected = false

            return
        }

        const subscribed = await realtime.connect(settings, onSignal, { onState: onConnectionState })

        if (!subscribed && realtimeKey === key) state.connected = false
    }

    // ---- lifecycle ------------------------------------------------------------------------------

    function onVisibility(visible) {
        if (!state.enabled) return

        if (visible) {
            if (state.sessionExpired) return

            connectRealtime()
            refresh()
            if (wantsRunsPolling()) refreshRuns()
        } else {
            clearStatusTimer()
            clearRunsTimer()
        }
    }

    // pagehide: close the socket; a page restored from the back/forward cache reconnects when it is shown.
    function onUnload() {
        realtime.disconnect()
        realtimeKey = null
        state.connected = false
    }

    function stop() {
        state.enabled = false
        clearStatusTimer()
        clearRunsTimer()
        if (signalTimer !== null) clearTimer(signalTimer)
        signalTimer = null
        for (const unsubscribe of unsubscribers) unsubscribe()
        unsubscribers = []
        realtime.disconnect()
        realtimeKey = null
        state.connected = false
    }

    // Called with the shared prop `larsadmin.notifications` on every page; null disables the bell.
    function init(props, i18n = null) {
        if (i18n) translate = { t: i18n.t ?? translate.t, tc: i18n.tc ?? i18n.t ?? translate.tc }

        if (!props || typeof props !== 'object' || !props.urls?.list) {
            if (state.enabled) stop()
            state.unread = null

            return
        }

        config = {
            urls: { list: props.urls.list, status: props.urls.status, runs: props.urls.runs },
            poll: props.poll === undefined || props.poll === null ? 60 : Number(props.poll),
            realtime: props.realtime ?? null,
        }

        if (props.unread === null || typeof props.unread === 'number') state.unread = props.unread

        const starting = !state.enabled
        // A page rendered with this prop means the server accepted the session again: resume polling.
        const resuming = state.sessionExpired

        state.enabled = true
        state.sessionExpired = false

        if (starting) {
            failures = 0
            unsubscribers = [lifecycle.onVisibilityChange(onVisibility), lifecycle.onUnload(onUnload)]
            // One status request sets the baseline (latest) and finds runs still active from earlier pages.
            if (lifecycle.visible()) refresh()
        }

        if (resuming && expiredToast !== null) {
            toasts.dismiss(expiredToast)
            expiredToast = null
        }

        if (!starting && resuming && lifecycle.visible()) {
            failures = 0
            refresh()
        }

        connectRealtime()
    }

    return {
        state,
        activeRuns,
        hasActiveRuns,
        init,
        stop,
        refresh,
        open,
        retry,
        loadMore,
        markRead,
        markAllRead,
        remove,
        trackRun,
        cancelRun,
        refreshRuns,
        onSignal,
        disconnect: () => realtime.disconnect(),
    }
}

let shared = null

export function useNotifications() {
    shared ??= createNotificationsStore()

    return shared
}
