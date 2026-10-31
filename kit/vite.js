// Vite preset for larsadmin extensions (design 1.5 §6.4, ADR-030). Ships in the Composer package (kit/), not on npm.
//
//   // vite.config.js of an extension
//   import { defineConfig } from 'vite'
//   import vue from '@vitejs/plugin-vue'
//   import tailwindcss from '@tailwindcss/vite'
//   import larsadmin from './vendor/maxcxam/larsadmin/kit/vite.js'
//
//   export default defineConfig({ plugins: [vue(), tailwindcss(), larsadmin()] })
//
// Build: an ES library dist/extension.js (+ dist/extension.css, dist/chunks/*, dist/.vite/manifest.json) with
// `vue`, `@inertiajs/vue3` and `larsadmin` left as bare imports: the panel's import map provides them, so the
// extension shares the panel's single Vue and Inertia. A guard stops the build when a copy of Vue or Inertia
// got bundled anyway. Tests (vitest): `larsadmin` resolves to kit/sdk.js and the environment is jsdom.
//
// Hand-written ESM without dependencies besides node:path / node:url.
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const KIT = dirname(fileURLToPath(import.meta.url))

export const SDK_PATH = resolve(KIT, 'sdk.js')
export const EXTERNALS = Object.freeze(['vue', '@inertiajs/vue3', 'larsadmin'])

// Vitest's default excludes plus Composer's vendor/ and the built dist/.
export const TEST_EXCLUDE = Object.freeze(['**/node_modules/**', '**/.git/**', 'vendor/**', 'dist/**'])

/** Output name of an emitted asset: every CSS file of the bundle is extension.css. Exported for tests. */
export function assetFileName(asset) {
    const names = asset?.names ?? (asset?.name ? [asset.name] : [])

    return names.some((name) => name.endsWith('.css')) ? 'extension.css' : 'assets/[name]-[hash][extname]'
}

// Modules that must never be bundled into an extension: they would be a second Vue / Inertia / SDK instance.
export const SHARED_RUNTIME = /[\\/]node_modules[\\/](vue|@vue[\\/][^\\/]+|@inertiajs[\\/][^\\/]+)[\\/]|[\\/]kit[\\/]sdk\.js$/

// Vitest runs Vite in mode 'test'.
function isTesting(mode) {
    return mode === 'test'
}

// Module ids of one output chunk (Rollup: `modules`; Rolldown also `moduleIds`).
function moduleIds(chunk) {
    return chunk.moduleIds ?? Object.keys(chunk.modules ?? {})
}

/** Bundled copies of the shared runtime, as [{ chunk, module }]. Exported for tests. */
export function bundledRuntime(bundle) {
    const found = []

    for (const output of Object.values(bundle ?? {})) {
        if (output?.type !== 'chunk') continue

        for (const id of moduleIds(output)) {
            if (SHARED_RUNTIME.test(id)) found.push({ chunk: output.fileName, module: id })
        }
    }

    return found
}

/** The config the preset contributes. Exported for tests. */
export function presetConfig({ entry = 'resources/js/extension.js', outDir = 'dist', mode = 'production', rolldown = true } = {}) {
    const dedupe = ['vue', '@inertiajs/vue3']

    if (isTesting(mode)) {
        return {
            resolve: { dedupe, alias: [{ find: /^larsadmin$/, replacement: SDK_PATH }] },
            // Built files and Composer dependencies (the panel's own *.test.js in vendor/) are not the author's tests.
            test: { environment: 'jsdom', exclude: TEST_EXCLUDE },
        }
    }

    const bundler = {
        external: (id) => EXTERNALS.includes(id),
        output: {
            chunkFileNames: 'chunks/[name]-[hash].js',
            // The library CSS must be dist/extension.css: Extension::styleFile() looks for that name. Under Vite 8 an
            // assetFileNames string would override lib.cssFileName, so CSS is named here explicitly.
            assetFileNames: assetFileName,
        },
    }

    return {
        base: './',
        resolve: { dedupe },
        build: {
            outDir,
            emptyOutDir: true,
            manifest: true,
            cssCodeSplit: false,
            lib: { entry, formats: ['es'], fileName: 'extension', cssFileName: 'extension' },
            // Vite 8 (Rolldown) reads rolldownOptions; older Vite reads rollupOptions.
            [rolldown ? 'rolldownOptions' : 'rollupOptions']: bundler,
        },
    }
}

export function larsadmin(options = {}) {
    return [
        {
            name: 'larsadmin-extension',
            config(_, env) {
                return presetConfig({ ...options, mode: env?.mode, rolldown: Boolean(this?.meta?.rolldownVersion) })
            },
        },
        {
            name: 'larsadmin-extension-guard',
            apply: 'build',
            generateBundle(_, bundle) {
                const found = bundledRuntime(bundle)

                if (found.length === 0) return

                const list = [...new Set(found.map(({ module }) => module))].slice(0, 5).join('\n  ')

                this.error(
                    '[larsadmin] The extension bundle contains its own copy of Vue, Inertia or the larsadmin SDK. ' +
                        'Import them only as "vue", "@inertiajs/vue3" and "larsadmin" (no deep paths, no aliases): the panel provides them.\n  ' +
                        list,
                )
            },
        },
    ]
}

export default larsadmin
