import { cpSync, existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { singleInstancePlugin } from './resources/js/runtime/singleInstance.js'

const root = fileURLToPath(new URL('.', import.meta.url))

// Extension API version (design 1.5 §5.5, §9): one source, Larsadmin::API_VERSION in src/Larsadmin.php, injected
// into the JS bundle as __LARSADMIN_API_VERSION__.
// No fallback (CRX-16): a constant the regex no longer matches must fail the build, not ship a stale version.
export function apiVersion() {
    const match = /const\s+(?:string\s+)?API_VERSION\s*=\s*'(\d+\.\d+)'/.exec(readFileSync(resolve(root, 'src/Larsadmin.php'), 'utf8'))

    if (!match) throw new Error('[larsadmin] Larsadmin::API_VERSION not found in src/Larsadmin.php.')

    return match[1]
}

const ENTRY_MODULE = /resources[\\/]js[\\/](app\.js|runtime[\\/](vue|inertia)\.js|sdk[\\/]index\.js)$/

// In watch mode, copy every rebuild into the sandbox app so changes show up without re-publishing.
const publishDir = resolve(process.env.LARSADMIN_PUBLISH_DIR ?? '../../sandbox/public/vendor/larsadmin')

const publishToSandbox = {
    name: 'larsadmin-publish',
    apply: (config, { mode }) => mode === 'development',
    closeBundle() {
        if (existsSync(resolve(publishDir, '..'))) {
            cpSync('dist', publishDir, { recursive: true })
        }
    },
}

// The bundle is prebuilt into dist/ and published to public/vendor/larsadmin,
// so output names are fixed and the host app never needs to run Vite.
//
// Entries (design 1.5 §6.1, ADR-030): app.js plus the import-map targets runtime/vue.js, runtime/inertia.js and
// runtime/larsadmin.js (the SDK). They share chunks/vue-*.js and chunks/inertia-*.js with app.js, so the panel and
// its extensions use one Vue and one Inertia instance; singleInstancePlugin fails the build otherwise.
export default defineConfig({
    plugins: [vue(), tailwindcss(), singleInstancePlugin(), publishToSandbox],
    base: '/vendor/larsadmin/',
    define: {
        __LARSADMIN_API_VERSION__: JSON.stringify(apiVersion()),
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: true,
        rolldownOptions: {
            input: {
                app: 'resources/js/app.js',
                'runtime/vue': 'resources/js/runtime/vue.js',
                'runtime/inertia': 'resources/js/runtime/inertia.js',
                'runtime/larsadmin': 'resources/js/sdk/index.js',
            },
            preserveEntrySignatures: 'exports-only',
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: (asset) => asset.names?.some((name) => name.endsWith('.css')) ? 'app.css' : 'assets/[name]-[hash][extname]',
                codeSplitting: {
                    groups: [
                        { name: 'vue', test: /[\\/]node_modules[\\/](vue|@vue)[\\/]/, priority: 30 },
                        { name: 'inertia', test: /[\\/]node_modules[\\/]@inertiajs[\\/]/, priority: 20 },
                        // Everything the entries load statically (except the entry modules themselves) goes into one
                        // shared chunk, so no chunk ever imports an entry: entries load with ?id=, and a plain import of
                        // app.js from a lazy chunk would evaluate it (and Vue's consumers) a second time.
                        { name: 'shared', tags: ['$initial'], test: (id) => !ENTRY_MODULE.test(id), priority: 10 },
                    ],
                },
            },
        },
    },
})
