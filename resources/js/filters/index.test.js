import { describe, expect, it } from 'vitest'
import { cleanFilters, isFilterActive } from './index.js'

describe('cleanFilters', () => {
    it('drops empty values and empty date-range ends', () => {
        expect(cleanFilters({ a: '', b: null, c: undefined, d: 'x', e: { from: '', to: '' }, f: { from: '2026-01-01', to: '' } }))
            .toEqual({ d: 'x', f: { from: '2026-01-01' } })
    })

    it('keeps falsy-but-meaningful values', () => {
        expect(cleanFilters({ a: 0, b: false })).toEqual({ a: 0, b: false })
    })

    it('sends an explicit empty for a cleared filter that has a default', () => {
        const definitions = [{ key: 'status', default: 'active' }, { key: 'type', default: null }, { key: 'plain' }]

        expect(cleanFilters({ status: null, type: '' }, definitions)).toEqual({ status: '' })
        expect(cleanFilters({}, definitions)).toEqual({ status: '' })
    })

    it('keeps a chosen value over the default', () => {
        expect(cleanFilters({ status: 'archived' }, [{ key: 'status', default: 'active' }])).toEqual({ status: 'archived' })
    })

    it('treats falsy defaults as defaults', () => {
        expect(cleanFilters({}, [{ key: 'flag', default: false }, { key: 'n', default: 0 }])).toEqual({ flag: '', n: '' })
    })
})

describe('isFilterActive', () => {
    it('is false for empty values and the explicit empty', () => {
        expect(isFilterActive('')).toBe(false)
        expect(isFilterActive({ from: '' })).toBe(false)
        expect(isFilterActive('x')).toBe(true)
    })
})
