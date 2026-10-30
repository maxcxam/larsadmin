import { describe, expect, it } from 'vitest'
import { objectToFormData } from '@inertiajs/core'
import {
    buildSubmission,
    emptyValue,
    extensionOf,
    fieldError,
    fileValue,
    focusIndexAfterRemoval,
    formatSize,
    hasFileFields,
    isExpired,
    isRasterImage,
    matchesAccept,
    maxFileSize,
    precheck,
    storedAnnouncement,
    storedToggle,
    toggleStored,
    uploadErrors,
} from './files'

const file = (name, size = 10, type = '') => new File([new Uint8Array(size)], name, { type })

const imageField = {
    component: 'image-field',
    attribute: 'cover',
    multiple: false,
    types: ['jpg', 'jpeg', 'png'],
    maxSize: 1024 * 1024,
    maxFiles: 1,
    uploadLimit: { file: 2 * 1024 * 1024, request: 8 * 1024 * 1024, count: 20 },
    value: { present: 1, keep: ['a'.repeat(32)], upload: [] },
    files: [],
}

const multiField = {
    component: 'file-field',
    attribute: 'attachments',
    multiple: true,
    types: [],
    maxSize: 5000,
    maxFiles: 3,
    uploadLimit: { file: null, request: 12000, count: 2 },
    value: { present: 1, keep: [], upload: [] },
    files: [],
}

describe('formatSize', () => {
    it('formats bytes with binary steps per locale', () => {
        expect(formatSize(512, 'en')).toBe('512 byte')
        expect(formatSize(1536, 'en')).toBe('1.5 kB')
        expect(formatSize(5 * 1024 * 1024, 'en')).toBe('5 MB')
        expect(formatSize(1536, 'uk')).toMatch(/^1,5\s?кБ$/)
    })
    it('returns an empty string for unknown sizes', () => {
        expect(formatSize(null)).toBe('')
        expect(formatSize(undefined)).toBe('')
        expect(formatSize('abc')).toBe('')
    })
})

describe('matchesAccept / extensionOf', () => {
    it('matches by the last lower-cased extension', () => {
        expect(extensionOf('Звіт.PDF')).toBe('pdf')
        expect(extensionOf('archive.tar.gz')).toBe('gz')
        expect(extensionOf('.htaccess')).toBe('')
        expect(extensionOf('README')).toBe('')
        expect(matchesAccept(file('photo.JPG'), ['jpg', 'png'])).toBe(true)
        expect(matchesAccept(file('photo.svg'), ['jpg', 'png'])).toBe(false)
        expect(matchesAccept(file('noext'), ['jpg'])).toBe(false)
        expect(matchesAccept(file('x.php.jpg'), ['.jpg'])).toBe(true)
    })
    it('accepts anything when the field has no allow-list', () => {
        expect(matchesAccept(file('a.bin'), [])).toBe(true)
        expect(matchesAccept(file('a.bin'), undefined)).toBe(true)
    })
    it('detects raster images by extension or type', () => {
        expect(isRasterImage(file('a.webp'))).toBe(true)
        expect(isRasterImage(file('a', 1, 'image/png'))).toBe(true)
        expect(isRasterImage(file('a.svg', 1, 'image/svg+xml'))).toBe(false)
    })
})

describe('precheck', () => {
    it('caps the size by the PHP upload limit', () => {
        expect(maxFileSize({ maxSize: 10, uploadLimit: { file: 5 } })).toBe(5)
        expect(maxFileSize({ maxSize: 10, uploadLimit: { file: null } })).toBe(10)
        expect(maxFileSize({})).toBe(null)
    })
    it('rejects wrong types and oversize files with a reason', () => {
        const { accepted, rejected } = precheck([file('a.gif'), file('b.png', 2 * 1024 * 1024), file('c.png')], imageField)

        expect(accepted.map((f) => f.name)).toEqual(['c.png'])
        expect(rejected).toEqual([
            { name: 'a.gif', key: 'files_type_not_allowed', params: { name: 'a.gif' } },
            { name: 'b.png', key: 'files_too_large', params: { name: 'b.png', max: 1024 * 1024 } },
        ])
    })
    it('accepts one file for single fields', () => {
        const { accepted, rejected } = precheck([file('a.png'), file('b.png')], imageField, { kept: 1, pending: [file('old.png')] })

        expect(accepted.map((f) => f.name)).toEqual(['a.png'])
        expect(rejected).toEqual([{ name: 'b.png', key: 'files_too_many', params: { max: 1 } }])
    })
    it('enforces maxFiles against kept + pending files', () => {
        const { accepted, rejected } = precheck([file('a'), file('b')], multiField, { kept: 2, pending: [] })

        expect(accepted.map((f) => f.name)).toEqual(['a'])
        expect(rejected[0]).toMatchObject({ name: 'b', key: 'files_too_many', params: { max: 3 } })
    })
    it('enforces the PHP max_file_uploads count', () => {
        const { accepted, rejected } = precheck([file('a'), file('b')], multiField, { kept: 0, pending: [file('p')] })

        expect(accepted.map((f) => f.name)).toEqual(['a'])
        expect(rejected[0]).toMatchObject({ name: 'b', key: 'files_too_many', params: { max: 2 } })
    })
    it('enforces the total request size', () => {
        const { accepted, rejected } = precheck([file('a', 4000), file('b', 4000)], { ...multiField, uploadLimit: { request: 10000 } }, { pending: [file('p', 4000)] })

        expect(accepted.map((f) => f.name)).toEqual(['a'])
        expect(rejected[0]).toMatchObject({ name: 'b', key: 'files_request_too_large', params: { max: 10000 } })
    })
})

describe('error mapping', () => {
    const errors = {
        title: 'Title is required.',
        cover: 'Cover is required.',
        'attachments.upload': 'Too many uploads.',
        'attachments.upload.0': 'First file too large.',
        'attachments.upload.2': 'Third file wrong type.',
        'gallery.keep': 'Bad keep.',
    }

    it('picks the field-level message', () => {
        expect(fieldError(errors, 'title')).toBe('Title is required.')
        expect(fieldError(errors, 'cover')).toBe('Cover is required.')
        expect(fieldError(errors, 'attachments')).toBe('Too many uploads.')
        expect(fieldError(errors, 'gallery')).toBe('Bad keep.')
        expect(fieldError(errors, 'missing')).toBe(null)
        expect(fieldError(undefined, 'x')).toBe(null)
    })
    it('maps per-upload messages by index', () => {
        expect(uploadErrors(errors, 'attachments')).toEqual({ 0: 'First file too large.', 2: 'Third file wrong type.' })
        expect(uploadErrors(errors, 'cover')).toEqual({})
    })
})

describe('isExpired', () => {
    const now = Date.parse('2026-10-30T12:00:00Z')

    it('is false for public files and future expiry', () => {
        expect(isExpired({ urlsExpireAt: null }, now)).toBe(false)
        expect(isExpired({ urlsExpireAt: '2026-10-30T12:30:00+00:00' }, now)).toBe(false)
    })
    it('is true once the expiry (minus skew) has passed', () => {
        expect(isExpired({ urlsExpireAt: '2026-10-30T11:59:00+00:00' }, now)).toBe(true)
        expect(isExpired({ urlsExpireAt: '2026-10-30T12:00:03+00:00' }, now)).toBe(true)
    })
})

describe('buildSubmission (EntityForm transport)', () => {
    const textFields = [
        { component: 'text-field', attribute: 'title', value: 'A' },
        { component: 'boolean-field', attribute: 'published', value: true },
    ]

    it('keeps forms without file fields on the unchanged JSON request', () => {
        const data = { title: 'A', published: true }

        for (const method of ['post', 'put']) {
            const submission = buildSubmission(textFields, method, data)

            expect(submission.multipart).toBe(false)
            expect(submission.method).toBe(method)
            expect(submission.data).toBe(data)
            expect(JSON.stringify(submission.data)).toBe('{"title":"A","published":true}')
        }
        expect(hasFileFields(textFields)).toBe(false)
        expect(hasFileFields([])).toBe(false)
    })

    it('sends file forms as multipart POST, spoofing PUT for updates', () => {
        const fields = [...textFields, imageField]
        const data = { title: 'A', published: true, cover: imageField.value }

        expect(hasFileFields(fields)).toBe(true)
        expect(buildSubmission(fields, 'post', data)).toEqual({ method: 'post', data, multipart: true })

        const update = buildSubmission(fields, 'put', data)

        expect(update.method).toBe('post')
        expect(update.data).toEqual({ ...data, _method: 'PUT' })
        expect(data).not.toHaveProperty('_method')
    })

    it('produces the §4.2 wire format through Inertia FormData encoding', () => {
        const upload = file('sunset2.jpg', 3, 'image/jpeg')
        const fields = [...textFields, imageField, multiField]
        const data = {
            title: 'Новий допис',
            published: false,
            subtitle: null,
            cover: { present: 1, known: ['c0'], keep: [], upload: [upload] },
            attachments: { present: 1, known: ['1f', '9a', '7c'], keep: ['1f', '9a'], upload: [] },
        }
        // Inertia v3 default: form.forceIndicesArrayFormatInFormData = true -> "indices".
        const form = objectToFormData(buildSubmission(fields, 'put', data).data, new FormData(), null, 'indices')
        const entries = [...form.entries()].map(([key, value]) => [key, value instanceof File ? `<file ${value.name}>` : value])

        expect(entries).toEqual([
            ['title', 'Новий допис'],
            ['published', '0'],
            ['subtitle', ''],
            ['cover[present]', '1'],
            ['cover[known][0]', 'c0'],
            ['cover[upload][0]', '<file sunset2.jpg>'],
            ['attachments[present]', '1'],
            ['attachments[known][0]', '1f'],
            ['attachments[known][1]', '9a'],
            ['attachments[known][2]', '7c'],
            ['attachments[keep][0]', '1f'],
            ['attachments[keep][1]', '9a'],
            ['_method', 'PUT'],
        ])
    })

    it('sends value.known unchanged, falling back to the server value (CRF-1)', () => {
        const field = { ...multiField, value: { present: 1, known: ['1f', '9a'], keep: ['1f', '9a'], upload: [] } }
        const lost = buildSubmission([field], 'put', { attachments: { present: 1, keep: [], upload: [] } })

        expect(lost.data.attachments).toEqual({ present: 1, known: ['1f', '9a'], keep: [], upload: [] })
        expect(fileValue({ present: 1, known: ['x'], keep: ['x'], upload: [] }, field)).toEqual({ present: 1, known: ['x'], keep: ['x'], upload: [] })
        // Create forms have no `known`: nothing extra is sent.
        expect(fileValue({ present: 1, keep: [], upload: [] }, multiField)).toEqual({ present: 1, keep: [], upload: [] })
    })

    it('recognizes custom file components by their value shape', () => {
        expect(hasFileFields([{ component: 'avatar-field', value: { present: 1, keep: [], upload: [] }, files: [] }])).toBe(true)
        expect(hasFileFields([{ component: 'json-field', value: { present: 1 } }])).toBe(false)
    })
})

describe('emptyValue', () => {
    it('keeps every stored token', () => {
        expect(emptyValue({ files: [{ token: 'a' }, { token: 'b' }] })).toEqual({ present: 1, known: ['a', 'b'], keep: ['a', 'b'], upload: [] })
        expect(emptyValue({})).toEqual({ present: 1, known: [], keep: [], upload: [] })
    })
})

describe('stored file toggle (CRF-5)', () => {
    const order = ['a', 'b', 'c']

    it('removes and restores tokens in stored order', () => {
        expect(toggleStored(['a', 'b', 'c'], 'b', order)).toEqual(['a', 'c'])
        expect(toggleStored(['a', 'c'], 'b', order)).toEqual(['a', 'b', 'c'])
        expect(toggleStored(['c'], 'a', order)).toEqual(['a', 'c'])
    })
    it('labels one button by state, naming the file', () => {
        expect(storedToggle(false, 'x.pdf')).toEqual({ label: 'files_remove', aria: { key: 'files_remove_named', params: { name: 'x.pdf' } } })
        expect(storedToggle(true, 'x.pdf')).toEqual({ label: 'files_undo', aria: { key: 'files_undo_named', params: { name: 'x.pdf' } } })
    })
    it('announces both removal and undo', () => {
        expect(storedAnnouncement(true, 'x.pdf')).toEqual({ key: 'files_removed_on_save', params: { name: 'x.pdf' } })
        expect(storedAnnouncement(false, 'x.pdf')).toEqual({ key: 'files_kept', params: { name: 'x.pdf' } })
    })
})

describe('focusIndexAfterRemoval', () => {
    it('focuses the button now at the same position, else the input', () => {
        expect(focusIndexAfterRemoval(1, 3)).toBe(1)
        expect(focusIndexAfterRemoval(2, 2)).toBe(-1)
        expect(focusIndexAfterRemoval(0, 0)).toBe(-1)
        expect(focusIndexAfterRemoval(-1, 3)).toBe(-1)
    })
})
