// @vitest-environment jsdom
import { mkdirSync, readFileSync, rmSync, writeFileSync, existsSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { afterAll, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { build } from 'vite'
import tailwindcss from '@tailwindcss/vite'
import larsadmin, { assetFileName, bundledRuntime, EXTERNALS, presetConfig, SDK_PATH, TEST_EXCLUDE } from './vite.js'
import { mountCard, mountDisplay, mountField, withPanel } from './testing.js'
import { mount } from '@vue/test-utils'
import * as sdk from './sdk.js'

const KIT = dirname(fileURLToPath(import.meta.url))
// Inside node_modules so the fixture resolves `vue` from the package's own dependencies.
const TMP = resolve(KIT, '../node_modules/.cache/larsadmin-kit-test')

afterAll(() => rmSync(TMP, { recursive: true, force: true }))

describe('presetConfig', () => {
    it('builds an ES library with the shared runtime external', () => {
        const config = presetConfig({ mode: 'production' })

        expect(config.base).toBe('./')
        expect(config.resolve.dedupe).toEqual(['vue', '@inertiajs/vue3'])
        expect(config.build).toMatchObject({
            outDir: 'dist',
            emptyOutDir: true,
            manifest: true,
            cssCodeSplit: false,
            lib: { entry: 'resources/js/extension.js', formats: ['es'], fileName: 'extension', cssFileName: 'extension' },
        })

        const { external, output } = config.build.rolldownOptions

        expect(EXTERNALS).toEqual(['vue', '@inertiajs/vue3', 'larsadmin'])
        expect(EXTERNALS.every((id) => external(id))).toBe(true)
        expect(external('vue/dist/vue.esm-bundler.js')).toBe(false)
        expect(output.chunkFileNames).toBe('chunks/[name]-[hash].js')
        expect(output.assetFileNames).toBe(assetFileName)
        expect(assetFileName({ names: ['style.css'] })).toBe('extension.css')
        expect(assetFileName({ name: 'extension.css' })).toBe('extension.css')
        expect(assetFileName({ names: ['logo.svg'] })).toBe('assets/[name]-[hash][extname]')
    })

    it('honours entry / outDir and older Vite (rollupOptions)', () => {
        const config = presetConfig({ entry: 'src/main.js', outDir: 'build', rolldown: false })

        expect(config.build.lib.entry).toBe('src/main.js')
        expect(config.build.outDir).toBe('build')
        expect(config.build.rollupOptions).toBeDefined()
        expect(config.build.rolldownOptions).toBeUndefined()
    })

    it('aliases larsadmin to kit/sdk.js under vitest, in jsdom', () => {
        const config = presetConfig({ mode: 'test' })

        expect(config.test).toEqual({ environment: 'jsdom', exclude: TEST_EXCLUDE })
        expect(TEST_EXCLUDE).toEqual(expect.arrayContaining(['vendor/**', 'dist/**', '**/node_modules/**']))
        expect(config.resolve.alias).toEqual([{ find: /^larsadmin$/, replacement: SDK_PATH }])
        expect(existsSync(SDK_PATH)).toBe(true)
        expect(config.build).toBeUndefined()
    })

    it('is a plugin pair (config + guard)', () => {
        expect(larsadmin().map((plugin) => plugin.name)).toEqual(['larsadmin-extension', 'larsadmin-extension-guard'])
    })
})

describe('guard', () => {
    it('finds bundled copies of Vue, Inertia and the SDK', () => {
        const bundle = {
            'extension.js': { type: 'chunk', fileName: 'extension.js', moduleIds: ['/x/resources/js/extension.js', '/x/node_modules/@vue/runtime-core/dist/runtime-core.esm-bundler.js'] },
            'chunks/a.js': { type: 'chunk', fileName: 'chunks/a.js', modules: { '/x/node_modules/@inertiajs/core/dist/index.js': {}, '/x/vendor/maxcxam/larsadmin/kit/sdk.js': {} } },
            'extension.css': { type: 'asset', fileName: 'extension.css' },
        }

        expect(bundledRuntime(bundle).map(({ module }) => module.split('/').slice(-3).join('/'))).toEqual([
            'runtime-core/dist/runtime-core.esm-bundler.js',
            'core/dist/index.js',
            'larsadmin/kit/sdk.js',
        ])
        expect(bundledRuntime({ 'e.js': { type: 'chunk', fileName: 'e.js', moduleIds: ['/x/resources/js/extension.js'] } })).toEqual([])
    })
})

function fixture(name, source) {
    const root = resolve(TMP, name)

    rmSync(root, { recursive: true, force: true })
    mkdirSync(resolve(root, 'resources/js'), { recursive: true })
    writeFileSync(resolve(root, 'resources/js/extension.js'), source)
    writeFileSync(resolve(root, 'resources/js/Lazy.js'), "import { h } from 'vue'\nexport default { render: () => h('p', 'lazy') }\n")

    return root
}

function buildFixture(root, plugins = []) {
    return build({ root, configFile: false, logLevel: 'silent', mode: 'production', plugins: [...plugins, larsadmin()] })
}

describe('preset build (fixture extension)', () => {
    it('emits extension.js with bare shared imports, a chunk and a manifest', async () => {
        const root = fixture('ok', [
            "import { h, defineAsyncComponent } from 'vue'",
            "import { usePage } from '@inertiajs/vue3'",
            "import { defineExtension } from 'larsadmin'",
            "const Input = { props: ['field'], setup: (props) => () => h('input', { id: props.field.attribute, 'data-path': usePage().props?.larsadmin?.path }) }",
            "export default defineExtension({ name: 'acme/demo', requires: '^1.5', fields: { 'acme/demo': { form: Input, display: defineAsyncComponent(() => import('./Lazy.js')) } } })",
        ].join('\n'))

        await buildFixture(root)

        const code = readFileSync(resolve(root, 'dist/extension.js'), 'utf8')

        expect(code).toMatch(/from\s*["']vue["']/)
        expect(code).toMatch(/from\s*["']@inertiajs\/vue3["']/)
        expect(code).toMatch(/from\s*["']larsadmin["']/)
        expect(code).toMatch(/import\(["']\.\/chunks\/Lazy-[\w-]+\.js["']\)/)
        expect(code).not.toContain('createRenderer')
        expect(existsSync(resolve(root, 'dist/.vite/manifest.json'))).toBe(true)
    }, 30000)

    it('emits dist/extension.css with Tailwind utilities from the kit theme (sizes, text, radius, breakpoints, --la-* colours)', async () => {
        const root = fixture('css', [
            "import './extension.css'",
            "import { h } from 'vue'",
            "import { defineExtension } from 'larsadmin'",
            "const Card = { render: () => h('div', { class: 'size-6 p-4 text-sm rounded md:flex bg-canvas text-danger-fg' }) }",
            "export default defineExtension({ name: 'acme/css', requires: '^1.5', cards: { 'acme/css': Card } })",
        ].join('\n'))
        const theme = resolve(KIT, 'theme.css').replaceAll('\\', '/')

        writeFileSync(resolve(root, 'resources/js/extension.css'), `@import "tailwindcss/utilities";\n@import "${theme}";\n@source "./";\n`)

        await buildFixture(root, [tailwindcss()])

        expect(existsSync(resolve(root, 'dist/extension.css'))).toBe(true)
        expect(existsSync(resolve(root, 'dist/assets'))).toBe(false)

        const css = readFileSync(resolve(root, 'dist/extension.css'), 'utf8')

        for (const selector of ['.size-6', '.p-4', '.text-sm', '.rounded', '.md\\:flex', '.bg-canvas', '.text-danger-fg']) {
            expect(css).toContain(selector)
        }
        expect(css).toContain('var(--la-canvas-default)')
        // Theme as reference: the panel's variables are used, not redefined.
        expect(css).not.toMatch(/--spacing:\s/)
        expect(css).toMatch(/@media \(width>=48rem\)|@media \(min-width:\s?48rem\)/)
    }, 30000)

    it('stops the build when Vue is bundled through a deep import', async () => {
        const root = fixture('bad', "import { h } from 'vue/dist/vue.runtime.esm-bundler.js'\nexport default { render: () => h('p') }\n")

        await expect(buildFixture(root)).rejects.toThrow(/own copy of Vue, Inertia or the larsadmin SDK/)
    }, 30000)
})

describe('kit/sdk.js and kit/testing.js (authors\' vitest)', () => {
    it('is the SDK built from resources/js/sdk', () => {
        expect(sdk.apiVersion).toMatch(/^\d+\.\d+$/)
        expect(typeof sdk.defineExtension).toBe('function')
        expect(sdk.Icon.NAMES).toContain('pencil')
    })

    it('mountField wires v-model and gives SDK composables a panel page with extension translations', async () => {
        const Stars = defineComponent({
            props: ['field', 'modelValue', 'errors', 'describedBy'],
            emits: ['update:modelValue'],
            setup(props, { emit }) {
                const { t } = sdk.useI18n()
                const { url } = sdk.useLarsadmin()

                return () => h('button', { id: props.field.attribute, 'data-url': url('ext', 'stars'), 'aria-describedby': props.describedBy, onClick: () => emit('update:modelValue', props.modelValue + 1) }, t('acme-rating::stars', { count: props.modelValue }))
            },
        })

        const wrapper = mountField(Stars, { field: { attribute: 'rating', value: 2 }, errors: ['Too low.'], panel: { translations: { 'acme-rating': { stars: ':count stars' } }, path: '/backoffice' } })

        expect(wrapper.text()).toBe('2 stars')
        expect(wrapper.find('button').attributes('data-url')).toBe('/backoffice/ext/stars')
        expect(wrapper.find('button').attributes('aria-describedby')).toBe('rating-error')

        await wrapper.find('button').trigger('click')

        expect(wrapper.emitted('update:modelValue')[0]).toEqual([3])
        expect(wrapper.text()).toBe('3 stars')
    })

    it('kit/theme.css maps colours exactly like the panel app.css', () => {
        const block = (css) => /@theme inline \{[\s\S]*?\n\}/.exec(css)?.[0]
        const kit = readFileSync(resolve(KIT, 'theme.css'), 'utf8')
        const panel = readFileSync(resolve(KIT, '../resources/css/app.css'), 'utf8')

        expect(block(kit)).toBeTruthy()
        expect(block(kit)).toBe(block(panel))
        expect(kit).toContain('@import "tailwindcss/theme.css" theme(reference);')
        expect(panel).not.toContain('kit/theme.css";')
    })

    it('mountField passes errorsByKey (default {})', () => {
        const Probe = defineComponent({ props: ['field', 'errorsByKey'], setup: (props) => () => h('span', JSON.stringify(props.errorsByKey)) })

        expect(mountField(Probe, { field: { attribute: 'gallery' } }).text()).toBe('{}')
        expect(mountField(Probe, { field: { attribute: 'gallery' }, errorsByKey: { 'gallery.upload.1': 'Too big.' } }).text()).toBe('{"gallery.upload.1":"Too big."}')
    })

    it('mountDisplay passes the display contract', () => {
        const Display = defineComponent({ props: ['field', 'context'], setup: (props) => () => h('span', `${props.context}:${props.field.displayedAs}`) })

        expect(mountDisplay(Display, { field: { attribute: 'rating', value: 4 }, context: 'index' }).text()).toBe('index:4')
        expect(mountDisplay(Display, { field: { attribute: 'rating', value: 4, displayedAs: '★★★★' } }).text()).toBe('detail:★★★★')
    })

    it('mountCard renders CardShell with the card name', () => {
        const Card = defineComponent({ props: ['card'], setup: (props) => () => h(sdk.CardShell, { card: props.card }, () => h('p', 'content')) })
        const wrapper = mountCard(Card, { card: { name: 'Top rated' } })

        expect(wrapper.find('h2').text()).toBe('Top rated')
        expect(wrapper.text()).toContain('content')
    })

    it('withPanel falls back to the bundled panel strings', () => {
        const Probe = defineComponent({ setup: () => () => h('span', sdk.useI18n().t('retry')) })

        expect(mount(Probe, withPanel()).text()).toBe('Повторити')
    })
})
