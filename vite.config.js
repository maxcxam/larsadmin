import { cpSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { noEntryImportsPlugin } from './resources/js/build/noEntryImports.js'

const ENTRY_MODULE = /resources[\\/]js[\\/]app\.js$/

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
export default defineConfig({
    plugins: [vue(), tailwindcss(), noEntryImportsPlugin(), publishToSandbox],
    base: '/vendor/larsadmin/',
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: 'resources/js/app.js',
            output: {
                entryFileNames: 'app.js',
                chunkFileNames: 'chunks/[name]-[hash].js',
                assetFileNames: (asset) => asset.names?.some((name) => name.endsWith('.css')) ? 'app.css' : 'assets/[name]-[hash][extname]',
                // Everything app.js loads statically (except app.js itself) goes into one shared chunk, so the lazy
                // chunks import chunks/shared-*.js instead of ../app.js. The page loads app.js with ?id=, and a plain
                // import of app.js from a lazy chunk would evaluate the whole app a second time.
                codeSplitting: {
                    groups: [
                        { name: 'shared', tags: ['$initial'], test: (id) => !ENTRY_MODULE.test(id) },
                    ],
                },
            },
        },
    },
})
