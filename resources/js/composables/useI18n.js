import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

// Bundled fallback: generated from lang/uk/ui.php by `npm run i18n` (tests/Feature/UiTranslationsTest.php guards drift).
import ukMessages from '../i18n/uk.json'

const CATEGORY_ORDER = ['zero', 'one', 'two', 'few', 'many', 'other']
const DEFAULT_LOCALE = 'uk'

// The shared prop is nested like the PHP file; flatten it to dotted keys.
export function flatten(object, prefix = '', into = {}) {
    for (const [key, value] of Object.entries(object ?? {})) {
        if (value !== null && typeof value === 'object') flatten(value, `${prefix}${key}.`, into)
        else into[prefix + key] = String(value)
    }

    return into
}

const fallback = ukMessages

// Laravel-style replacements: :name, :Name (ucfirst), :NAME (uppercase). Longer names first.
export function replace(message, params) {
    return Object.entries(params)
        .sort(([a], [b]) => b.length - a.length)
        .reduce((text, [name, value]) => {
            const plain = String(value)

            return text
                .replaceAll(`:${name.toUpperCase()}`, plain.toUpperCase())
                .replaceAll(`:${name.charAt(0).toUpperCase()}${name.slice(1)}`, plain.charAt(0).toUpperCase() + plain.slice(1))
                .replaceAll(`:${name}`, plain)
        }, message)
}

export function useI18n() {
    const page = usePage()

    const locale = computed(() => page.props.larsadmin?.locale ?? DEFAULT_LOCALE)
    const messages = computed(() => ({ ...fallback, ...flatten(page.props.larsadmin?.translations) }))

    function t(key, params = {}) {
        const message = messages.value[key]

        if (message === undefined) {
            if (import.meta.env?.DEV) console.warn(`[larsadmin] Missing translation: ${key}`)

            return key
        }

        return replace(message, params)
    }

    // Plural choice: Intl.PluralRules category -> index into the 'a|b|c' segments.
    function tc(key, count, params = {}) {
        const message = messages.value[key]

        if (message === undefined) return t(key, params)

        const segments = message.split('|')
        const rules = new Intl.PluralRules(locale.value)
        const supported = rules.resolvedOptions().pluralCategories
        const categories = CATEGORY_ORDER.filter((category) => supported.includes(category))
        const index = Math.min(Math.max(categories.indexOf(rules.select(count)), 0), segments.length - 1)

        return replace(segments[index], { count, ...params })
    }

    return { t, tc, locale }
}
