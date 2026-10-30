import { describe, expect, it } from 'vitest'
import { areaPath, donutSegments, labelIndices, linePath, nearestIndex, niceTicks, scaleLinear, valueDomain } from './geometry.js'

describe('scaleLinear', () => {
    it('maps domain to range and handles flat domains', () => {
        const s = scaleLinear([0, 10], [100, 0])
        expect(s(0)).toBe(100)
        expect(s(5)).toBe(50)
        expect(scaleLinear([3, 3], [0, 10])(3)).toBe(5)
    })
})

describe('niceTicks', () => {
    it('produces round ticks covering the range', () => {
        const t = niceTicks(0, 97, 4)
        expect(t.ticks).toEqual([0, 50, 100])
        expect(t.min).toBe(0)
        expect(t.max).toBe(100)
    })

    it('handles zero, equal values and bad input', () => {
        expect(niceTicks(0, 0).ticks).toEqual([0, 1])
        const t = niceTicks(5, 5)
        expect(t.min).toBeLessThan(5)
        expect(t.max).toBeGreaterThan(5)
        expect(niceTicks(NaN, 1).ticks).toEqual([0, 1])
    })

    it('handles fractions without float noise', () => {
        expect(niceTicks(0, 1, 5).ticks).toEqual([0, 0.2, 0.4, 0.6, 0.8, 1])
    })
})

describe('valueDomain', () => {
    it('includes zero and ignores nulls', () => {
        const d = valueDomain([null, 12, 40])
        expect(d.min).toBe(0)
        expect(d.max).toBeGreaterThanOrEqual(40)
        expect(valueDomain([null]).max).toBe(1)
    })
})

describe('paths', () => {
    it('builds a line and breaks on null', () => {
        expect(linePath([{ x: 0, y: 10 }, { x: 50, y: 5 }])).toBe('M0 10 L50 5')
        expect(linePath([{ x: 0, y: 1 }, { x: 1, y: null }, { x: 2, y: 3 }])).toBe('M0 1 M2 3')
        expect(linePath([])).toBe('')
    })

    it('closes areas to the baseline', () => {
        expect(areaPath([{ x: 0, y: 10 }, { x: 100, y: 20 }], 100)).toBe('M0 10 L100 20 L100 100 L0 100 Z')
    })
})

describe('labelIndices / nearestIndex', () => {
    it('keeps first and last', () => {
        expect(labelIndices(3)).toEqual([0, 1, 2])
        const idx = labelIndices(30, 5)
        expect(idx[0]).toBe(0)
        expect(idx.at(-1)).toBe(29)
        expect(idx.length).toBe(5)
        expect(labelIndices(0)).toEqual([])
    })

    it('snaps pointer fractions', () => {
        expect(nearestIndex(0, 5)).toBe(0)
        expect(nearestIndex(0.5, 5)).toBe(2)
        expect(nearestIndex(2, 5)).toBe(4)
        expect(nearestIndex(0.4, 1)).toBe(0)
    })
})

describe('donutSegments', () => {
    it('splits the circumference proportionally', () => {
        const s = donutSegments([1, 3], 0)
        expect(s.map((x) => x.length)).toEqual([25, 75])
        expect(s[1].offset).toBe(25)
        expect(s[1].dashoffset).toBe(-25)
    })

    it('applies the gap only when several slices show', () => {
        expect(donutSegments([5], 1)[0].dasharray).toBe('100 0')
        expect(donutSegments([1, 1], 1)[0].dasharray).toBe('49 51')
    })

    it('skips zero and invalid values, empty -> []', () => {
        expect(donutSegments([0, 2, -1, null]).map((x) => x.index)).toEqual([1])
        expect(donutSegments([0, 0])).toEqual([])
    })
})
