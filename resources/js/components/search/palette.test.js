import { describe, expect, it } from 'vitest'
import {
    buildSections,
    enterAction,
    flattenOptions,
    isSafePath,
    modifierLabel,
    highlight,
    hotkeyLabel,
    isApplePlatform,
    isSearchHotkey,
    nextIndex,
    searchUrl,
    shouldSearch,
    wantsNewTab,
} from './palette.js'

const key = (overrides = {}) => ({ key: 'k', code: 'KeyK', ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, isComposing: false, ...overrides })

describe('isSearchHotkey', () => {
    it('accepts Ctrl+K and ⌘+K', () => {
        expect(isSearchHotkey(key({ ctrlKey: true }))).toBe(true)
        expect(isSearchHotkey(key({ metaKey: true }))).toBe(true)
    })

    it('matches the physical key on the Ukrainian layout', () => {
        expect(isSearchHotkey(key({ ctrlKey: true, key: 'л' }))).toBe(true)
        expect(isSearchHotkey(key({ metaKey: true, key: 'Л' }))).toBe(true)
    })

    it('falls back to the key when the code is unavailable', () => {
        expect(isSearchHotkey(key({ ctrlKey: true, code: '', key: 'K' }))).toBe(true)
        expect(isSearchHotkey(key({ ctrlKey: true, code: undefined, key: 'k' }))).toBe(true)
    })

    it('rejects K without a modifier, other keys and extra modifiers', () => {
        expect(isSearchHotkey(key())).toBe(false)
        expect(isSearchHotkey(key({ ctrlKey: true, code: 'KeyJ', key: 'j' }))).toBe(false)
        expect(isSearchHotkey(key({ ctrlKey: true, altKey: true }))).toBe(false)
        expect(isSearchHotkey(key({ ctrlKey: true, shiftKey: true }))).toBe(false)
    })

    it('ignores IME composition and missing events', () => {
        expect(isSearchHotkey(key({ ctrlKey: true, isComposing: true }))).toBe(false)
        expect(isSearchHotkey(null)).toBe(false)
        expect(isSearchHotkey(undefined)).toBe(false)
    })
})

describe('hotkeyLabel', () => {
    it.each(['MacIntel', 'macOS', 'iPhone', 'iPad'])('shows ⌘K on %s', (platform) => {
        expect(isApplePlatform(platform)).toBe(true)
        expect(hotkeyLabel(platform)).toBe('⌘K')
    })

    it.each(['Win32', 'Windows', 'Linux x86_64', 'Android', '', null, undefined])('shows Ctrl K on %s', (platform) => {
        expect(isApplePlatform(platform)).toBe(false)
        expect(hotkeyLabel(platform)).toBe('Ctrl K')
    })
})

describe('shouldSearch', () => {
    it('requires the minimum length after trimming', () => {
        expect(shouldSearch('', 2)).toBe(false)
        expect(shouldSearch('   ', 2)).toBe(false)
        expect(shouldSearch('a', 2)).toBe(false)
        expect(shouldSearch(' a ', 2)).toBe(false)
        expect(shouldSearch('ab', 2)).toBe(true)
        expect(shouldSearch('abc', 3)).toBe(true)
        expect(shouldSearch('ab', 3)).toBe(false)
    })

    it('searches digits-only terms from one character', () => {
        expect(shouldSearch('7', 2)).toBe(true)
        expect(shouldSearch(' 42 ', 5)).toBe(true)
        expect(shouldSearch('7a', 3)).toBe(false)
        expect(shouldSearch('٧', 2)).toBe(false)
    })

    it('counts unicode code points, not UTF-16 units', () => {
        expect(shouldSearch('я', 2)).toBe(false)
        expect(shouldSearch('кв', 2)).toBe(true)
        expect(shouldSearch('😀', 2)).toBe(false)
        expect(shouldSearch('😀😀', 2)).toBe(true)
    })

    it('treats a missing or invalid minimum as 1 and tolerates null terms', () => {
        expect(shouldSearch('a', 0)).toBe(true)
        expect(shouldSearch('a', undefined)).toBe(false)
        expect(shouldSearch('a', 'x')).toBe(true)
        expect(shouldSearch(null, 2)).toBe(false)
    })
})

describe('searchUrl', () => {
    it('appends the trimmed, encoded term', () => {
        expect(searchUrl('/admin/api/search', ' київ ')).toBe('/admin/api/search?search=%D0%BA%D0%B8%D1%97%D0%B2')
        expect(searchUrl('/admin/api/search', 'a&b=c #1%')).toBe('/admin/api/search?search=a%26b%3Dc+%231%25')
    })

    it('keeps an existing query string and replaces an existing search param', () => {
        expect(searchUrl('/api/search?locale=uk', 'x')).toBe('/api/search?locale=uk&search=x')
        expect(searchUrl('/api/search?search=old', 'new')).toBe('/api/search?search=new')
    })
})

const response = () => [
    {
        entity: 'posts',
        label: 'Дописи',
        results: [
            { id: 12, title: 'Київ восени', subtitle: 'Подорожі', url: '/admin/entities/posts/12' },
            { id: 13, title: '', subtitle: null, url: '/admin/entities/posts/13' },
        ],
        more: true,
        indexUrl: '/admin/entities/posts?search=x',
    },
    { entity: 'empty', label: 'Порожньо', results: [], more: false, indexUrl: '/admin/entities/empty?search=x' },
    {
        entity: 'users',
        label: 'Користувачі',
        results: [
            { id: 'u-1', title: 'Олена', subtitle: 'olena@example.com', url: '/admin/entities/users/u-1' },
            { id: 2, title: 'No URL', url: null },
        ],
        more: false,
        indexUrl: null,
    },
]

describe('flattenOptions', () => {
    it('lists results and "View all" rows with flat indices', () => {
        const options = flattenOptions(response())

        expect(options.map((option) => [option.index, option.kind, option.groupIndex, option.url])).toEqual([
            [0, 'result', 0, '/admin/entities/posts/12'],
            [1, 'result', 0, '/admin/entities/posts/13'],
            [2, 'more', 0, '/admin/entities/posts?search=x'],
            [3, 'result', 2, '/admin/entities/users/u-1'],
        ])
    })

    it('normalizes titles and subtitles', () => {
        const [first, second, more, third] = flattenOptions(response())

        expect(first).toMatchObject({ id: 12, title: 'Київ восени', subtitle: 'Подорожі' })
        expect(second).toMatchObject({ id: 13, title: '#13', subtitle: null })
        expect(more).toMatchObject({ label: 'Дописи' })
        expect(third).toMatchObject({ id: 'u-1', title: 'Олена', subtitle: 'olena@example.com' })
    })

    it('gives every option a unique key', () => {
        const keys = flattenOptions(response()).map((option) => option.key)

        expect(new Set(keys).size).toBe(keys.length)
    })

    it('skips empty groups, rows without a URL and malformed input', () => {
        expect(flattenOptions([])).toEqual([])
        expect(flattenOptions(null)).toEqual([])
        expect(flattenOptions(undefined)).toEqual([])
        expect(flattenOptions([{ entity: 'x', results: null, indexUrl: '/x' }])).toEqual([])
        expect(flattenOptions([null])).toEqual([])
        expect(flattenOptions([{ entity: 'x', results: [{ id: 1, title: 'a' }] }])).toEqual([])
    })
})

describe('buildSections', () => {
    it('groups the flat options and counts results', () => {
        const { sections, options, results } = buildSections(response())

        expect(sections.map((section) => [section.label, section.options.map((option) => option.index)])).toEqual([
            ['Дописи', [0, 1, 2]],
            ['Користувачі', [3]],
        ])
        expect(options).toHaveLength(4)
        expect(results).toBe(3)
    })

    it('is empty for no groups', () => {
        expect(buildSections([])).toEqual({ sections: [], options: [], results: 0 })
    })
})

describe('highlight', () => {
    const marked = (segments) => segments.map(({ text, match }) => (match ? `[${text}]` : text)).join('')

    it('marks case-insensitive matches, keeping the original case', () => {
        expect(highlight('Київ восени', 'київ')).toEqual([
            { text: 'Київ', match: true },
            { text: ' восени', match: false },
        ])
        expect(marked(highlight('Hello WORLD', 'world'))).toBe('Hello [WORLD]')
    })

    it('marks every occurrence', () => {
        expect(marked(highlight('abcABCabc', 'abc'))).toBe('[abc][ABC][abc]')
        expect(marked(highlight('a-b a-b', 'a-b'))).toBe('[a-b] [a-b]')
    })

    it('treats regex characters literally', () => {
        expect(marked(highlight('Cost (USD) $5.00 a+b', '(usd)'))).toBe('Cost [(USD)] $5.00 a+b')
        expect(marked(highlight('$5.00 and 5x00', '5.00'))).toBe('$[5.00] and 5x00')
        expect(marked(highlight('a+b [x]', '[x]'))).toBe('a+b [[x]]')
        expect(marked(highlight('a.*b', '.*'))).toBe('a[.*]b')
    })

    it('trims the term and returns the whole text without a match', () => {
        expect(highlight('Київ', '  ')).toEqual([{ text: 'Київ', match: false }])
        expect(highlight('Київ', '')).toEqual([{ text: 'Київ', match: false }])
        expect(highlight('Київ', null)).toEqual([{ text: 'Київ', match: false }])
        expect(highlight('Київ', 'Львів')).toEqual([{ text: 'Київ', match: false }])
        expect(marked(highlight('Київ', ' ки '))).toBe('[Ки]їв')
    })

    it('handles empty and non-string text', () => {
        expect(highlight('', 'a')).toEqual([])
        expect(highlight(null, 'a')).toEqual([])
        expect(marked(highlight(1234, '23'))).toBe('1[23]4')
    })

    it('keeps the full text when concatenated', () => {
        const text = 'Ще один Київ, київська область'

        expect(highlight(text, 'КИЇВ').map((segment) => segment.text).join('')).toBe(text)
    })
})

describe('nextIndex', () => {
    it('cycles forward and backward', () => {
        expect(nextIndex(0, 3, 1)).toBe(1)
        expect(nextIndex(2, 3, 1)).toBe(0)
        expect(nextIndex(0, 3, -1)).toBe(2)
        expect(nextIndex(1, 3, -1)).toBe(0)
    })

    it('starts at the first or last option when none is active', () => {
        expect(nextIndex(-1, 3, 1)).toBe(0)
        expect(nextIndex(-1, 3, -1)).toBe(2)
        expect(nextIndex(7, 3, 1)).toBe(0)
    })

    it('returns -1 for an empty list', () => {
        expect(nextIndex(0, 0, 1)).toBe(-1)
        expect(nextIndex(-1, 0, -1)).toBe(-1)
    })

    it('stays on a single option', () => {
        expect(nextIndex(0, 1, 1)).toBe(0)
        expect(nextIndex(0, 1, -1)).toBe(0)
    })
})

describe('isSafePath (SECS-5)', () => {
    it.each(['/admin/entities/posts/12', '/admin/entities/posts?search=%D0%BA', '/', '/a/b#c'])('accepts the same-origin path %s', (value) => {
        expect(isSafePath(value)).toBe(true)
    })

    it.each([
        'javascript:alert(1)',
        'JavaScript:alert(1)',
        'data:text/html,x',
        'https://evil.example/x',
        '//evil.example/x',
        '/\\evil.example/x',
        '/\t/evil.example',
        '/\n/evil.example',
        '/a b',
        'admin/entities',
        '',
        null,
        undefined,
        42,
    ])('rejects %j', (value) => {
        expect(isSafePath(value)).toBe(false)
    })

    it('drops results and "View all" rows with unsafe URLs', () => {
        const options = flattenOptions([
            {
                entity: 'posts',
                label: 'Posts',
                results: [
                    { id: 1, title: 'ok', url: '/admin/entities/posts/1' },
                    { id: 2, title: 'js', url: 'javascript:alert(1)' },
                    { id: 3, title: 'off-site', url: '//evil.example/3' },
                    { id: 4, title: 'absolute', url: 'https://evil.example/4' },
                ],
                indexUrl: '/\\evil.example',
            },
            { entity: 'tags', label: 'Tags', results: [{ id: 5, title: 'x', url: 'https://evil.example' }], indexUrl: '/admin/entities/tags?search=x' },
        ])

        expect(options.map((option) => [option.kind, option.url])).toEqual([['result', '/admin/entities/posts/1']])
    })
})

describe('enterAction (CRS-04)', () => {
    it('opens the active option when the results answer the current input', () => {
        expect(enterAction({ term: ' inv ', searched: 'inv', hasActive: true, searchable: true })).toBe('choose')
    })

    it('searches now when typed within the debounce window', () => {
        expect(enterAction({ term: 'invoice 12', searched: 'inv', hasActive: true, searchable: true })).toBe('search')
        expect(enterAction({ term: '12345', searched: '1234', hasActive: true, searchable: true })).toBe('search')
    })

    it('searches when nothing is active and does nothing for a too short term', () => {
        expect(enterAction({ term: 'inv', searched: '', hasActive: false, searchable: true })).toBe('search')
        expect(enterAction({ term: 'a', searched: '', hasActive: false, searchable: false })).toBe('none')
    })
})

describe('modifierLabel', () => {
    it('shows ⌘ on Apple platforms and Ctrl elsewhere', () => {
        expect(modifierLabel('MacIntel')).toBe('⌘')
        expect(modifierLabel('iPhone')).toBe('⌘')
        expect(modifierLabel('Win32')).toBe('Ctrl')
        expect(modifierLabel('')).toBe('Ctrl')
    })
})

describe('wantsNewTab', () => {
    it('is true with Ctrl or ⌘ held', () => {
        expect(wantsNewTab({ ctrlKey: true })).toBe(true)
        expect(wantsNewTab({ metaKey: true })).toBe(true)
        expect(wantsNewTab({})).toBe(false)
        expect(wantsNewTab(null)).toBe(false)
    })
})
