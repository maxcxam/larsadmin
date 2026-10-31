// The public `larsadmin` module (design 1.5 §5.5, ADR-034): everything an extension may import. Exports listed here
// are a SemVer contract for 1.x; minors may only add. The panel build serves this file as runtime/larsadmin.js
// (import map); kit/sdk.js is the same module built for authors' tests.
import { computed, ref } from 'vue'
import { API_VERSION } from '../extensions/version'
import { defineExtension } from '../extensions/define'
import { useI18n } from '../composables/useI18n'
import { buildUrl, withParams } from '../composables/useLarsadmin'
import { useJsonRequest } from '../composables/useJsonRequest'
import { useJsonResource } from '../composables/useJsonResource'
import { useCardData } from '../composables/useCardData'
import { useToasts as usePanelToasts } from '../composables/useToasts'
import { useTheme as usePanelTheme } from '../composables/useTheme'
import { formatNumber } from '../composables/useNumberFormat'
import { usePanelPage } from './page'
import Icon from '../components/Icon.vue'
import CardShell from '../components/cards/CardShell.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'

export const apiVersion = API_VERSION

export { defineExtension, useI18n, useJsonRequest, useJsonResource, useCardData, buildUrl, withParams, Icon, CardShell, ConfirmDialog }

// { url(...segments), entityUrl(key, ...segments) } under the panel path; segments are encoded, `.`/`..` refused.
export function useLarsadmin() {
    const page = usePanelPage()

    const url = (...segments) => buildUrl(page.props?.larsadmin?.path ?? '/', segments)
    const entityUrl = (uriKey, ...segments) => url('entities', uriKey, ...segments)

    return { url, entityUrl }
}

// The panel's toast stack. Links in extension toasts are not supported (design §5.5).
export function useToasts() {
    const toasts = usePanelToasts()

    return {
        push: ({ message, type, title = null, timeout } = {}) => toasts.push({ message, type, title, ...(timeout === undefined ? {} : { timeout }) }),
        dismiss: (id) => toasts.dismiss(id),
    }
}

const prefersDark = ref(false)

if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    const query = window.matchMedia('(prefers-color-scheme: dark)')

    prefersDark.value = query.matches
    query.addEventListener?.('change', (event) => (prefersDark.value = event.matches))
}

const resolvedTheme = computed(() => {
    const { theme } = usePanelTheme()

    if (theme.value === 'light' || theme.value === 'dark') return theme.value

    return prefersDark.value ? 'dark' : 'light'
})

// { resolved }: 'light' | 'dark' as currently shown (the user's choice or the OS setting), e.g. for charts.
export function useTheme() {
    return { resolved: resolvedTheme }
}

// { format(value, options) }: Intl.NumberFormat options (plus `prefix` / `suffix`) in the panel's locale;
// null / NaN -> "—".
export function useNumberFormat() {
    const { locale } = useI18n()

    return {
        format: (value, { prefix = null, suffix = null, ...options } = {}) => formatNumber(value, { format: options, prefix, suffix }, locale.value),
    }
}
