// Import-map target for the `vue` specifier (design 1.5 §5.1, ADR-030): re-exports the Vue chunk that app.js
// itself imports, so the panel and every extension share one Vue instance.
export * from 'vue'
