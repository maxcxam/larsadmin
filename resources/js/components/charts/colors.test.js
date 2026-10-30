import { describe, expect, it } from 'vitest'
import { isSafeColor, paletteColor, seriesColor } from './colors'

describe('chart colours', () => {
    it.each(['#fff', '#ffff', '#1f2328', '#1f2328cc', 'rgb(1, 2, 3)', 'rgba(1,2,3,.5)', 'rgb(1 2 3 / 50%)', 'hsl(210 50% 40%)', 'hsla(210, 50%, 40%, 0.4)', 'oklch(62.8% 0.25 29.2)', 'var(--la-chart-3)', ' #abc '])(
        'accepts %s',
        (color) => expect(isSafeColor(color)).toBe(true),
    )

    it.each([null, undefined, '', 42, 'red', '#12', '#ggg', 'url(https://example.com/x.png)', 'rgb(1,2,3) url(x)', 'rgb(var(--x))', 'var(--x, url(y))', 'expression(alert(1))', 'oklch(1 2 3);background:red', `#${'f'.repeat(70)}`])(
        'rejects %s',
        (color) => expect(isSafeColor(color)).toBe(false),
    )

    it('falls back to the cycling palette', () => {
        expect(paletteColor(0)).toBe('var(--la-chart-1)')
        expect(paletteColor(9)).toBe('var(--la-chart-2)')
        expect(seriesColor('url(x)', 2)).toBe('var(--la-chart-3)')
        expect(seriesColor(null, 0)).toBe('var(--la-chart-1)')
        expect(seriesColor(' #abcdef', 0)).toBe('#abcdef')
    })
})
