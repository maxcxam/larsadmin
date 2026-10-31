import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createToasts, normalizeType } from './useToasts'

describe('useToasts', () => {
    beforeEach(() => vi.useFakeTimers())
    afterEach(() => vi.useRealTimers())

    it('shows at most three toasts; the rest wait and move up', () => {
        const toasts = createToasts()

        for (const n of [1, 2, 3, 4, 5]) toasts.push({ message: `m${n}`, timeout: 1000 })

        expect(toasts.visible.value.map((toast) => toast.message)).toEqual(['m1', 'm2', 'm3'])
        expect(toasts.hiddenCount.value).toBe(2)

        toasts.dismiss(toasts.visible.value[0].id)
        expect(toasts.visible.value.map((toast) => toast.message)).toEqual(['m2', 'm3', 'm4'])
        expect(toasts.hiddenCount.value).toBe(1)
    })

    it('expires a toast after its timeout; waiting toasts start their timer once visible', () => {
        const toasts = createToasts({ max: 1 })

        toasts.push({ message: 'first', timeout: 4000 })
        toasts.push({ message: 'second', timeout: 4000 })

        vi.advanceTimersByTime(3999)
        expect(toasts.state.items).toHaveLength(2)

        vi.advanceTimersByTime(1)
        expect(toasts.state.items.map((toast) => toast.message)).toEqual(['second'])

        vi.advanceTimersByTime(4000)
        expect(toasts.state.items).toHaveLength(0)
    })

    it('pauses the timer on hover/focus and resumes with the remaining time', () => {
        const toasts = createToasts()
        const id = toasts.push({ message: 'x', timeout: 4000 })

        vi.advanceTimersByTime(3000)
        toasts.pause(id)
        vi.advanceTimersByTime(60_000)
        expect(toasts.state.items).toHaveLength(1)

        toasts.resume(id)
        vi.advanceTimersByTime(999)
        expect(toasts.state.items).toHaveLength(1)
        vi.advanceTimersByTime(1)
        expect(toasts.state.items).toHaveLength(0)
    })

    it('keeps a toast with timeout 0 until dismissed', () => {
        const toasts = createToasts()
        const id = toasts.push({ message: 'sticky', timeout: 0 })

        vi.advanceTimersByTime(600_000)
        expect(toasts.state.items).toHaveLength(1)

        toasts.dismiss(id)
        expect(toasts.state.items).toHaveLength(0)
    })

    it('de-duplicates by key and ignores empty toasts', () => {
        const toasts = createToasts()

        expect(toasts.push({ message: 'a', key: 'notification:1' })).not.toBeNull()
        expect(toasts.push({ message: 'a again', key: 'notification:1' })).toBeNull()
        expect(toasts.push({ message: '' })).toBeNull()
        expect(toasts.state.items).toHaveLength(1)
    })

    it('forgets the oldest de-duplication keys beyond the cap', () => {
        const toasts = createToasts({ maxKeys: 2 })

        toasts.push({ message: 'a', key: 'k1' })
        toasts.push({ message: 'b', key: 'k2' })
        toasts.push({ message: 'c', key: 'k3' }) // k1 forgotten

        expect(toasts.push({ message: 'a again', key: 'k1' })).not.toBeNull()
        expect(toasts.push({ message: 'c again', key: 'k3' })).toBeNull()
    })

    it('normalizes types (danger is an error) and keeps links as data', () => {
        const toasts = createToasts()

        toasts.push({ message: 'x', type: 'danger', link: { href: '/admin/x', label: 'Open' } })

        expect(toasts.state.items[0]).toMatchObject({ type: 'error', link: { href: '/admin/x', label: 'Open', download: false } })
        expect(normalizeType('weird')).toBe('success')
        expect(normalizeType('warning')).toBe('warning')
    })
})
