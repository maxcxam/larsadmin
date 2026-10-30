import { beforeEach, describe, expect, it, vi } from 'vitest'

const props = { larsadmin: { locale: 'uk', translations: {} } }

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props }) }))

const { useI18n } = await import('../../composables/useI18n.js')
const {
    actionFieldRows,
    actorInfo,
    appendPage,
    isHistoryPage,
    activeFilterValues,
    actorText,
    changeRows,
    eventKey,
    eventLabel,
    filterQuery,
    formatDateTime,
    hasDetails,
    pageUrl,
    recordDeleted,
    recordText,
    safeUrl,
    summary,
    toValue,
    valueText,
} = await import('./audit.js')

// English strings as the server would share them (the bundled fallback is Ukrainian).
const en = {
    audit_event_created: 'Created',
    audit_event_updated: 'Updated',
    audit_event_deleted: 'Deleted',
    audit_event_deleted_soft: 'Soft deleted',
    audit_event_action: 'Action “:action”',
    audit_event_attached: 'Attached :entity: :title',
    audit_event_detached: 'Detached :entity: :title',
    audit_changed_fields: ':count field|:count fields',
    audit_system: 'System',
    audit_masked: 'Hidden',
    audit_empty_value: 'empty',
    audit_truncated: '(truncated)',
    yes: 'Yes',
    no: 'No',
}

function i18n(locale) {
    props.larsadmin = { locale, translations: locale === 'en' ? en : {} }

    return useI18n()
}

beforeEach(() => {
    props.larsadmin = { locale: 'uk', translations: {} }
})

describe('eventKey', () => {
    it.each([
        ['created', 'audit_event_created'],
        ['updated', 'audit_event_updated'],
        ['deleted', 'audit_event_deleted'],
        ['action', 'audit_event_action'],
        ['attached', 'audit_event_attached'],
        ['detached', 'audit_event_detached'],
    ])('maps %s to its key', (event, key) => {
        expect(eventKey(event)).toBe(key)
        expect(eventKey({ event })).toBe(key)
    })

    it('uses the soft-delete key for soft deletes only', () => {
        expect(eventKey({ event: 'deleted', soft: true })).toBe('audit_event_deleted_soft')
        expect(eventKey({ event: 'deleted', soft: false })).toBe('audit_event_deleted')
        expect(eventKey({ event: 'updated', soft: true })).toBe('audit_event_updated')
    })

    it('falls back for unknown events', () => {
        expect(eventKey('restored')).toBe('audit_event_updated')
        expect(eventKey(null)).toBe('audit_event_updated')
    })
})

describe('eventLabel / summary', () => {
    const changes = (count) => Array.from({ length: count }, (_, i) => ({ attribute: `f${i}`, label: `F${i}`, masked: true }))

    it.each([
        [1, 'Змінено: 1 поле'],
        [3, 'Змінено: 3 поля'],
        [5, 'Змінено: 5 полів'],
        [21, 'Змінено: 21 поле'],
    ])('pluralises the changed fields in Ukrainian (%i)', (count, expected) => {
        const { t, tc } = i18n('uk')

        expect(summary({ event: 'updated', changes: changes(count) }, t, tc)).toBe(expected)
    })

    it.each([
        [1, 'Updated: 1 field'],
        [2, 'Updated: 2 fields'],
    ])('pluralises the changed fields in English (%i)', (count, expected) => {
        const { t, tc } = i18n('en')

        expect(summary({ event: 'updated', changes: changes(count) }, t, tc)).toBe(expected)
    })

    it('omits the count without changes', () => {
        const { t, tc } = i18n('uk')

        expect(summary({ event: 'created', changes: [] }, t, tc)).toBe('Створено')
        expect(summary({ event: 'deleted', soft: true }, t, tc)).toBe('Переміщено в кошик')
    })

    it('names the action', () => {
        const { t, tc } = i18n('uk')

        expect(eventLabel({ event: 'action', action: { name: 'Опублікувати', fields: [] } }, t)).toBe('Дія «Опублікувати»')
        expect(summary({ event: 'action', action: { name: 'Publish' }, changes: changes(2) }, t, tc)).toBe('Дія «Publish»: 2 поля')
    })

    it('names the related record of attach / detach, or its key when the title is hidden', () => {
        const { t } = i18n('en')

        expect(eventLabel({ event: 'attached', related: { entity: 'tags', entityLabel: 'Tags', id: 7, title: 'News' } }, t)).toBe('Attached Tags: News')
        expect(eventLabel({ event: 'detached', related: { entity: 'tags', entityLabel: 'Tags', id: 7, title: null } }, t)).toBe('Detached Tags: #7')
    })
})

describe('actorText / recordText', () => {
    it('shows the actor label or "System"', () => {
        const { t } = i18n('uk')

        expect(actorText({ actor: { label: 'Олена Коваль' } }, t)).toBe('Олена Коваль')
        expect(actorText({ actor: null }, t)).toBe('Система')
        expect(actorText({ actor: { label: '' } }, t)).toBe('Система')
        expect(actorText({ actor: { id: 5, label: 'Олена' } }, t)).toBe('Олена (#5)')
        expect(actorText({ actor: { id: 5, label: null } }, t)).toBe('#5')
    })

    it('keeps a user named "System" apart from the real system actor (SECA-05)', () => {
        const { t } = i18n('uk')

        expect(actorInfo({ actor: null })).toEqual({ system: true, label: null, id: null })
        expect(actorInfo({ actor: { system: true, label: 'cron' } })).toEqual({ system: true, label: null, id: null })
        expect(actorInfo({ actor: { id: 9, label: 'Система', system: false } })).toEqual({ system: false, label: 'Система', id: '9' })
        expect(actorInfo({ actor: { id: '9', label: 'Система', system: 'true' } }).system).toBe(false)
        expect(actorText({ actor: { id: 9, label: 'Система' } }, t)).not.toBe(actorText({ actor: { system: true } }, t))
    })

    it('falls back to #id', () => {
        expect(recordText({ id: '12', title: 'Київ' })).toBe('Київ')
        expect(recordText({ id: 12, title: null })).toBe('#12')
        expect(recordText({ id: 0, title: '' })).toBe('#0')
        expect(recordText(null)).toBe('')
        expect(recordText({})).toBe('')
    })
})

describe('formatDateTime', () => {
    it('formats in the given timezone and locale', () => {
        const kyiv = formatDateTime('2026-10-30T12:05:11+00:00', 'en', 'Europe/Kyiv')
        const utc = formatDateTime('2026-10-30T12:05:11+00:00', 'en', 'UTC')

        expect(kyiv).toContain('Oct 30, 2026')
        expect(kyiv).toMatch(/2:05/)
        expect(utc).toMatch(/12:05/)
        expect(formatDateTime('2026-10-30T12:05:11+00:00', 'uk', 'UTC')).toContain('2026')
    })

    it('survives an unknown timezone and bad input', () => {
        expect(formatDateTime('2026-10-30T12:05:11+00:00', 'en', 'Mars/Olympus')).toContain('2026')
        expect(formatDateTime('not a date', 'en', 'UTC')).toBe('not a date')
        expect(formatDateTime(null, 'en', 'UTC')).toBe('')
    })
})

describe('toValue / valueText', () => {
    const opts = { locale: 'en', timezone: 'UTC' }

    it('coerces scalars and markers', () => {
        expect(toValue(null)).toEqual({ kind: 'empty' })
        expect(toValue('')).toEqual({ kind: 'empty' })
        expect(toValue(true)).toEqual({ kind: 'boolean', value: true })
        expect(toValue(5)).toEqual({ kind: 'text', text: '5' })
        expect(toValue({ masked: true })).toEqual({ kind: 'masked' })
        expect(toValue({ kind: 'text', text: 'x' })).toEqual({ kind: 'text', text: 'x' })
    })

    it('renders every kind as text', () => {
        const { t } = i18n('en')

        expect(valueText({ kind: 'empty' }, t, opts)).toBe('empty')
        expect(valueText({ kind: 'text', text: 'Київ' }, t, opts)).toBe('Київ')
        expect(valueText({ kind: 'boolean', value: true }, t, opts)).toBe('Yes')
        expect(valueText({ kind: 'boolean', value: false }, t, opts)).toBe('No')
        expect(valueText({ kind: 'datetime', value: '2026-10-30T12:05:00+00:00' }, t, opts)).toMatch(/Oct 30, 2026.*12:05/)
        expect(valueText({ kind: 'record', id: 3, title: 'Іван', url: '/admin/entities/users/3' }, t, opts)).toBe('Іван')
        expect(valueText({ kind: 'record', id: 5, title: null, url: null }, t, opts)).toBe('#5')
        expect(valueText({ kind: 'files', files: ['a.jpg', 'b.pdf'] }, t, opts)).toBe('a.jpg, b.pdf')
        expect(valueText({ kind: 'files', files: [] }, t, opts)).toBe('empty')
    })

    it('marks truncated values and hides masked ones', () => {
        const { t } = i18n('en')

        expect(valueText({ kind: 'text', text: 'Lorem', truncated: true }, t, opts)).toBe('Lorem (truncated)')
        expect(valueText({ masked: true }, t, opts)).toBe('Hidden')
        expect(valueText({ kind: 'masked', text: 'secret' }, t, opts)).toBe('Hidden')
    })
})

describe('changeRows / actionFieldRows', () => {
    it('builds diff rows and never carries values of masked changes', () => {
        const rows = changeRows({
            changes: [
                { attribute: 'title', label: 'Заголовок', old: { kind: 'text', text: 'Київ' }, new: { kind: 'text', text: 'Київ восени' } },
                { attribute: 'password', label: 'Пароль', masked: true, old: { kind: 'text', text: 'leak' } },
                { attribute: 'body', label: 'Текст' },
                null,
            ],
        })

        expect(rows).toEqual([
            { key: 'title', label: 'Заголовок', masked: false, old: { kind: 'text', text: 'Київ' }, new: { kind: 'text', text: 'Київ восени' } },
            { key: 'password', label: 'Пароль', masked: true, old: null, new: null },
            { key: 'body', label: 'Текст', masked: false, old: { kind: 'empty' }, new: { kind: 'empty' } },
        ])
        expect(changeRows({})).toEqual([])
        expect(changeRows({ changes: 'oops' })).toEqual([])
    })

    it('builds action field rows', () => {
        expect(actionFieldRows({ action: { name: 'Publish', fields: [{ label: 'Notify', value: true }, { label: 'Code', masked: true }] } }).map((row) => row.value))
            .toEqual([{ kind: 'boolean', value: true }, { kind: 'masked' }])
        expect(actionFieldRows({ action: null })).toEqual([])
    })

    it('knows when an entry has details', () => {
        expect(hasDetails({ changes: [] })).toBe(false)
        expect(hasDetails({ changes: [{ attribute: 'a', masked: true }] })).toBe(true)
        expect(hasDetails({ ip: '10.0.0.5' })).toBe(false)
        expect(hasDetails({ ip: '10.0.0.5' }, { meta: true })).toBe(true)
        expect(recordDeleted({ meta: { record_deleted: true } })).toBe(true)
        expect(recordDeleted({ recordDeleted: true })).toBe(true)
        expect(hasDetails({ recordDeleted: true })).toBe(true)
    })
})

describe('filterQuery', () => {
    it('drops empty filters and the page', () => {
        expect(filterQuery({ entity: 'posts', event: null, date: { from: '2026-10-01', to: '' } }))
            .toEqual({ filters: { entity: 'posts', date: { from: '2026-10-01' } } })
        expect(filterQuery({ entity: '', actor: null })).toEqual({})
    })

    it('keeps the actor key as is', () => {
        expect(filterQuery({ actor: 'App\\Models\\User|5' })).toEqual({ filters: { actor: 'App\\Models\\User|5' } })
        expect(filterQuery({ actor: 'system' })).toEqual({ filters: { actor: 'system' } })
    })

    it('sends cleared defaults explicitly', () => {
        expect(filterQuery({}, [{ key: 'event', default: 'updated' }])).toEqual({ filters: { event: '' } })
    })

    it('reads the active values from the query prop', () => {
        expect(activeFilterValues({ filters: { entity: 'posts' } })).toEqual({ entity: 'posts' })
        expect(activeFilterValues({ filters: [] })).toEqual({})
        expect(activeFilterValues(null)).toEqual({})
    })
})

describe('pageUrl / safeUrl', () => {
    it('sets the page parameter', () => {
        expect(pageUrl('/admin/api/entities/posts/12/audit', 1)).toBe('/admin/api/entities/posts/12/audit')
        expect(pageUrl('/admin/api/entities/posts/12/audit', 2)).toBe('/admin/api/entities/posts/12/audit?page=2')
        expect(pageUrl('/admin/api/entities/posts/12/audit?page=3&x=1', 4)).toBe('/admin/api/entities/posts/12/audit?page=4&x=1')
    })

    it('allows same-origin paths only', () => {
        expect(safeUrl('/admin/entities/users/3')).toBe('/admin/entities/users/3')
        expect(safeUrl('//evil.test')).toBeNull()
        expect(safeUrl('javascript:alert(1)')).toBeNull()
        expect(safeUrl(null)).toBeNull()
    })

    it('allows absolute URLs on the current origin', () => {
        expect(safeUrl('https://app.test/admin/entities/users/3', 'https://app.test')).toBe('https://app.test/admin/entities/users/3')
        expect(safeUrl('https://evil.test/admin', 'https://app.test')).toBeNull()
        expect(safeUrl('https://app.test/admin', null)).toBeNull()
    })
})

describe('appendPage (CRA-14)', () => {
    const url = '/admin/api/entities/posts/12/audit'
    const state = { url, generation: 3, page: 1, entries: [{ id: 30 }, { id: 29 }] }
    const request = { url, generation: 3, page: 2 }

    it('appends the next page and skips entries already shown', () => {
        expect(appendPage(state, request, { entries: [{ id: 29 }, { id: 28 }], page: 2, hasMore: true }))
            .toEqual({ entries: [{ id: 30 }, { id: 29 }, { id: 28 }], added: 1, page: 2, hasMore: true })
        expect(appendPage(state, request, { entries: [{ id: 27 }] })).toMatchObject({ added: 1, page: 2, hasMore: false })
    })

    it('drops a response for another record', () => {
        expect(appendPage(state, { ...request, url: '/admin/api/entities/posts/13/audit' }, { entries: [{ id: 1 }], page: 2 })).toBeNull()
    })

    it('drops a response from before a refresh', () => {
        expect(appendPage(state, { ...request, generation: 2 }, { entries: [{ id: 1 }], page: 2 })).toBeNull()
    })

    it('drops a duplicate or out-of-order page', () => {
        expect(appendPage({ ...state, page: 2 }, request, { entries: [{ id: 1 }], page: 2 })).toBeNull()
        expect(appendPage(state, request, { entries: [{ id: 1 }], page: 3 })).toBeNull()
    })

    it('drops malformed bodies', () => {
        expect(isHistoryPage({ entries: [] })).toBe(true)
        expect(isHistoryPage({ entries: 'x' })).toBe(false)
        expect(appendPage(state, request, null)).toBeNull()
        expect(appendPage({ ...state, entries: null }, request, { entries: [] })).toBeNull()
    })
})
