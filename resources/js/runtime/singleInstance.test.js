import { describe, expect, it } from 'vitest'
import { singleInstanceErrors } from './singleInstance'

const vue = '/repo/node_modules/vue/dist/vue.runtime.esm-bundler.js'
const runtimeCore = '/repo/node_modules/@vue/runtime-core/dist/runtime-core.esm-bundler.js'
const inertia = '/repo/node_modules/@inertiajs/vue3/dist/index.js'
const core = '/repo/node_modules/@inertiajs/core/dist/index.js'

const chunk = (fileName, moduleIds, imports = [], isEntry = false) => ({ type: 'chunk', fileName, moduleIds, imports, isEntry })

function goodBundle() {
    return {
        'app.js': chunk('app.js', ['/repo/resources/js/app.js'], ['chunks/vue-a.js', 'chunks/inertia-b.js', 'chunks/shared-c.js'], true),
        'runtime/vue.js': chunk('runtime/vue.js', ['/repo/resources/js/runtime/vue.js'], ['chunks/vue-a.js'], true),
        'runtime/inertia.js': chunk('runtime/inertia.js', ['/repo/resources/js/runtime/inertia.js'], ['chunks/inertia-b.js'], true),
        'runtime/larsadmin.js': chunk('runtime/larsadmin.js', ['/repo/resources/js/sdk/index.js'], ['chunks/vue-a.js', 'chunks/shared-c.js'], true),
        'chunks/vue-a.js': chunk('chunks/vue-a.js', [vue, runtimeCore]),
        'chunks/inertia-b.js': chunk('chunks/inertia-b.js', [inertia, core], ['chunks/vue-a.js']),
        'chunks/shared-c.js': chunk('chunks/shared-c.js', [
            '/repo/resources/js/composables/useI18n.js',
            '/repo/resources/js/extensions/registry.js',
            '/repo/resources/js/composables/useToasts.js',
            '/repo/resources/js/composables/useTheme.js',
            '/repo/resources/js/sdk/page.js',
        ], ['chunks/vue-a.js', 'chunks/inertia-b.js']),
        'app.css': { type: 'asset', fileName: 'app.css' },
    }
}

describe('singleInstanceErrors (one Vue, one Inertia)', () => {
    it('accepts runtime entries that share the vendor chunks with app.js', () => {
        expect(singleInstanceErrors(goodBundle())).toEqual([])
    })

    it('fails when Vue is bundled twice', () => {
        const bundle = goodBundle()
        bundle['chunks/shared-c.js'].moduleIds.push(runtimeCore)

        expect(singleInstanceErrors(bundle)).toEqual([expect.stringContaining('Vue must be in exactly one chunk, found 2')])
    })

    it('fails when Vue is inlined into an entry', () => {
        const bundle = goodBundle()
        delete bundle['chunks/vue-a.js']
        bundle['runtime/vue.js'].moduleIds.push(vue)

        expect(singleInstanceErrors(bundle).join('\n')).toContain('bundled into the entry runtime/vue.js')
    })

    it('fails when a runtime entry does not import the shared chunk', () => {
        const bundle = goodBundle()
        bundle['runtime/inertia.js'].imports = []

        expect(singleInstanceErrors(bundle)).toEqual(['runtime/inertia.js does not import the shared Inertia chunk chunks/inertia-b.js.'])
    })

    it('fails when a chunk imports an entry (it would be evaluated twice)', () => {
        const bundle = goodBundle()
        bundle['chunks/lazy-d.js'] = chunk('chunks/lazy-d.js', ['/repo/resources/js/Lazy.vue'], ['app.js'])

        expect(singleInstanceErrors(bundle)).toEqual(['chunks/lazy-d.js imports the entry app.js; entries must only import shared chunks.'])
    })

    it('reads module ids from `modules` when `moduleIds` is absent', () => {
        const bundle = goodBundle()

        for (const output of Object.values(bundle)) {
            if (output.type !== 'chunk') continue
            output.modules = Object.fromEntries(output.moduleIds.map((id) => [id, {}]))
            delete output.moduleIds
        }

        expect(singleInstanceErrors(bundle)).toEqual([])
    })

    it('fails when the SDK gets its own copy of the toast stack (CRX-06)', () => {
        const bundle = goodBundle()
        bundle['runtime/larsadmin.js'].moduleIds.push('/repo/resources/js/composables/useToasts.js')

        expect(singleInstanceErrors(bundle)).toEqual([expect.stringContaining('useToasts.js must be in exactly one chunk, found 2')])
    })

    it('fails when the registry is inlined into app.js', () => {
        const bundle = goodBundle()
        bundle['chunks/shared-c.js'].moduleIds = bundle['chunks/shared-c.js'].moduleIds.filter((id) => !id.endsWith('registry.js'))
        bundle['app.js'].moduleIds.push('/repo/resources/js/extensions/registry.js')

        expect(singleInstanceErrors(bundle)).toEqual(['Panel state module registry.js is bundled into the entry app.js; it must be in a shared chunk.'])
    })

    it('fails when runtime/larsadmin.js does not import the state chunk', () => {
        const bundle = goodBundle()
        bundle['runtime/larsadmin.js'].imports = ['chunks/vue-a.js']

        expect(singleInstanceErrors(bundle)).toEqual(expect.arrayContaining([expect.stringContaining('runtime/larsadmin.js does not import chunks/shared-c.js')]))
    })
})
