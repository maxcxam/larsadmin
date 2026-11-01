import '../css/app.css'
import { createApp, h } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import AppLayout from './Layouts/AppLayout.vue'
import { registry } from './extensions/registry'
import { ensureBuiltins } from './extensions/builtins'
import { createLoader } from './extensions/loader'
import { useToasts } from './composables/useToasts'
import { flatten, replace } from './composables/useI18n'
import ukMessages from './i18n/uk.json'
import { syncDocumentLocale } from './runtime/documentLocale'
import { handleNetworkErrors } from './runtime/networkErrors'

// `import: 'default'`: the components themselves, not module namespace objects, which would need a Rolldown runtime
// helper chunk and one more sequential request before boot (CRX-05).
const pages = import.meta.glob('./Pages/**/*.vue', { eager: true, import: 'default' })
const panelName = document.querySelector('meta[name="larsadmin-name"]')?.content ?? 'Admin'

ensureBuiltins()

// The initial Inertia page (inertia-laravel v3 renders it as JSON in a script tag): the extension list must be known
// before the first mount (design 1.5 §5.3).
function readInitialPage() {
    try {
        const json = document.querySelector('script[data-page="app"][type="application/json"]')?.textContent
            ?? document.getElementById('app')?.dataset.page

        return json ? JSON.parse(json) : null
    } catch {
        return null
    }
}

let currentPage = readInitialPage()

const larsadmin = () => currentPage?.props?.larsadmin ?? {}

function translate(key, params) {
    const message = { ...ukMessages, ...flatten(larsadmin().translations) }[key] ?? key

    return replace(message, params)
}

// Extensions: console + placeholder always; a toast only when app.debug is on (Q-71).
const loader = createLoader({
    registry,
    isDebug: () => larsadmin().debug === true,
    notify(name, reason, details) {
        const message = reason === 'incompatible'
            ? translate('extension_incompatible', { name, requires: details.requires ?? '?', version: details.version ?? '?' })
            : translate('extension_failed', { name })

        useToasts().push({ type: 'error', message, timeout: 8000 })
    },
})

// The first sign-in (login -> dashboard) is an Inertia visit, not a page load: pick up extensions listed later.
router.on('navigate', (event) => {
    currentPage = event.detail.page
    // <html lang> follows the panel locale without a reload (the in-panel language switch is an Inertia visit).
    syncDocumentLocale(currentPage)
    loader.load(larsadmin().extensions)
})

// The in-panel language switch posts and is redirected back to the same URL: that visit ends in `success` without a
// `navigate` event, so <html lang> is synced there too.
router.on('success', (event) => syncDocumentLocale(event.detail.page))

// A save (or any visit) that gets no response at all: the error toast instead of an uncaught "Network error".
handleNetworkErrors(router, {
    notify: () => useToasts().push({ type: 'error', message: translate('network_error'), timeout: 8000 }),
})

function boot() {
    createInertiaApp({
        title: (title) => (title ? `${title} – ${panelName}` : panelName),
        resolve: (name) => {
            const page = pages[`./Pages/${name}.vue`]

            if (!page) {
                throw new Error(`Larsadmin page not found: ${name}`)
            }

            // Only default when the page declares nothing: an explicit `layout: null` (e.g. Login) means "no layout".
            if (page.layout === undefined) page.layout = AppLayout

            return page
        },
        setup({ el, App, props, plugin }) {
            const app = createApp({ render: () => h(App, props) }).use(plugin)

            loader.attach(app)
            app.mount(el)
        },
        progress: {
            color: '#0969da',
        },
    })
}

// Without extensions the panel boots exactly as in 1.4. With extensions it waits for them (at most 5 s).
const extensions = larsadmin().extensions

if (Array.isArray(extensions) && extensions.length > 0) loader.load(extensions).then(boot)
else boot()
