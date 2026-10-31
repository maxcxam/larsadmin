import { getCurrentInstance, inject } from 'vue'
import { usePage } from '@inertiajs/vue3'

// The Inertia page the SDK composables read. Tests of extensions (kit/testing.js withPanel) provide a fake page
// under this key, so components can be mounted without an Inertia app. Symbol.for: kit/testing.js does not
// import the SDK.
export const PANEL_PAGE = Symbol.for('larsadmin.page')

export function usePanelPage() {
    const provided = getCurrentInstance() ? inject(PANEL_PAGE, null) : null

    return provided ?? usePage()
}
