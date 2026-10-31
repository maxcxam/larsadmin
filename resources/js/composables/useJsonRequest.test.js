import { describe, expect, it, vi } from 'vitest'
import { createJsonRequest, isSameOrigin, readCookie } from './useJsonRequest'

function response(status, body = null) {
    return { status, ok: status >= 200 && status < 300, text: async () => (body === null ? '' : JSON.stringify(body)) }
}

function make(status, body, cookie = 'a=1; XSRF-TOKEN=eyJpdiI6%3D%3D; b=2') {
    const fetch = vi.fn(async () => response(status, body))
    const reload = vi.fn()

    return { fetch, reload, request: createJsonRequest({ fetch, reload, cookie: () => cookie, base: () => 'https://panel.test/admin/posts' }) }
}

describe('readCookie', () => {
    it('reads and decodes a cookie by name', () => {
        expect(readCookie('XSRF-TOKEN', 'a=1; XSRF-TOKEN=abc%3D; b=2')).toBe('abc=')
        expect(readCookie('XSRF-TOKEN', 'a=1')).toBeNull()
        expect(readCookie('XSRF-TOKEN', '')).toBeNull()
    })
})

describe('useJsonRequest', () => {
    it('sends the XSRF cookie as X-XSRF-TOKEN on mutations, with a JSON body', async () => {
        const { fetch, request } = make(200, { unread: 2 })

        const result = await request.send('POST', '/admin/api/notifications/1/read', { a: 1 })

        expect(result).toEqual({ ok: true, status: 200, data: { unread: 2 } })

        const [url, init] = fetch.mock.calls[0]

        expect(url).toBe('/admin/api/notifications/1/read')
        expect(init).toMatchObject({ method: 'POST', credentials: 'same-origin', body: '{"a":1}' })
        expect(init.headers).toMatchObject({ Accept: 'application/json', 'X-XSRF-TOKEN': 'eyJpdiI6==', 'Content-Type': 'application/json' })
    })

    it('does not send the token on GET', async () => {
        const { fetch, request } = make(200, { items: [] })

        await request.send('GET', '/admin/api/notifications')

        expect(fetch.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBeUndefined()
        expect(fetch.mock.calls[0][1].body).toBeUndefined()
    })

    it.each([401, 419])('reloads the page on %i for a user-initiated mutation', async (status) => {
        const { reload, request } = make(status, { message: 'x' })

        expect(await request.send('DELETE', '/admin/api/notifications/1')).toEqual({ ok: false, status, data: null })
        expect(reload).toHaveBeenCalledOnce()
    })

    it.each([403, 404, 409, 500])('reports %i without reloading', async (status) => {
        const { reload, request } = make(status, { message: 'nope' })

        expect(await request.send('POST', '/admin/api/runs/1/cancel')).toEqual({ ok: false, status, data: { message: 'nope' } })
        expect(reload).not.toHaveBeenCalled()
    })

    it('resolves (never throws) on network errors and non-JSON bodies', async () => {
        const failing = createJsonRequest({ fetch: async () => Promise.reject(new TypeError('offline')), reload: vi.fn(), cookie: () => '' })

        expect(await failing.send('GET', '/x')).toEqual({ ok: false, status: 0, data: null })

        const html = createJsonRequest({ fetch: async () => ({ status: 200, ok: true, text: async () => '<html>' }), reload: vi.fn(), cookie: () => '' })

        expect(await html.send('GET', '/x')).toEqual({ ok: true, status: 200, data: null })
    })

    it.each([401, 419])('never reloads for a background GET on %i (CRN-07): it reports expired', async (status) => {
        const { reload, request } = make(status, { message: 'x' })

        expect(await request.send('GET', '/admin/api/notifications/status')).toEqual({ ok: false, status, data: null, expired: true })
        expect(reload).not.toHaveBeenCalled()
    })

    it('the background flag overrides the method default', async () => {
        const quietPost = make(419)

        expect((await quietPost.request.send('POST', '/x', undefined, { background: true })).expired).toBe(true)
        expect(quietPost.reload).not.toHaveBeenCalled()

        const loudGet = make(401)

        await loudGet.request.send('GET', '/x', undefined, { background: false })
        expect(loudGet.reload).toHaveBeenCalledOnce()
    })
})

describe('CSRF token only for the panel origin (SECX-05)', () => {
    it.each([
        ['/admin/ext/rating', true],
        ['ext/rating', true],
        ['https://panel.test/admin/x', true],
        ['https://api.vendor.example/v1', false],
        ['//api.vendor.example/v1', false],
        ['http://panel.test/admin/x', false],
        ['https://panel.test:8443/x', false],
    ])('%s -> token %s', async (url, sent) => {
        const { fetch, request } = make(200, {})

        await request.send('POST', url, { a: 1 })

        const headers = fetch.mock.calls[0][1].headers
        expect('X-XSRF-TOKEN' in headers).toBe(sent)
        expect('X-Requested-With' in headers).toBe(sent)
        expect(fetch.mock.calls[0][1].credentials).toBe('same-origin')
    })

    it('treats unparsable URLs and a missing page origin as foreign', () => {
        expect(isSameOrigin('http://[bad', 'https://panel.test/')).toBe(false)
        expect(isSameOrigin('/x', null)).toBe(false)
        expect(isSameOrigin('/x', 'https://panel.test/admin')).toBe(true)
    })
})
