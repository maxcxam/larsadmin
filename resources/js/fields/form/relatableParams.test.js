import { describe, expect, it } from 'vitest'
import { relatableParams } from './relatableParams'

describe('relatableParams', () => {
    it('sends context + resourceId for create/update', () => {
        expect(relatableParams({ context: 'update', resourceId: 5 })).toEqual({ context: 'update', resourceId: 5 })
        expect(relatableParams({ context: 'create' })).toEqual({ context: 'create', resourceId: null })
    })
    it('sends context + action for action fields', () => {
        expect(relatableParams({ context: 'action', action: 'archive', resourceId: 5 })).toEqual({ context: 'action', action: 'archive' })
    })
})
