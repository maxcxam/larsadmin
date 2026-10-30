import { nextTick, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { isExpired } from '../files'

// Index payloads carry `count` (and no tokens); detail/form payloads carry the full file list (§4.1).
export function isIndexPayload(field) {
    return field?.context === 'index' || field?.count !== undefined
}

/**
 * Click handling for links to stored files. Signed / temporary URLs expire (files.url_ttl): when they have, the
 * click reloads the page props first (fresh signatures) and then follows the refreshed URL (§7.2).
 * New-tab links open a blank tab synchronously inside the click (user activation), and the tab is pointed at the
 * fresh URL after the reload, so popup blockers do not eat it (CRF-13). If the tab could not be opened, the fresh
 * URL opens in the current tab instead. Clicks never bubble, because index rows are clickable.
 */
export function useFileLinks(getField) {
    const status = ref('')
    let reloading = false

    function follow(event, index, kind = 'url') {
        event.stopPropagation()

        if (!isExpired(getField())) return

        event.preventDefault()

        if (reloading) return

        const anchor = event.currentTarget
        const newTab = anchor?.getAttribute('target') === '_blank'
        const download = anchor?.getAttribute('download')
        const tab = newTab ? openBlankTab() : null
        let opened = false

        reloading = true
        status.value = ''

        router.reload({
            preserveScroll: true,
            onSuccess: () =>
                nextTick(() => {
                    const fresh = getField()?.files?.[index]?.[kind]

                    if (!fresh) return

                    opened = true

                    if (tab) tab.location.href = fresh
                    else if (newTab) window.location.assign(fresh)
                    else openDownload(fresh, download)
                }),
            onFinish: () => {
                reloading = false
                // Reload failed / was cancelled, or the file is gone: do not leave an empty tab behind.
                nextTick(() => !opened && tab && !tab.closed && tab.close())
            },
        })

        // Assigned after clearing so a repeated message is announced again by the live region.
        nextTick(() => (status.value = 'files_links_expired'))
    }

    return { follow, status }
}

function openBlankTab() {
    try {
        const tab = window.open('', '_blank')

        // Same protection as rel="noopener": the file page cannot script this window.
        if (tab) tab.opener = null

        return tab
    } catch {
        return null
    }
}

function openDownload(url, download) {
    const link = document.createElement('a')

    link.href = url
    link.rel = 'noopener noreferrer'
    if (download !== null && download !== undefined) link.setAttribute('download', download)

    document.body.appendChild(link)
    link.click()
    link.remove()
}
