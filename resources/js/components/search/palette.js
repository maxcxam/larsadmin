// Framework-free helpers of the global search palette (Ctrl/⌘+K). Covered by palette.test.js.

const APPLE_PLATFORM = /Mac|iPhone|iPad|iPod/i

// Ctrl/⌘+K without Alt/Shift. `code` keeps it working on non-Latin layouts (Ukrainian: key === 'л').
export function isSearchHotkey(event) {
    if (!event || event.isComposing) return false
    if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) return false

    return event.code === 'KeyK' || String(event.key ?? '').toLowerCase() === 'k'
}

export function isApplePlatform(platform) {
    return APPLE_PLATFORM.test(String(platform ?? ''))
}

// The hotkey as shown in the trigger: "⌘K" on Apple platforms, "Ctrl K" elsewhere.
export function hotkeyLabel(platform) {
    return isApplePlatform(platform) ? '⌘K' : 'Ctrl K'
}

// The browser's platform string (User-Agent Client Hints first); '' outside a browser.
export function currentPlatform() {
    if (typeof navigator === 'undefined') return ''

    return navigator.userAgentData?.platform || navigator.platform || ''
}

const DIGITS_ONLY = /^[0-9]+$/

// Mirrors the server rule: a trimmed term of at least `minLength` characters (code points, like mb_strlen),
// or a digits-only term of any length (ID lookups).
export function shouldSearch(term, minLength = 2) {
    const trimmed = String(term ?? '').trim()

    if (trimmed === '') return false
    if (DIGITS_ONLY.test(trimmed)) return true

    return [...trimmed].length >= Math.max(1, Number(minLength) || 1)
}

// `base?search=<term>` (or `&` when `base` already has a query string); the term is trimmed and encoded.
export function searchUrl(base, term) {
    const [path, hash = ''] = String(base).split('#')
    const [pathname, existing = ''] = path.split('?')
    const params = new URLSearchParams(existing)

    params.set('search', String(term ?? '').trim())

    return `${pathname}?${params.toString()}${hash ? `#${hash}` : ''}`
}

const BACKSLASH = String.fromCharCode(92)

// Same-origin paths only (SECS-5): "/…" but not protocol-relative "//…" or "/\…", and no control characters
// or whitespace (browsers strip tabs/newlines, so "/<TAB>/evil" would become "//evil").
export function isSafePath(value) {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/' + BACKSLASH) && ![...value].some((char) => char.charCodeAt(0) <= 32 || char.charCodeAt(0) === 127)
}

const isUrl = isSafePath

// The response groups as one flat list of navigable options: each group's results, then its
// "View all" row (only when the server sent an `indexUrl`). Results without a URL and groups
// without results are skipped. `index` is the position in the flat list.
export function flattenOptions(groups) {
    const options = []

    if (!Array.isArray(groups)) return options

    groups.forEach((group, groupIndex) => {
        const results = (Array.isArray(group?.results) ? group.results : []).filter((result) => isUrl(result?.url))

        if (results.length === 0) return

        results.forEach((result, position) => {
            const id = result.id ?? position
            const title = result.title === null || result.title === undefined ? '' : String(result.title)

            options.push({
                key: `${groupIndex}:r:${id}:${position}`,
                kind: 'result',
                groupIndex,
                index: options.length,
                id,
                title: title.trim() === '' ? `#${id}` : title,
                subtitle: result.subtitle === null || result.subtitle === undefined || result.subtitle === '' ? null : String(result.subtitle),
                url: result.url,
            })
        })

        if (isUrl(group.indexUrl)) {
            options.push({
                key: `${groupIndex}:more`,
                kind: 'more',
                groupIndex,
                index: options.length,
                label: String(group.label ?? group.entity ?? ''),
                url: group.indexUrl,
            })
        }
    })

    return options
}

// Groups ready to render: { key, label, options } for every group that has options, plus the flat list.
export function buildSections(groups) {
    const options = flattenOptions(groups)
    const sections = []

    for (const option of options) {
        let section = sections[sections.length - 1]

        if (!section || section.groupIndex !== option.groupIndex) {
            const group = groups[option.groupIndex]
            section = { key: `${option.groupIndex}:${group.entity ?? ''}`, groupIndex: option.groupIndex, label: String(group.label ?? group.entity ?? ''), options: [] }
            sections.push(section)
        }

        section.options.push(option)
    }

    return { sections, options, results: options.filter((option) => option.kind === 'result').length }
}

const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

// Splits `text` into [{ text, match }] segments around case-insensitive occurrences of the trimmed term,
// so matches can be wrapped in <mark> without rendering raw HTML.
export function highlight(text, term) {
    const value = text === null || text === undefined ? '' : String(text)
    const needle = String(term ?? '').trim()

    if (value === '') return []
    if (needle === '') return [{ text: value, match: false }]

    const segments = []
    const pattern = new RegExp(escapeRegExp(needle), 'giu')
    let last = 0

    for (const found of value.matchAll(pattern)) {
        if (found.index > last) segments.push({ text: value.slice(last, found.index), match: false })
        segments.push({ text: found[0], match: true })
        last = found.index + found[0].length
    }

    if (last < value.length) segments.push({ text: value.slice(last), match: false })

    return segments
}

// Cyclic move through `count` options; -1 when there is nothing to select. From "none" (-1),
// down selects the first option and up the last.
export function nextIndex(current, count, delta) {
    if (!count || count < 1) return -1
    if (current < 0 || current >= count) return delta < 0 ? count - 1 : 0

    return (((current + delta) % count) + count) % count
}

// The modifier shown in key hints: "⌘" on Apple platforms, "Ctrl" elsewhere.
export function modifierLabel(platform) {
    return isApplePlatform(platform) ? '⌘' : 'Ctrl'
}

// What Enter does (CRS-04): open the active option only when the results on screen answer the current
// input; otherwise (typed within the debounce window) search now. 'none' when the term is not searchable.
export function enterAction({ term, searched, hasActive, searchable }) {
    const current = String(term ?? '').trim()

    if (hasActive && current === String(searched ?? '')) return 'choose'

    return searchable ? 'search' : 'none'
}

// Ctrl/⌘ held while choosing an option: open it in a new tab.
export function wantsNewTab(event) {
    return !!(event?.ctrlKey || event?.metaKey)
}
