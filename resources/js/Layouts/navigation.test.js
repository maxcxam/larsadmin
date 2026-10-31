import { describe, expect, it } from 'vitest'
import { isDashboardActive, isToolActive, toolLinks } from './navigation'

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

describe('isToolActive', () => {
    const toolPage = (uriKey, component = 'Tool') => ({ component, props: { tool: uriKey === undefined ? null : { uriKey } } })

    it('matches the tool the server rendered', () => {
        expect(isToolActive({ uriKey: 'backups' }, toolPage('backups'))).toBe(true)
        expect(isToolActive({ uriKey: 'backups' }, toolPage('system-info'))).toBe(false)
    })

    it('is inactive on other pages and without a tool prop', () => {
        expect(isToolActive({ uriKey: 'backups' }, toolPage('backups', 'Dashboard'))).toBe(false)
        expect(isToolActive({ uriKey: 'backups' }, toolPage(undefined))).toBe(false)
    })
})

describe('toolLinks', () => {
    const names = ['file', 'database', 'globe']

    it('keeps allow-listed icons and falls back to the default one', () => {
        expect(toolLinks([
            { name: 'Backups', uriKey: 'backups', icon: 'database', url: '/admin/tools/backups' },
            { name: 'Info', uriKey: 'info', icon: null, url: '/admin/tools/info' },
            { name: 'Bad', uriKey: 'bad', icon: '<svg>', url: '/admin/tools/bad' },
        ], names).map((tool) => tool.icon)).toEqual(['database', 'file', 'file'])
    })

    it('skips malformed entries and non-arrays', () => {
        expect(toolLinks([null, { name: 'x' }, { url: '/a' }], names)).toEqual([])
        expect(toolLinks(undefined, names)).toEqual([])
    })
})
