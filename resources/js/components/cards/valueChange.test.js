import { describe, expect, it } from 'vitest'
import { changeDirection } from './valueChange'

describe('changeDirection', () => {
    it('detects growth and decline', () => {
        expect(changeDirection(12.5)).toBe('up')
        expect(changeDirection(0.05)).toBe('up')
        expect(changeDirection(-3)).toBe('down')
        expect(changeDirection(-0.05)).toBe('down')
    })

    it('treats zero and values that round to 0.0 as no change', () => {
        expect(changeDirection(0)).toBe('none')
        expect(changeDirection(-0)).toBe('none')
        expect(changeDirection(0.04)).toBe('none')
        expect(changeDirection(-0.04)).toBe('none')
    })

    it('returns null without a comparison', () => {
        expect(changeDirection(null)).toBeNull()
        expect(changeDirection(undefined)).toBeNull()
        expect(changeDirection(Number.NaN)).toBeNull()
    })
})
