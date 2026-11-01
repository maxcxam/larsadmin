import { beforeEach, describe, expect, it, vi } from 'vitest'

const props = { larsadmin: { locale: 'uk', translations: {} } }

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props }) }))

const { useI18n, replace } = await import('./useI18n.js')

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

    it('resolves extension translations as namespace::key', () => {
        props.larsadmin = {
            locale: 'uk',
            translations: { retry: 'Ще раз' },
            extensionTranslations: { 'acme-rating': { stars: ':count зірка|:count зірки|:count зірок', group: { title: 'Рейтинг' } } },
        }
        const { t, tc } = useI18n()

        expect(t('acme-rating::group.title')).toBe('Рейтинг')
        expect(tc('acme-rating::stars', 3)).toBe('3 зірки')
        expect(t('retry')).toBe('Ще раз')
        expect(t('acme-rating::missing')).toBe('acme-rating::missing')
    })

    it('ignores a null extensionTranslations prop', () => {
        props.larsadmin = { locale: 'uk', translations: {}, extensionTranslations: null }

        expect(useI18n().t('retry')).toBe('Повторити')
    })
})

describe('replace', () => {
    it('returns the message unchanged without params (the network error toast passed none in 1.6.4)', () => {
        expect(replace('Немає звʼязку', undefined)).toBe('Немає звʼязку')
        expect(replace('Немає звʼязку', null)).toBe('Немає звʼязку')
    })
})
