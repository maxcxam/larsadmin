import { computed, reactive } from 'vue'

// Toast stack shared by the whole panel (flash toasts, new notifications, finished background tasks).
// At most `max` toasts are visible; the rest wait. A visible toast's timer starts when it is shown and is
// paused while it is hovered or focused (WCAG 2.2.1). `key` de-duplicates (a notification is toasted once).
//
// push({ message, type, title?, link?, timeout?, key? }) -> id (or null for a duplicate key)
//   type: 'success' | 'info' | 'warning' | 'error' ('danger' is an alias of 'error')
//   link: { href, label, download? } - rendered only when the href is same-origin (checked by ToastStack)
//   timeout: ms, 0 = stays until dismissed

export const TOAST_TYPES = ['success', 'info', 'warning', 'error']

export function normalizeType(type) {
    if (type === 'danger') return 'error'

    return TOAST_TYPES.includes(type) ? type : 'success'
}

export function createToasts({ max = 3, maxKeys = 500, setTimer = (fn, ms) => setTimeout(fn, ms), clearTimer = (id) => clearTimeout(id), now = () => Date.now() } = {}) {
    const state = reactive({ items: [] })
    const seenKeys = new Set()
    const timers = new Map() // id -> { handle, startedAt, remaining, paused }
    let sequence = 0

    const visible = computed(() => state.items.slice(0, max))
    const hiddenCount = computed(() => Math.max(0, state.items.length - max))

    function arm(toast) {
        const timer = timers.get(toast.id)

        if (!timer || timer.handle !== null || timer.paused || timer.remaining <= 0) return

        timer.startedAt = now()
        timer.handle = setTimer(() => dismiss(toast.id), timer.remaining)
    }

    function sync() {
        for (const toast of state.items.slice(0, max)) arm(toast)
    }

    function push({ message, type = 'success', title = null, link = null, timeout = 4000, key = null } = {}) {
        if ((message === undefined || message === null || message === '') && !title) return null

        if (key !== null && key !== undefined) {
            if (seenKeys.has(String(key))) return null
            seenKeys.add(String(key))
            // Bounded memory in a long-lived tab: forget the oldest keys (a Set iterates in insertion order).
            while (seenKeys.size > maxKeys) seenKeys.delete(seenKeys.values().next().value)
        }

        const id = ++sequence
        const toast = {
            id,
            type: normalizeType(type),
            title: title ? String(title) : null,
            message: message === undefined || message === null ? '' : String(message),
            link: link && typeof link.href === 'string' ? { href: link.href, label: String(link.label ?? ''), download: !!link.download, onOpen: link.onOpen ?? null } : null,
        }

        timers.set(id, { handle: null, startedAt: 0, remaining: Math.max(0, Number(timeout) || 0), paused: false })
        state.items.push(toast)
        sync()

        return id
    }

    function dismiss(id) {
        const timer = timers.get(id)

        if (timer?.handle !== null && timer?.handle !== undefined) clearTimer(timer.handle)
        timers.delete(id)

        const index = state.items.findIndex((toast) => toast.id === id)

        if (index !== -1) state.items.splice(index, 1)
        sync()
    }

    function pause(id) {
        const timer = timers.get(id)

        if (!timer || timer.paused) return

        timer.paused = true

        if (timer.handle !== null) {
            clearTimer(timer.handle)
            timer.handle = null
            timer.remaining = Math.max(1, timer.remaining - (now() - timer.startedAt))
        }
    }

    function resume(id) {
        const timer = timers.get(id)

        if (!timer || !timer.paused) return

        timer.paused = false

        const toast = state.items.findIndex((item) => item.id === id)

        if (toast !== -1 && toast < max) arm(state.items[toast])
    }

    function clear() {
        for (const timer of timers.values()) if (timer.handle !== null) clearTimer(timer.handle)
        timers.clear()
        state.items.splice(0)
    }

    return { state, visible, hiddenCount, push, dismiss, pause, resume, clear }
}

let shared = null

export function useToasts() {
    shared ??= createToasts()

    return shared
}
