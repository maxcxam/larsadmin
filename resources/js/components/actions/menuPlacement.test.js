import { describe, expect, it } from 'vitest'
import { menuPlacement } from './menuPlacement'

const viewport = { width: 1000, height: 800 }

describe('menuPlacement', () => {
    it('opens below when the menu fits', () => {
        expect(menuPlacement({ top: 100, bottom: 120, right: 900 }, 200, viewport)).toEqual({ top: 124, right: 100, up: false })
    })

    it('flips upwards near the viewport bottom', () => {
        expect(menuPlacement({ top: 700, bottom: 720, right: 900 }, 200, viewport)).toEqual({ bottom: 104, right: 100, up: true })
    })

    it('stays below when there is even less room above', () => {
        expect(menuPlacement({ top: 60, bottom: 80, right: 900 }, 900, viewport).up).toBe(false)
    })

    it('keeps a margin from the right edge', () => {
        expect(menuPlacement({ top: 0, bottom: 20, right: 998 }, 10, viewport).right).toBe(8)
    })
})
