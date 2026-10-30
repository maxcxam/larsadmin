import '../css/app.css'
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import AppLayout from './Layouts/AppLayout.vue'

const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
const panelName = document.querySelector('meta[name="larsadmin-name"]')?.content ?? 'Admin'

createInertiaApp({
    title: (title) => (title ? `${title} – ${panelName}` : panelName),
    resolve: (name) => {
        const page = pages[`./Pages/${name}.vue`]

        if (!page) {
            throw new Error(`Larsadmin page not found: ${name}`)
        }

        // Only default when the page declares nothing: an explicit `layout: null` (e.g. Login) means "no layout".
        if (page.default.layout === undefined) page.default.layout = AppLayout

        return page
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
    progress: {
        color: '#0969da',
    },
})
