import { useI18n } from './useI18n'

const EMPTY = '—'
const formatters = new Map()

function formatter(locale, options) {
    const key = `${locale}|${JSON.stringify(options)}`

    if (!formatters.has(key)) {
        try {
            formatters.set(key, new Intl.NumberFormat(locale, options))
        } catch {
            formatters.set(key, new Intl.NumberFormat(locale))
        }
    }

    return formatters.get(key)
}

// Formats a metric number. `format` is the server's Intl option bag
// ({ style: 'decimal'|'currency'|'percent', currency, notation, maximumFractionDigits, ... }).
// null / undefined / NaN -> "—".
export function formatNumber(value, { format = {}, prefix = null, suffix = null } = {}, locale = 'uk') {
    if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) return EMPTY

    const options = Object.fromEntries(Object.entries(format ?? {}).filter(([, v]) => v !== null && v !== undefined))

    if (options.style === 'currency' && !options.currency) delete options.style

    return `${prefix ?? ''}${formatter(locale, options).format(Number(value))}${suffix ?? ''}`
}

export function useNumberFormat() {
    const { locale } = useI18n()

    return {
        formatNumber: (value, spec) => formatNumber(value, spec, locale.value),
        locale,
    }
}
