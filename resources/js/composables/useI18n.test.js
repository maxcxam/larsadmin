import { beforeEach, describe, expect, it, vi } from 'vitest'

const props = { larsadmin: { locale: 'uk', translations: {} } }

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props }) }))

const { useI18n } = await import('./useI18n.js')

beforeEach(() => {
    props.larsadmin = { locale: 'uk', translations: { files: ':count файл|:count файли|:count файлів', greet: 'Привіт, :name / :Name / :NAME' } }
})

describe('useI18n', () => {
    it.each([
        [1, '1 файл'],
        [2, '2 файли'],
        [5, '5 файлів'],
        [11, '11 файлів'],
        [21, '21 файл'],
        [22, '22 файли'],
        [25, '25 файлів'],
    ])('picks the Ukrainian plural form for %i', (count, expected) => {
        expect(useI18n().tc('files', count)).toBe(expected)
    })

    it('replaces :name, :Name and :NAME placeholders', () => {
        expect(useI18n().t('greet', { name: 'олег' })).toBe('Привіт, олег / Олег / ОЛЕГ')
    })

    it('falls back to the bundled uk strings and then to the key', () => {
        props.larsadmin.translations = {}
        const { t } = useI18n()

        expect(t('retry')).toBe('Повторити')
        expect(t('no.such.key')).toBe('no.such.key')
    })

    it('flattens nested shared translations and lets them override the fallback', () => {
        props.larsadmin.translations = { act: { cancel: 'Nope' } }

        expect(useI18n().t('act.cancel')).toBe('Nope')
    })

    it('uses the locale of the page for plural rules', () => {
        props.larsadmin = { locale: 'en', translations: { files: ':count file|:count files' } }
        const { tc } = useI18n()

        expect(tc('files', 1)).toBe('1 file')
        expect(tc('files', 2)).toBe('2 files')
    })
})
