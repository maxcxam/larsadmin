import { describe, expect, it } from 'vitest'
import { errorCount, firstTabWithErrors, hasError, nodeAttributes, normalizeLayout } from './layout'

describe('layout helpers', () => {
    const fields = [{ attribute: 'a' }, { attribute: 'b' }, { attribute: 'rel', panel: true }]

    it('falls back to one implicit panel without relation fields', () => {
        const [panel, ...rest] = normalizeLayout(null, fields)

        expect(rest).toEqual([])
        expect(panel).toMatchObject({ type: 'panel', name: null, columns: 1, fields: ['a', 'b'] })
        expect(normalizeLayout([], [])).toEqual([])
    })

    it('keeps a server layout untouched', () => {
        const layout = [{ type: 'panel', key: 'p0', fields: ['a'] }]

        expect(normalizeLayout(layout, fields)).toBe(layout)
    })

    it('collects attributes of panels and tabs', () => {
        expect(nodeAttributes({ type: 'panel', fields: ['a'] })).toEqual(['a'])
        expect(nodeAttributes({ type: 'tabs', tabs: [{ fields: ['a'] }, { fields: ['b', 'c'] }] })).toEqual(['a', 'b', 'c'])
    })

    it('matches errors by attribute and dotted children', () => {
        expect(hasError('tags', { 'tags.0': 'x' })).toBe(true)
        expect(hasError('tag', { tags: 'x' })).toBe(false)
        expect(errorCount(['a', 'b', 'c'], { a: 'x', c: 'y' })).toBe(2)
    })

    it('finds the first tab with errors', () => {
        const tabs = [{ fields: ['a'] }, { fields: ['b'] }, { fields: ['c'] }]

        expect(firstTabWithErrors(tabs, { b: 'x', c: 'y' })).toBe(1)
        expect(firstTabWithErrors(tabs, {})).toBe(-1)
    })
})
