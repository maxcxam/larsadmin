import { onBeforeUnmount, ref } from 'vue'

// Shared JSON fetcher for lazily loaded widgets (Combobox options, related panels):
// AbortController + sequence guard so only the latest request may touch state,
// 401 -> reload (session expired), 403 -> `forbidden`, anything else -> `error`.
//
// `load(url, { background })` resolves to:
//   the parsed body  - success (status becomes 'idle')
//   null             - failed (status is 'error' / 'forbidden') or the page is reloading
//   undefined        - superseded by a newer request or cancelled: callers must ignore it
// With `background: true` the status is not switched to 'loading' (load-more, refresh with data on screen).
export function useJsonResource() {
    const status = ref('idle') // idle | loading | error | forbidden

    let controller = null
    let sequence = 0

    async function load(url, { background = false } = {}) {
        controller?.abort()
        controller = new AbortController()
        const mine = ++sequence

        if (!background) status.value = 'loading'

        try {
            const response = await fetch(url, {
                signal: controller.signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })

            if (mine !== sequence) return undefined

            if (response.status === 401) {
                window.location.reload()

                return null
            }

            if (response.status === 403) {
                status.value = 'forbidden'

                return null
            }

            if (!response.ok) throw new Error(`HTTP ${response.status}`)

            const body = await response.json()

            if (mine !== sequence) return undefined

            status.value = 'idle'

            return body
        } catch (error) {
            if (error?.name === 'AbortError' || mine !== sequence) return undefined

            status.value = 'error'

            return null
        }
    }

    // Aborts the in-flight request and invalidates its result.
    function cancel() {
        controller?.abort()
        controller = null
        sequence++
        status.value = 'idle'
    }

    onBeforeUnmount(() => {
        controller?.abort()
        sequence++
    })

    return { status, load, cancel }
}
