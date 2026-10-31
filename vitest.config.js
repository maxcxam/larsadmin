import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { apiVersion } from './vite.config.js'

// Unit tests run in `node`; component tests opt into jsdom per file (`// @vitest-environment jsdom`).
// The Vue build config stays in vite.config.js.
export default defineConfig({
    plugins: [vue()],
    define: {
        __LARSADMIN_API_VERSION__: JSON.stringify(apiVersion()),
    },
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.js', 'kit/**/*.test.js'],
    },
})
