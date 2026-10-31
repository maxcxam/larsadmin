// JSON requests outside Inertia that may change state (bell: read, delete, cancel). Framework-free, so the
// module-level notification store can use it too. Mutations carry the XSRF-TOKEN cookie as X-XSRF-TOKEN
// (Laravel's VerifyCsrfToken accepts it). 401 (session expired) and 419 (CSRF token expired) reload the page
// only for user-initiated requests: background requests (GET by default; polling) must never reload a page
// with unsaved form input (CRN-07). They resolve with `expired: true` instead and the caller stops polling.
//
// `send(method, url, body?)` resolves to { ok, status, data }:
//   ok: true            - 2xx, `data` is the parsed body (null when empty)
//   ok: false, status   - 403 / 404 / 409 / 422 / 5xx, or 0 on a network error; `data` is the parsed body if any
//   expired: true       - 401 / 419 of a background request (no reload)
// Options: { background } - defaults to true for GET / HEAD, false for mutations.
// It never throws.

export function readCookie(name, cookie = typeof document === 'undefined' ? '' : document.cookie) {
    for (const part of String(cookie ?? '').split(';')) {
        const index = part.indexOf('=')

        if (index === -1) continue
        if (part.slice(0, index).trim() !== name) continue

        try {
            return decodeURIComponent(part.slice(index + 1).trim())
        } catch {
            return null
        }
    }

    return null
}

export function createJsonRequest({
    fetch: fetchImpl = (...args) => globalThis.fetch(...args),
    reload = () => window.location.reload(),
    cookie = () => (typeof document === 'undefined' ? '' : document.cookie),
} = {}) {
    async function send(method, url, body = undefined, { background = undefined } = {}) {
        const verb = String(method).toUpperCase()
        const quiet = background ?? (verb === 'GET' || verb === 'HEAD')
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }

        if (verb !== 'GET' && verb !== 'HEAD') {
            const token = readCookie('XSRF-TOKEN', cookie())

            if (token) headers['X-XSRF-TOKEN'] = token
        }

        const init = { method: verb, credentials: 'same-origin', headers }

        if (body !== undefined) {
            headers['Content-Type'] = 'application/json'
            init.body = JSON.stringify(body)
        }

        let response

        try {
            response = await fetchImpl(url, init)
        } catch {
            return { ok: false, status: 0, data: null }
        }

        if (response.status === 401 || response.status === 419) {
            if (quiet) return { ok: false, status: response.status, data: null, expired: true }

            reload()

            return { ok: false, status: response.status, data: null }
        }

        let data = null

        try {
            const text = await response.text()
            data = text ? JSON.parse(text) : null
        } catch {
            data = null
        }

        return { ok: response.ok, status: response.status, data }
    }

    return { send }
}

let shared = null

export function useJsonRequest() {
    shared ??= createJsonRequest()

    return shared
}
