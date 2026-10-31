import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { API_VERSION, satisfies } from './version'
import { defineExtension, isExtensionDefinition } from './define'

describe('satisfies (^MAJOR.MINOR)', () => {
    it.each([
        ['^1.5', '1.5', true],
        ['^1.5', '1.6', true],
        ['^1.5', '1.10', true],
        ['^1.6', '1.5', false],
        ['^1.5', '2.0', false],
        ['^2.0', '1.9', false],
        [' ^1.5 ', '1.5', true],
    ])('%s against %s -> %s', (requires, version, expected) => {
        expect(satisfies(requires, version)).toBe(expected)
    })

    it.each([['1.5'], ['>=1.5'], ['~1.5'], ['^1'], ['^1.5.0'], ['^x.y'], [''], [null], [undefined], [15]])('rejects the invalid range %j', (requires) => {
        expect(satisfies(requires, '1.5')).toBe(false)
    })

    it('rejects an invalid panel version', () => {
        expect(satisfies('^1.5', '1.5.0')).toBe(false)
        expect(satisfies('^1.5', 'dev')).toBe(false)
    })
})

describe('API_VERSION', () => {
    it('is MAJOR.MINOR and satisfies its own range', () => {
        expect(API_VERSION).toMatch(/^\d+\.\d+$/)
        expect(satisfies(`^${API_VERSION}`)).toBe(true)
    })

    it('equals Larsadmin::API_VERSION when the PHP constant exists', () => {
        const php = readFileSync(new URL('../../../src/Larsadmin.php', import.meta.url), 'utf8')
        const match = /API_VERSION\s*=\s*'([^']+)'/.exec(php)

        if (match) expect(API_VERSION).toBe(match[1])
    })
})

describe('defineExtension', () => {
    it('returns the options marked as a definition', () => {
        const options = { name: 'acme/larsadmin-rating', requires: '^1.5', fields: {}, setup() {} }

        expect(defineExtension(options)).toBe(options)
        expect(isExtensionDefinition(options)).toBe(true)
        expect(isExtensionDefinition({ ...options })).toBe(false)
    })

    it.each([
        [null, 'expected an options object'],
        [{ requires: '^1.5' }, '"name"'],
        [{ name: 'Acme', requires: '^1.5' }, '"name"'],
        [{ name: 'acme/x' }, '"requires"'],
        [{ name: 'acme/x', requires: '^1.5.0' }, '"requires"'],
        [{ name: 'acme/x', requires: '>=1.5' }, '"requires"'],
        [{ name: 'acme/x', requires: '^1.5', fields: [] }, '"fields"'],
        [{ name: 'acme/x', requires: '^1.5', setup: 'no' }, '"setup"'],
    ])('throws a TypeError naming the extension for %j', (options, message) => {
        expect(() => defineExtension(options)).toThrow(TypeError)
        expect(() => defineExtension(options)).toThrow(message)
    })
})
