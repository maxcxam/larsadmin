import { watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Triggers a browser download once when a page response carries `{ url, filename }`
// (shared prop `larsadmin.download`).
export function useDownload() {
    const page = usePage()
    let lastUrl = null

    watch(
        () => page.props.larsadmin?.download,
        (download) => {
            if (!download?.url || download.url === lastUrl) return

            lastUrl = download.url
            const link = document.createElement('a')
            link.href = download.url
            link.download = download.filename ?? ''
            link.rel = 'noopener'
            link.style.display = 'none'
            document.body.appendChild(link)
            link.click()
            link.remove()
        },
        { immediate: true },
    )
}
