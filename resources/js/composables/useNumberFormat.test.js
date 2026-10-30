import { describe, expect, it } from 'vitest'
import { formatNumber } from './useNumberFormat.js'

const plain = (s) => s.replace(/[\s  ]/g, '')

describe('formatNumber', () => {
    it('renders null-ish values as a dash', () => {
        expect(formatNumber(null)).toBe('—')
        expect(formatNumber(undefined)).toBe('—')
        expect(formatNumber(NaN)).toBe('—')
    })

    it('keeps zero', () => {
        expect(formatNumber(0)).toBe('0')
    })

    it('uses the locale separators', () => {
        expect(plain(formatNumber(1234.5, {}, 'en'))).toBe('1,234.5')
        expect(plain(formatNumber(1234.5, {}, 'uk'))).toBe('1234,5')
    })

    it('formats currency', () => {
        const out = formatNumber(1500, { format: { style: 'currency', currency: 'USD' } }, 'en')
        expect(out).toContain('$')
        expect(plain(out)).toContain('1,500')
    })

    it('ignores currency style without a code', () => {
        expect(formatNumber(5, { format: { style: 'currency', currency: null } }, 'en')).toBe('5')
    })

    it('supports compact notation and fraction digits', () => {
        expect(formatNumber(1500000, { format: { notation: 'compact' } }, 'en')).toBe('1.5M')
        expect(formatNumber(1.2345, { format: { maximumFractionDigits: 1 } }, 'en')).toBe('1.2')
    })

    it('applies prefix and suffix', () => {
        expect(formatNumber(12, { prefix: '~', suffix: ' pcs' }, 'en')).toBe('~12 pcs')
    })

    it('does not throw on invalid Intl options', () => {
        expect(formatNumber(3, { format: { style: 'bogus' } }, 'en')).toBe('3')
    })
})
