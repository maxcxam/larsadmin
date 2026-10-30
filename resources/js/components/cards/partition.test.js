import { describe, expect, it } from 'vitest'
import { isAdditive, partitionEmpty } from './partition'

describe('partition helpers', () => {
    it('treats a payload without the flag as additive', () => {
        expect(isAdditive({ slices: [] })).toBe(true)
        expect(isAdditive({ additive: true })).toBe(true)
        expect(isAdditive({ additive: false })).toBe(false)
    })

    it('is empty without slices or with a zero total', () => {
        expect(partitionEmpty(null)).toBe(true)
        expect(partitionEmpty({ slices: [], total: 5 })).toBe(true)
        expect(partitionEmpty({ slices: [{ value: 0 }], total: 0 })).toBe(true)
        expect(partitionEmpty({ slices: [{ value: 3 }], total: 3 })).toBe(false)
    })

    it('does not need a total for non-additive results', () => {
        expect(partitionEmpty({ additive: false, total: null, slices: [{ value: 4.5 }] })).toBe(false)
        expect(partitionEmpty({ additive: false, total: null, slices: [{ value: 0 }, { value: null }] })).toBe(true)
    })
})
