import { ref } from 'vue'

const STORAGE_KEY = 'larsadmin.theme'
const THEMES = ['light', 'dark', 'system']

function readStored() {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY)

        return THEMES.includes(value) ? value : 'system'
    } catch {
        return 'system'
    }
}

// Module-level state: shared by the layout and the login page.
const theme = ref(typeof window === 'undefined' ? 'system' : readStored())

function apply(value) {
    const root = document.documentElement

    if (value === 'system') {
        root.removeAttribute('data-theme')
    } else {
        root.setAttribute('data-theme', value)
    }
}

function setTheme(value) {
    if (!THEMES.includes(value)) return

    theme.value = value
    apply(value)

    try {
        if (value === 'system') {
            window.localStorage.removeItem(STORAGE_KEY)
        } else {
            window.localStorage.setItem(STORAGE_KEY, value)
        }
    } catch {
        // Storage unavailable (private mode): the choice lasts for this page only.
    }
}

export function useTheme() {
    return { theme, setTheme }
}
