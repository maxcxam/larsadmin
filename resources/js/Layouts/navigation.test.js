import { describe, expect, it } from 'vitest'
import { isDashboardActive } from './navigation'

const page = (uriKey, component = 'Dashboard') => ({ component, props: { dashboard: uriKey === undefined ? null : { uriKey } } })

describe('isDashboardActive', () => {
    it('matches the dashboard the server rendered', () => {
        expect(isDashboardActive({ uriKey: 'sales' }, page('sales'))).toBe(true)
        expect(isDashboardActive({ uriKey: 'main' }, page('sales'))).toBe(false)
    })

    it('does not fall back to the first entry', () => {
        expect(isDashboardActive({ uriKey: 'sales' }, page(undefined))).toBe(false)
    })

    it('is inactive outside the dashboard page', () => {
        expect(isDashboardActive({ uriKey: 'sales' }, page('sales', 'Entities/Index'))).toBe(false)
    })
})
