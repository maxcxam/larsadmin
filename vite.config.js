import { cpSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

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
    plugins: [vue(), tailwindcss(), publishToSandbox],
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
            },
        },
    },
})
