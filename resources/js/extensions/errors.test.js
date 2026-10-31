import { describe, expect, it } from 'vitest'
import { isFatalComponentError } from './errors'

describe('isFatalComponentError (CRX-01)', () => {
    it.each(['render function', 'setup function', 'mounted hook', 'updated', 'async component loader', 'component update'])('%s is fatal (dev)', (info) => {
        expect(isFatalComponentError(info)).toBe(true)
    })

    it.each(['native event handler', 'component event handler', 'watcher callback', 'watcher getter', 'unmounted hook', 'directive hook', '', undefined])('%s is not fatal (dev)', (info) => {
        expect(isFatalComponentError(info)).toBe(false)
    })

    it('reads production error-reference URLs', () => {
        const url = (code) => `https://vuejs.org/error-reference/#runtime-${code}`

        expect(isFatalComponentError(url(1))).toBe(true)
        expect(isFatalComponentError(url(0))).toBe(true)
        expect(isFatalComponentError(url('m'))).toBe(true)
        expect(isFatalComponentError(url(5))).toBe(false)
        expect(isFatalComponentError(url(6))).toBe(false)
        expect(isFatalComponentError(url(3))).toBe(false)
        expect(isFatalComponentError(url('um'))).toBe(false)
    })
})
