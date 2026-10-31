// Import-map target for the `@inertiajs/vue3` specifier (design 1.5 §5.1, ADR-030): re-exports the Inertia chunk
// that app.js itself imports, so usePage() in an extension sees the panel's page.
export * from '@inertiajs/vue3'
