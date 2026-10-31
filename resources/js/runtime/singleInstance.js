// Build assertion (design 1.5 §6.1, ADR-030): the panel bundle must contain exactly ONE Vue and ONE Inertia, shared by
// app.js and the import-map entries runtime/*.js. Used by vite.config.js (generateBundle) and unit-tested.
//
// Checks, over the Rollup/Rolldown output bundle:
//   1. every Vue module (vue, @vue/*) lives in one chunk, and so does every Inertia module (@inertiajs/*);
//   2. app.js and runtime/vue.js import that same Vue chunk; app.js and runtime/inertia.js the same Inertia chunk;
//   3. no chunk imports an entry chunk: entries are loaded with a ?id= query, so a static import of app.js or
//      runtime/*.js from another chunk would evaluate it a second time (a second Vue instance);
//   4. the panel state the SDK relies on (component registry, toast stack, theme, page accessor) lives in one
//      non-entry chunk imported by both app.js and runtime/larsadmin.js (CRX-06): otherwise extensions would get a
//      second registry or toast stack and their toasts would never show.

export const VUE_MODULE = /[\\/]node_modules[\\/](vue|@vue[\\/][^\\/]+)[\\/]/
export const INERTIA_MODULE = /[\\/]node_modules[\\/]@inertiajs[\\/]/

export const SHARED_STATE = Object.freeze([
    ['registry.js', /[\\/]resources[\\/]js[\\/]extensions[\\/]registry\.js$/],
    ['useToasts.js', /[\\/]resources[\\/]js[\\/]composables[\\/]useToasts\.js$/],
    ['useTheme.js', /[\\/]resources[\\/]js[\\/]composables[\\/]useTheme\.js$/],
    ['page.js', /[\\/]resources[\\/]js[\\/]sdk[\\/]page\.js$/],
])

function moduleIds(chunk) {
    return chunk.moduleIds ?? Object.keys(chunk.modules ?? {})
}

function chunksContaining(chunks, pattern) {
    return chunks.filter((chunk) => moduleIds(chunk).some((id) => pattern.test(id))).map((chunk) => chunk.fileName)
}

export function singleInstanceErrors(bundle, { app = 'app.js', vue = 'runtime/vue.js', inertia = 'runtime/inertia.js', sdk = 'runtime/larsadmin.js', sharedState = SHARED_STATE } = {}) {
    const chunks = Object.values(bundle).filter((output) => output.type === 'chunk')
    const entries = new Set(chunks.filter((chunk) => chunk.isEntry).map((chunk) => chunk.fileName))
    const byName = Object.fromEntries(chunks.map((chunk) => [chunk.fileName, chunk]))
    const errors = []

    for (const [label, pattern, entry] of [['Vue', VUE_MODULE, vue], ['Inertia', INERTIA_MODULE, inertia]]) {
        const holders = chunksContaining(chunks, pattern)

        if (holders.length !== 1) {
            errors.push(`${label} must be in exactly one chunk, found ${holders.length}: ${holders.join(', ') || 'none'}.`)
            continue
        }

        const [shared] = holders

        if (entries.has(shared)) errors.push(`${label} is bundled into the entry ${shared}; it must be a separate shared chunk.`)

        for (const importer of [app, entry]) {
            if (!byName[importer]) errors.push(`Entry ${importer} is missing from the bundle.`)
            else if (!byName[importer].imports?.includes(shared)) errors.push(`${importer} does not import the shared ${label} chunk ${shared}.`)
        }
    }

    for (const [label, pattern] of sharedState) {
        const holders = chunksContaining(chunks, pattern)

        if (holders.length !== 1) {
            errors.push(`Panel state module ${label} must be in exactly one chunk, found ${holders.length}: ${holders.join(', ') || 'none'}.`)
            continue
        }

        const [shared] = holders

        if (entries.has(shared)) {
            errors.push(`Panel state module ${label} is bundled into the entry ${shared}; it must be in a shared chunk.`)
            continue
        }

        for (const importer of [app, sdk]) {
            if (!byName[importer]) errors.push(`Entry ${importer} is missing from the bundle.`)
            else if (!byName[importer].imports?.includes(shared)) errors.push(`${importer} does not import ${shared}, which holds the panel state module ${label}.`)
        }
    }

    for (const chunk of chunks) {
        for (const imported of chunk.imports ?? []) {
            if (entries.has(imported)) errors.push(`${chunk.fileName} imports the entry ${imported}; entries must only import shared chunks.`)
        }
    }

    return errors
}

// Vite plugin: fails the production build when the assertion does not hold.
export function singleInstancePlugin(options) {
    return {
        name: 'larsadmin-single-instance',
        apply: 'build',
        generateBundle(_, bundle) {
            const errors = singleInstanceErrors(bundle, options)

            if (errors.length) this.error(`[larsadmin] One Vue / one Inertia check failed:\n- ${errors.join('\n- ')}`)
        },
    }
}
