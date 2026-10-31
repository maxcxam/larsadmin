// Build assertion (1.4.1): no chunk may import the entry chunk app.js. The page loads the entry as app.js?id=<hash>,
// so a lazy chunk importing "../app.js" is a different module URL and would evaluate the whole app a second time
// (a second Inertia app, duplicate composable singletons, polling and listeners). Used by vite.config.js.

export function entryImportErrors(bundle) {
    const chunks = Object.values(bundle).filter((output) => output.type === 'chunk')
    const entries = new Set(chunks.filter((chunk) => chunk.isEntry).map((chunk) => chunk.fileName))
    const errors = []

    for (const chunk of chunks) {
        for (const imported of [...(chunk.imports ?? []), ...(chunk.dynamicImports ?? [])]) {
            if (entries.has(imported)) errors.push(`${chunk.fileName} imports the entry ${imported}; chunks must only import shared chunks.`)
        }
    }

    return errors
}

// Vite plugin: fails the production build when a chunk imports an entry.
export function noEntryImportsPlugin() {
    return {
        name: 'larsadmin-no-entry-imports',
        apply: 'build',
        generateBundle(_, bundle) {
            const errors = entryImportErrors(bundle)

            if (errors.length) this.error(`[larsadmin] Entry import check failed:\n- ${errors.join('\n- ')}`)
        },
    }
}
