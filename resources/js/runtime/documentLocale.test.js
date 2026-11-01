// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { documentLang, syncDocumentLocale } from './documentLocale.js'

const pageIn = (locale) => ({ props: { larsadmin: { locale } } })

afterEach(() => {
    document.documentElement.setAttribute('lang', 'uk')
})

describe('documentLang', () => {
    it.each([
        ['uk', 'uk'],
        ['en', 'en'],
        ['pt_BR', 'pt-BR'],
        ['zh-Hant', 'zh-Hant'],
        ['', null],
        ['not a locale', null],
        ['"><script>', null],
        [null, null],
        [undefined, null],
    ])('%j -> %j', (locale, expected) => {
        expect(documentLang(locale)).toBe(expected)
    })
})

describe('syncDocumentLocale (1.6.4)', () => {
    it('follows the panel locale after the in-panel language switch', () => {
        document.documentElement.setAttribute('lang', 'uk')

        syncDocumentLocale(pageIn('en'))
        expect(document.documentElement.getAttribute('lang')).toBe('en')

        syncDocumentLocale(pageIn('uk'))
        expect(document.documentElement.getAttribute('lang')).toBe('uk')
    })

    it('uses the BCP 47 form, as the Blade view does', () => {
        syncDocumentLocale(pageIn('pt_BR'))

        expect(document.documentElement.getAttribute('lang')).toBe('pt-BR')
    })

    it('keeps the attribute when the page carries no usable locale', () => {
        document.documentElement.setAttribute('lang', 'uk')

        syncDocumentLocale({ props: {} })
        syncDocumentLocale(null)
        syncDocumentLocale(pageIn('bad value'))

        expect(document.documentElement.getAttribute('lang')).toBe('uk')
    })

    it('leaves dir alone (no RTL locales)', () => {
        document.documentElement.removeAttribute('dir')

        syncDocumentLocale(pageIn('en'))

        expect(document.documentElement.hasAttribute('dir')).toBe(false)
    })
})
