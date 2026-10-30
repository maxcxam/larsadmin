import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// Unit tests cover the framework-free composables and helpers only; the Vue build config stays in vite.config.js.
export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.js'],
    },
})
