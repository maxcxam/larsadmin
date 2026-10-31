import { describe, expect, it } from 'vitest'
import {
    applyLocalChanges,
    applyRunSignal,
    isActiveRun,
    isFailedRun,
    isFinishedRun,
    badgeText,
    isPanelHref,
    isSafeHref,
    levelIcon,
    memberUrl,
    mergeItems,
    newSince,
    nextPollDelay,
    parseSignal,
    percent,
    relativeTime,
    runStatusKey,
    runsPollDelay,
    safeHref,
    withCursor,
} from './notifications'

const ORIGIN = 'https://app.test'
const BACKSLASH = String.fromCharCode(92)

describe('badgeText', () => {
    it('shows the count, "99+" above 99 and nothing at zero or unknown', () => {
        expect(badgeText(3)).toBe('3')
        expect(badgeText(99)).toBe('99')
        expect(badgeText(100)).toBe('99+')
        expect(badgeText(0)).toBe('')
        expect(badgeText(null)).toBe('')
        expect(badgeText(undefined)).toBe('')
        expect(badgeText(-2)).toBe('')
    })
})

describe('isSafeHref / safeHref', () => {
    it.each(['/admin/runs/01JC/download', '/admin/entities/posts?x=1', `${ORIGIN}/admin/entities/posts/3`, `${ORIGIN}/x`])(
        'accepts same-origin %s',
        (url) => expect(isSafeHref(url, ORIGIN)).toBe(true),
    )

    it.each([
        '//evil.test/x',
        `/${BACKSLASH}evil.test`,
        '/\t/evil.test',
        'javascript:alert(1)',
        'JAVASCRIPT:alert(1)',
        'data:text/html,<script>alert(1)</script>',
        'https://evil.test/admin',
        'https://app.test.evil.test/admin',
        'admin/relative',
        '',
        null,
        undefined,
        42,
    ])('rejects %s', (url) => {
        expect(isSafeHref(url, ORIGIN)).toBe(false)
        expect(safeHref(url, ORIGIN)).toBeNull()
    })

    it('rejects absolute URLs when the origin is unknown', () => {
        expect(safeHref(`${ORIGIN}/admin`, null)).toBeNull()
        expect(safeHref('/admin', null)).toBe('/admin')
    })
})

describe('isPanelHref', () => {
    it('is true for the panel path and below only', () => {
        expect(isPanelHref('/admin', '/admin')).toBe(true)
        expect(isPanelHref('/admin/entities/posts?x=1', '/admin')).toBe(true)
        expect(isPanelHref('/administrator', '/admin')).toBe(false)
        expect(isPanelHref('/storage/file.csv', '/admin')).toBe(false)
        expect(isPanelHref('//evil.test/admin', '/admin')).toBe(false)
        expect(isPanelHref('https://app.test/admin', '/admin')).toBe(false)
    })
})

describe('levelIcon', () => {
    it('uses the whitelisted icon, else the level icon', () => {
        expect(levelIcon('success', 'download')).toBe('download')
        expect(levelIcon('success', 'evil')).toBe('check')
        expect(levelIcon('danger')).toBe('alert')
        expect(levelIcon('warning')).toBe('alert')
        expect(levelIcon('info')).toBe('bell')
        expect(levelIcon('unknown')).toBe('bell')
    })
})

describe('relativeTime', () => {
    const now = Date.parse('2026-11-02T14:00:00Z')

    it('is null (just now) under a minute and for invalid dates', () => {
        expect(relativeTime('2026-11-02T13:59:30Z', 'en', now)).toBeNull()
        expect(relativeTime('nope', 'en', now)).toBeNull()
    })

    it('formats minutes, hours and days in the locale', () => {
        expect(relativeTime('2026-11-02T13:55:00Z', 'en', now)).toBe('5 minutes ago')
        expect(relativeTime('2026-11-02T12:00:00Z', 'en', now)).toBe('2 hours ago')
        expect(relativeTime('2026-11-01T14:00:00Z', 'en', now)).toBe('yesterday')
        expect(relativeTime('2026-11-02T13:55:00Z', 'uk', now)).toContain('5')
    })
})

describe('mergeItems', () => {
    const a = { id: 'a', createdAt: '2026-11-02T10:00:00Z', read: false }
    const b = { id: 'b', createdAt: '2026-11-02T11:00:00Z', read: false }
    const c = { id: 'c', createdAt: '2026-11-02T11:00:00Z', read: false }

    it('unions by id, newer first (id desc on ties), incoming wins', () => {
        const merged = mergeItems([a, { ...b }], [{ ...b, read: true }, c])

        expect(merged.map((item) => item.id)).toEqual(['c', 'b', 'a'])
        expect(merged.find((item) => item.id === 'b').read).toBe(true)
    })

    it('appends a next page without duplicates and drops items without an id', () => {
        expect(mergeItems([b], [b, a, { title: 'x' }]).map((item) => item.id)).toEqual(['b', 'a'])
        expect(mergeItems(null, undefined)).toEqual([])
    })
})

describe('newSince', () => {
    const items = [
        { id: 'n3', createdAt: '2026-11-02T12:00:00Z' },
        { id: 'n2', createdAt: '2026-11-02T11:00:00Z' },
        { id: 'n1', createdAt: '2026-11-02T10:00:00Z' },
    ]

    it('returns the items whose id is not known', () => {
        expect(newSince(items, new Set(['n1', 'n2'])).map((item) => item.id)).toEqual(['n3'])
        expect(newSince(items, ['n1']).map((item) => item.id)).toEqual(['n3', 'n2'])
    })

    it('with a baseline, only items created after it', () => {
        expect(newSince(items, new Set(), '2026-11-02T11:00:00Z').map((item) => item.id)).toEqual(['n3'])
    })
})

describe('percent', () => {
    it('is the floored share of processed records', () => {
        expect(percent({ status: 'running', total: 1200, processed: 400 })).toBe(33)
        expect(percent({ status: 'running', total: 10, processed: 15 })).toBe(100)
        expect(percent({ status: 'completed', total: 10, processed: 10 })).toBe(100)
    })

    it('is null (indeterminate) for queued, standalone or unknown totals', () => {
        expect(percent({ status: 'queued', total: 100, processed: 0 })).toBeNull()
        expect(percent({ status: 'running', total: null, processed: 0 })).toBeNull()
        expect(percent({ status: 'running', total: 0, processed: 0 })).toBeNull()
        expect(percent({ status: 'completed', total: null, processed: 0 })).toBe(100)
    })
})

describe('runStatusKey', () => {
    const now = Date.parse('2026-11-02T14:00:00Z')

    it('maps statuses to keys', () => {
        expect(runStatusKey({ status: 'running' }, now)).toBe('runs_status_running')
        expect(runStatusKey({ status: 'completed' }, now)).toBe('runs_status_completed')
        expect(runStatusKey({ status: 'abandoned' }, now)).toBe('runs_status_abandoned')
        expect(runStatusKey({ status: 'stale' }, now)).toBe('runs_status_failed') // unknown final status
        expect(runStatusKey({ status: 'Bad Status!' }, now)).toBe('runs_status_queued')
        expect(runStatusKey({}, now)).toBe('runs_status_queued')
    })

    it('says "cancelling" and "waiting for a worker" when relevant', () => {
        expect(runStatusKey({ status: 'running', cancelRequested: true }, now)).toBe('runs_cancelling')
        expect(runStatusKey({ status: 'queued', createdAt: '2026-11-02T13:59:30Z' }, now)).toBe('runs_status_queued')
        expect(runStatusKey({ status: 'queued', createdAt: '2026-11-02T13:58:00Z' }, now)).toBe('runs_waiting_worker')
    })
})

describe('failed-like and unknown run statuses', () => {
    it('treats abandoned and unknown final statuses as finished failures', () => {
        expect(isFinishedRun({ status: 'abandoned' })).toBe(true)
        expect(isFailedRun({ status: 'abandoned' })).toBe(true)
        expect(isFailedRun({ status: 'stale' })).toBe(true)
        expect(isFailedRun({ status: 'failed' })).toBe(true)
        expect(isFailedRun({ status: 'completed' })).toBe(false)
        expect(isFailedRun({ status: 'cancelled' })).toBe(false)
        expect(isFinishedRun({ status: 'running' })).toBe(false)
        expect(isFinishedRun({})).toBe(false)
        expect(isActiveRun({ status: 'abandoned' })).toBe(false)
    })
})

describe('applyLocalChanges (CRN-06)', () => {
    const items = [
        { id: 'c', createdAt: '2026-11-02T12:00:00Z', read: false },
        { id: 'b', createdAt: '2026-11-02T11:00:00Z', read: false },
        { id: 'a', createdAt: '2026-11-02T10:00:00Z', read: false },
    ]

    it('keeps local reads and deletes over a stale response', () => {
        const result = applyLocalChanges(items, { read: new Set(['b']), deleted: new Set(['a']) })

        expect(result.map((item) => [item.id, item.read])).toEqual([['c', false], ['b', true]])
        expect(items[1].read).toBe(false) // input untouched
    })

    it('mark all as read covers everything up to readUntil, not newer items', () => {
        const result = applyLocalChanges(items, { read: new Set(), deleted: new Set(), readUntil: '2026-11-02T11:00:00Z' })

        expect(result.map((item) => item.read)).toEqual([false, true, true])
    })

    it('is a no-op without local changes', () => {
        expect(applyLocalChanges(items)).toEqual(items)
        expect(applyLocalChanges(null)).toEqual([])
    })
})

describe('applyRunSignal', () => {
    it('updates status and numbers, ignores junk', () => {
        const run = { id: 'r', status: 'running', processed: 1, total: 10, cancellable: true }

        expect(applyRunSignal(run, { status: 'running', processed: 5, total: 10 })).toMatchObject({ processed: 5, total: 10 })
        expect(applyRunSignal(run, { status: '<evil>', processed: 'x' })).toMatchObject({ status: 'running', processed: 1 })
        expect(applyRunSignal(run, { status: 'completed', processed: 10 })).toMatchObject({ status: 'completed', cancellable: false })
        // A stale run the server gave up on keeps the server's "cancellable" flag.
        expect(applyRunSignal(run, { status: 'abandoned' })).toMatchObject({ status: 'abandoned', cancellable: true })
    })
})

describe('polling delays', () => {
    it('backs off exponentially after failures, capped at 5 minutes', () => {
        expect(nextPollDelay(0, 60_000)).toBe(60_000)
        expect(nextPollDelay(1, 60_000)).toBe(120_000)
        expect(nextPollDelay(2, 60_000)).toBe(240_000)
        expect(nextPollDelay(3, 60_000)).toBe(300_000)
        expect(nextPollDelay(50, 60_000)).toBe(300_000)
    })

    it('polls runs every 3 s, every 10 s after two minutes, every 30 s with realtime', () => {
        expect(runsPollDelay(0)).toBe(3_000)
        expect(runsPollDelay(119_999)).toBe(3_000)
        expect(runsPollDelay(120_000)).toBe(10_000)
        expect(runsPollDelay(0, true)).toBe(30_000)
    })
})

describe('parseSignal', () => {
    it('accepts only id-only notification and run signals', () => {
        expect(parseSignal({ kind: 'notification', id: '9b2c-1' })).toEqual({ kind: 'notification', id: '9b2c-1' })
        expect(parseSignal({ kind: 'run', id: '01JC', status: 'running', processed: 4, total: 10 })).toEqual({
            kind: 'run',
            id: '01JC',
            status: 'running',
            processed: 4,
            total: 10,
        })
        expect(parseSignal({ kind: 'run', id: '01JC', status: 'X <b>', total: null })).toMatchObject({ status: null, total: null })
    })

    it('rejects anything else', () => {
        expect(parseSignal(null)).toBeNull()
        expect(parseSignal({ kind: 'notification' })).toBeNull()
        expect(parseSignal({ kind: 'notification', id: '../x' })).toBeNull()
        expect(parseSignal({ kind: 'other', id: 'a' })).toBeNull()
    })
})

describe('URL builders', () => {
    it('encodes ids and adds the cursor', () => {
        expect(memberUrl('/admin/api/notifications/', 'a b', 'read')).toBe('/admin/api/notifications/a%20b/read')
        expect(memberUrl('/admin/api/runs', '01JC')).toBe('/admin/api/runs/01JC')
        expect(withCursor('/admin/api/notifications', 'eyJ+/=')).toBe('/admin/api/notifications?cursor=eyJ%2B%2F%3D')
        expect(withCursor('/x?a=1', 'c')).toBe('/x?a=1&cursor=c')
        expect(withCursor('/x', null)).toBe('/x')
    })
})
