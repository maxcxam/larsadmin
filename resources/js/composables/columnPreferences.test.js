import { describe, expect, it } from 'vitest'
import {
    isDefault,
    loadPreferences,
    moveColumn,
    resolveColumns,
    savePreferences,
    serialize,
    toggleColumn,
} from './columnPreferences.js'

const cols = [
    { attribute: 'id', name: 'ID' },
    { attribute: 'name', name: 'Name' },
    { attribute: 'email', name: 'Email', hideByDefault: true },
    { attribute: 'created', name: 'Created', width: '120px', align: 'right' },
]

const attrs = (items) => items.map((i) => i.column.attribute)
const visible = (items) => attrs(items.filter((i) => i.visible))

function memoryStorage() {
    const data = {}

    return {
        getItem: (k) => data[k] ?? null,
        setItem: (k, v) => (data[k] = v),
        removeItem: (k) => delete data[k],
    }
}

describe('resolveColumns', () => {
    it('uses server order and hideByDefault without saved prefs', () => {
        const items = resolveColumns(cols, null)

        expect(attrs(items)).toEqual(['id', 'name', 'email', 'created'])
        expect(visible(items)).toEqual(['id', 'name', 'created'])
    })

    it('fills defaults for older payloads missing the new keys', () => {
        const [first] = resolveColumns([{ attribute: 'x', name: 'X' }], null)

        expect(first.column).toMatchObject({ width: null, align: 'left', wrap: false, truncate: null, hideByDefault: false })
    })

    it('applies saved order and visibility', () => {
        const items = resolveColumns(cols, { order: ['created', 'email', 'id', 'name'], hidden: ['id'] })

        expect(attrs(items)).toEqual(['created', 'email', 'id', 'name'])
        expect(visible(items)).toEqual(['created', 'email', 'name'])
    })

    it('ignores unknown / removed columns in saved prefs', () => {
        const items = resolveColumns(cols, { order: ['gone', 'name'], hidden: ['gone2'] })

        expect(attrs(items)).toEqual(['name', 'id', 'email', 'created'])
    })

    it('appends new columns with their own default visibility', () => {
        const items = resolveColumns(cols, { order: ['name', 'id'], hidden: [] })

        expect(attrs(items)).toEqual(['name', 'id', 'email', 'created'])
        expect(visible(items)).toEqual(['name', 'id', 'created'])
    })

    it('keeps an explicitly shown hideByDefault column visible', () => {
        const items = resolveColumns(cols, { order: ['id', 'name', 'email', 'created'], hidden: [] })

        expect(visible(items)).toContain('email')
    })

    it('never produces an empty table', () => {
        const items = resolveColumns(cols, { order: ['id', 'name', 'email', 'created'], hidden: ['id', 'name', 'email', 'created'] })

        expect(visible(items)).toEqual(['id'])
    })

    it('tolerates garbage prefs', () => {
        expect(attrs(resolveColumns(cols, 'nope'))).toEqual(['id', 'name', 'email', 'created'])
        expect(attrs(resolveColumns(cols, { order: 5, hidden: 'x' }))).toEqual(['id', 'name', 'email', 'created'])
    })
})

describe('editing', () => {
    it('toggles visibility but keeps the last visible column', () => {
        let items = resolveColumns(cols, null)

        items = toggleColumn(items, 'email')
        expect(visible(items)).toContain('email')

        items = ['id', 'name', 'email'].reduce(toggleColumn, items)
        expect(visible(items)).toEqual(['created'])
        expect(toggleColumn(items, 'created')).toBe(items)
    })

    it('moves columns up/down and clamps at the edges', () => {
        const items = resolveColumns(cols, null)

        expect(attrs(moveColumn(items, 'name', -1))).toEqual(['name', 'id', 'email', 'created'])
        expect(attrs(moveColumn(items, 'name', 1))).toEqual(['id', 'email', 'name', 'created'])
        expect(moveColumn(items, 'id', -1)).toBe(items)
        expect(moveColumn(items, 'created', 1)).toBe(items)
    })

    it('detects the default arrangement and round-trips through serialize', () => {
        const items = resolveColumns(cols, null)

        expect(isDefault(cols, items)).toBe(true)

        const moved = moveColumn(items, 'name', -1)

        expect(isDefault(cols, moved)).toBe(false)
        expect(attrs(resolveColumns(cols, serialize(moved)))).toEqual(attrs(moved))
    })
})

describe('storage', () => {
    it('saves, loads and removes prefs', () => {
        const storage = memoryStorage()

        savePreferences('users', { order: ['id'], hidden: [] }, storage)
        expect(loadPreferences('users', storage)).toEqual({ order: ['id'], hidden: [] })

        savePreferences('users', null, storage)
        expect(loadPreferences('users', storage)).toBeNull()
    })

    it('survives broken storage and invalid JSON', () => {
        const broken = {
            getItem: () => {
                throw new Error('denied')
            },
            setItem: () => {
                throw new Error('denied')
            },
        }

        expect(loadPreferences('users', broken)).toBeNull()
        expect(() => savePreferences('users', {}, broken)).not.toThrow()
        expect(loadPreferences('users', { getItem: () => '{oops' })).toBeNull()
    })
})
