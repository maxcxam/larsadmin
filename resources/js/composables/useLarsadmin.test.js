import { describe, expect, it, vi } from 'vitest'

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { larsadmin: { path: '/admin' } } }) }))

const { buildUrl, useLarsadmin, withParams } = await import('./useLarsadmin.js')

describe('url()', () => {
    const { url, entityUrl } = useLarsadmin()

    it('joins segments under the panel path', () => {
        expect(url()).toBe('/admin')
        expect(entityUrl('users', 5, 'edit')).toBe('/admin/entities/users/5/edit')
    })

    it('percent-encodes every segment', () => {
        expect(entityUrl('users', 'a/b c?d#e')).toBe('/admin/entities/users/a%2Fb%20c%3Fd%23e')
        expect(entityUrl('users', '..%2Fx')).toBe('/admin/entities/users/..%252Fx')
    })

    it.each(['.', '..'])('rejects the "%s" dot segment', (segment) => {
        expect(() => entityUrl('users', segment)).toThrow(/Refusing/)
        expect(() => buildUrl('/admin', [segment])).toThrow(/Refusing/)
    })

    it('does not treat segments that merely contain dots as traversal', () => {
        expect(entityUrl('users', '..a')).toBe('/admin/entities/users/..a')
    })
})

describe('recordUrl()', () => {
    const { recordUrl } = useLarsadmin()

    it('prefers server-built urls, including null for "not allowed"', () => {
        const record = { id: '../x', urls: { show: '/admin/entities/users/1', edit: null, destroy: null } }

        expect(recordUrl('users', record, 'show')).toBe('/admin/entities/users/1')
        expect(recordUrl('users', record, 'edit')).toBeNull()
        expect(recordUrl('users', record, 'destroy')).toBeNull()
    })

    it('falls back to encoded local urls gated by the authorized flags', () => {
        const record = { id: 'a b', authorizedToView: true, authorizedToUpdate: true, authorizedToDelete: false }

        expect(recordUrl('users', record, 'show')).toBe('/admin/entities/users/a%20b')
        expect(recordUrl('users', record, 'edit')).toBe('/admin/entities/users/a%20b/edit')
        expect(recordUrl('users', record, 'destroy')).toBeNull()
        expect(recordUrl('users', record, 'detach')).toBeNull()
    })

    it('throws for a dot-segment id in the fallback path', () => {
        expect(() => recordUrl('users', { id: '..', authorizedToView: true }, 'show')).toThrow()
    })
})

describe('withParams()', () => {
    it('appends with ? or & and skips empties and existing keys', () => {
        expect(withParams('/x', { a: 1, b: '', c: null })).toBe('/x?a=1')
        expect(withParams('/x?a=1', { a: 2, b: 3 })).toBe('/x?a=1&b=3')
        expect(withParams('/x', null)).toBe('/x')
    })
})
