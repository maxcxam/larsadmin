import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createRegistry, isExtensionKey, OVERRIDABLE } from './registry'

const component = (name) => ({ name, render: () => null })

const BuiltinText = component('BuiltinText')
const BuiltinTextDisplay = component('BuiltinTextDisplay')
const BuiltinDate = component('BuiltinDate')
const BuiltinFile = component('BuiltinFile')
const BuiltinValue = component('BuiltinValue')
const BuiltinSelectFilter = component('BuiltinSelectFilter')

let logger
let registry

beforeEach(() => {
    logger = { error: vi.fn(), warn: vi.fn(), info: vi.fn() }
    registry = createRegistry({ logger })
    registry.register('fields', 'text-field', { form: BuiltinText, display: BuiltinTextDisplay }, { builtin: true })
    registry.register('fields', 'date-field', { form: BuiltinDate, display: BuiltinTextDisplay }, { builtin: true })
    registry.register('fields', 'file-field', { form: BuiltinFile, display: BuiltinTextDisplay }, { builtin: true })
    registry.register('cards', 'value-metric', BuiltinValue, { builtin: true })
    registry.register('filters', 'select-filter', BuiltinSelectFilter, { builtin: true })
})

describe('extension keys', () => {
    it('registers vendor/name keys and resolves parts with index/detail falling back to display', () => {
        const form = component('RatingInput')
        const display = component('RatingDisplay')
        const index = component('RatingIndex')

        expect(registry.register('fields', 'acme/rating', { form, display, index }, { extension: 'acme/rating-pkg' })).toBe(true)

        expect(registry.resolve('fields', 'acme/rating', 'form')).toMatchObject({ component: form, source: 'extension', extension: 'acme/rating-pkg' })
        expect(registry.resolve('fields', 'acme/rating', 'index').component).toBe(index)
        expect(registry.resolve('fields', 'acme/rating', 'detail').component).toBe(display)
        expect(registry.resolve('fields', 'acme/rating', 'panel')).toBeNull()
    })

    it('rejects malformed keys and keys without a slash that are not built-in', () => {
        expect(registry.register('fields', 'Acme/Rating', { form: component('x') }, { extension: 'acme/x' })).toBe(false)
        expect(registry.register('fields', 'acme/rating/extra', { form: component('x') }, { extension: 'acme/x' })).toBe(false)
        expect(registry.register('fields', 'rating', { form: component('x') }, { extension: 'acme/x' })).toBe(false)
        expect(logger.error).toHaveBeenCalledTimes(3)
        expect(registry.resolve('fields', 'rating', 'form')).toBeNull()
    })

    it('keeps the first registration of a key and rejects duplicates', () => {
        const first = component('First')

        registry.register('cards', 'acme/sales', first, { extension: 'acme/one' })
        expect(registry.register('cards', 'acme/sales', component('Second'), { extension: 'acme/two' })).toBe(false)
        expect(logger.error).toHaveBeenCalledWith(expect.stringContaining('already registered by "acme/one"'))
        expect(registry.resolve('cards', 'acme/sales').component).toBe(first)
    })

    it('accepts { component } for cards, filters and tools', () => {
        const tool = component('Backups')

        registry.register('tools', 'acme/backups', { component: tool }, { extension: 'acme/x' })
        expect(registry.resolve('tools', 'acme/backups').component).toBe(tool)
    })

    it('rejects an entry without a component', () => {
        expect(registry.register('fields', 'acme/empty', {}, { extension: 'acme/x' })).toBe(false)
        expect(registry.register('cards', 'acme/empty', null, { extension: 'acme/x' })).toBe(false)
    })

    it('removes every key of an extension (failed setup)', () => {
        registry.register('fields', 'acme/rating', { form: component('x') }, { extension: 'acme/x' })
        registry.register('cards', 'acme/sales', component('y'), { extension: 'acme/x' })
        registry.register('cards', 'other/kept', component('z'), { extension: 'other/y' })
        registry.register('fields', 'date-field', { form: component('Fancy'), override: true }, { extension: 'acme/x', override: true })

        registry.unregisterExtension('acme/x')

        expect(registry.resolve('fields', 'acme/rating', 'form')).toBeNull()
        expect(registry.resolve('cards', 'acme/sales')).toBeNull()
        expect(registry.resolve('cards', 'other/kept')).not.toBeNull()
        expect(registry.resolve('fields', 'date-field', 'form').component).toBe(BuiltinDate)
        expect(registry.overrides).toEqual([])
    })

    it('tells extension keys apart', () => {
        expect(isExtensionKey('acme/rating')).toBe(true)
        expect(isExtensionKey('text-field')).toBe(false)
        expect(isExtensionKey(undefined)).toBe(false)
    })
})

describe('built-ins (dogfooding)', () => {
    it('resolves built-ins as the 1.4 maps did, merging parts of later registerField() calls', () => {
        expect(registry.resolve('fields', 'text-field', 'form')).toMatchObject({ component: BuiltinText, source: 'builtin' })
        expect(registry.resolve('fields', 'text-field', 'index').component).toBe(BuiltinTextDisplay)

        const custom = component('CustomDisplay')
        registry.register('fields', 'text-field', { display: custom }, { builtin: true })

        expect(registry.resolve('fields', 'text-field', 'display').component).toBe(custom)
        expect(registry.resolve('fields', 'text-field', 'form').component).toBe(BuiltinText)
    })
})

describe('overrides (Q-70)', () => {
    it('rejects a built-in key without override: true with a warning', () => {
        expect(registry.register('fields', 'date-field', { form: component('Fancy') }, { extension: 'acme/x' })).toBe(false)
        expect(logger.warn).toHaveBeenCalledWith(expect.stringContaining('use override: true'))
        expect(registry.resolve('fields', 'date-field', 'form').component).toBe(BuiltinDate)
    })

    it('rejects keys outside the overridable list', () => {
        expect(registry.register('fields', 'file-field', { form: component('Fancy') }, { extension: 'acme/x', override: true })).toBe(false)
        expect(logger.warn).toHaveBeenCalledWith(expect.stringContaining('cannot be overridden'))
        expect(registry.resolve('fields', 'file-field', 'form').component).toBe(BuiltinFile)
        expect(OVERRIDABLE.fields).not.toContain('file-field')
        expect(OVERRIDABLE.fields).toHaveLength(8)
        expect(OVERRIDABLE.filters).toHaveLength(3)
        expect(OVERRIDABLE.cards).toHaveLength(4)
    })

    it('overrides only the given part and keeps the built-in for the rest', () => {
        const fancy = component('FancyDate')

        expect(registry.register('fields', 'date-field', { form: fancy, override: true }, { extension: 'acme/x', override: true })).toBe(true)

        expect(registry.resolve('fields', 'date-field', 'form')).toMatchObject({ component: fancy, source: 'override', builtin: BuiltinDate, extension: 'acme/x' })
        expect(registry.resolve('fields', 'date-field', 'display')).toMatchObject({ component: BuiltinTextDisplay, source: 'builtin' })
        expect(registry.overrides).toEqual([{ kind: 'fields', key: 'date-field', part: 'form', extension: 'acme/x' }])
    })

    it('uses the object form for cards and filters', () => {
        const big = component('BigNumber')

        registry.register('cards', 'value-metric', { component: big, override: true }, { extension: 'acme/x', override: true })
        expect(registry.resolve('cards', 'value-metric')).toMatchObject({ component: big, source: 'override', builtin: BuiltinValue })
    })

    it('keeps the first override of a part and rejects the second with an error', () => {
        const first = component('First')

        registry.register('fields', 'text-field', { form: first }, { extension: 'acme/one', override: true })
        expect(registry.register('fields', 'text-field', { form: component('Second') }, { extension: 'acme/two', override: true })).toBe(false)
        expect(logger.error).toHaveBeenCalledWith(expect.stringContaining('already overridden by "acme/one"'))
        expect(registry.resolve('fields', 'text-field', 'form').component).toBe(first)

        // A different part of the same key is still free.
        const display = component('Display')
        expect(registry.register('fields', 'text-field', { display }, { extension: 'acme/two', override: true })).toBe(true)
        expect(registry.resolve('fields', 'text-field', 'index').component).toBe(display)
    })

    it('falls back to the built-in after the override crashed (boundary)', () => {
        registry.register('fields', 'date-field', { form: component('Fancy') }, { extension: 'acme/x', override: true })
        registry.markCrashed('fields', 'date-field', 'form')

        expect(registry.resolve('fields', 'date-field', 'form')).toMatchObject({ component: BuiltinDate, source: 'builtin' })
        // An override never makes the field unavailable: the built-in handles the value.
        expect(registry.isUnavailable('fields', 'date-field', 'form')).toBe(false)
    })

    it('renders the built-in while the overriding extension is loading or after it failed (not registered)', () => {
        registry.setExtensionState('acme/x', 'loading')
        expect(registry.resolve('fields', 'date-field', 'form').source).toBe('builtin')

        registry.setExtensionState('acme/x', 'failed', { reason: 'import' })
        expect(registry.resolve('fields', 'date-field', 'form').source).toBe('builtin')
    })
})

describe('availability and state', () => {
    it('reports missing and crashed extension components as unavailable (fail closed)', () => {
        expect(registry.isUnavailable('fields', 'acme/rating', 'form')).toBe(true)

        registry.register('fields', 'acme/rating', { form: component('x') }, { extension: 'acme/x' })
        expect(registry.isUnavailable('fields', 'acme/rating', 'form')).toBe(false)

        registry.markCrashed('fields', 'acme/rating', 'form')
        expect(registry.isUnavailable('fields', 'acme/rating', 'form')).toBe(true)

        // Keys without `/` keep the 1.4 fallback and are never excluded.
        expect(registry.isUnavailable('fields', 'unknown-field', 'form')).toBe(false)
    })

    it('tracks whether any extension is still loading', () => {
        expect(registry.isLoading()).toBe(false)
        registry.setExtensionState('acme/a', 'loading')
        registry.setExtensionState('acme/b', 'loaded')
        expect(registry.isLoading()).toBe(true)
        registry.setExtensionState('acme/a', 'failed', { reason: 'import' })
        expect(registry.isLoading()).toBe(false)
        expect(registry.extensionState('acme/a')).toMatchObject({ status: 'failed', reason: 'import' })
    })

    it('throws on an unknown kind', () => {
        expect(() => registry.resolve('widgets', 'x')).toThrow(TypeError)
    })
})
