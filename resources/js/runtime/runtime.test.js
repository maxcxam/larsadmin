// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import * as vue from 'vue'
import * as inertia from '@inertiajs/vue3'
import * as runtimeVue from './vue.js'
import * as runtimeInertia from './inertia.js'
import * as sdk from '../sdk/index.js'

// The import-map targets must be the panel's own modules, not copies (design 1.5 §5.1, ADR-030). The build-level
// check (one vue/inertia chunk shared by app.js and runtime/*.js) is singleInstance.js.
describe('runtime entries', () => {
    it('re-export the very same Vue', () => {
        expect(runtimeVue.ref).toBe(vue.ref)
        expect(runtimeVue.createApp).toBe(vue.createApp)
        expect(runtimeVue.defineModel).toBe(vue.defineModel)
        const names = (module) => Object.keys(module).filter((key) => key !== '__esModule').sort()

        expect(names(runtimeVue)).toEqual(names(vue))
    })

    it('re-export the very same Inertia client', () => {
        expect(runtimeInertia.usePage).toBe(inertia.usePage)
        expect(runtimeInertia.router).toBe(inertia.router)
    })
})

describe('SDK exports (design §5.5)', () => {
    it('exposes exactly the public contract', () => {
        expect(Object.keys(sdk).sort()).toEqual([
            'CardShell',
            'ConfirmDialog',
            'Icon',
            'apiVersion',
            'buildUrl',
            'defineExtension',
            'useCardData',
            'useI18n',
            'useJsonRequest',
            'useJsonResource',
            'useLarsadmin',
            'useNumberFormat',
            'useTheme',
            'useToasts',
            'withParams',
        ])
        expect(sdk.apiVersion).toMatch(/^\d+\.\d+$/)
        expect(sdk.Icon.NAMES).toHaveLength(29)
        expect(sdk.Icon.NAMES).toContain('pencil')
    })

    it('useTheme().resolved follows the stored choice', () => {
        document.documentElement.removeAttribute('data-theme')
        expect(['light', 'dark']).toContain(sdk.useTheme().resolved.value)
    })

    it('useToasts drops links', () => {
        const toasts = sdk.useToasts()
        const id = toasts.push({ message: 'Saved', link: { href: 'https://evil.test', label: 'x' } })

        expect(id).toBeTypeOf('number')
        toasts.dismiss(id)
    })
})
