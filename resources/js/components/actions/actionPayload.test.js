import { describe, expect, it } from 'vitest'
import { objectToFormData } from '@inertiajs/core'
import { registry } from '../../extensions/registry'
import { actionPayload, fieldContract, fieldMessage } from './actionPayload'

const base = { resources: [1, 2], search: 'x' }

describe('actionPayload (ActionModal fail closed)', () => {
    it('keeps the 1.4 request for built-in fields', () => {
        const fields = [{ component: 'text-field', attribute: 'reason', value: '' }]
        const data = { ...base, reason: 'spam' }

        expect(actionPayload(fields, data)).toEqual({ data, multipart: false, exclude: [] })
        expect(actionPayload(fields, data).data).toBe(data)
    })

    it('drops values of unknown and crashed vendor/name fields', () => {
        registry.register('fields', 'acme/act-crash', { form: { render: () => null } }, { extension: 'acme/act' })
        registry.markCrashed('fields', 'acme/act-crash', 'form')

        const fields = [
            { component: 'text-field', attribute: 'reason' },
            { component: 'nobody/missing', attribute: 'missing' },
            { component: 'acme/act-crash', attribute: 'crashed' },
        ]
        const { data, multipart, exclude } = actionPayload(fields, { ...base, reason: 'r', missing: 'x', crashed: 'y' })

        expect(exclude).toEqual(['missing', 'crashed'])
        expect(data).toEqual({ ...base, reason: 'r' })
        expect(multipart).toBe(false)
    })

    it('switches to multipart for file / multipart fields, without spoofing the method', () => {
        const upload = new File([new Uint8Array(3)], 'a.csv')
        const fields = [
            { component: 'file-field', attribute: 'import', value: { present: 1, keep: [], upload: [] }, files: [] },
            { component: 'nobody/missing', attribute: 'missing', multipart: true },
        ]
        const { data, multipart } = actionPayload(fields, { ...base, import: { present: 1, keep: [], upload: [upload] }, missing: 'x' })
        const keys = [...objectToFormData(data, new FormData(), null, 'indices').keys()]

        expect(multipart).toBe(true)
        expect(data).not.toHaveProperty('_method')
        expect(keys).toContain('import[upload][0]')
        expect(keys).toContain('resources[0]')
        expect(keys.some((key) => key.startsWith('missing'))).toBe(false)
    })
})

describe('fieldContract / fieldMessage (extension fields in the action modal)', () => {
    const errors = { rating: 'Required.', 'tags.0': 'Too long.', 'tags.1': 'Taken.', other: 'x' }

    it('gives the full form contract with context "create" and the action', () => {
        const action = { uriKey: 'rate', entity: 'posts' }

        expect(fieldContract(errors, { attribute: 'tags' }, { describedBy: 'm-tags-error', resourceId: 7, action })).toEqual({
            errors: ['Too long.', 'Taken.'],
            errorsByKey: { 'tags.0': 'Too long.', 'tags.1': 'Taken.' },
            describedBy: 'm-tags-error',
            context: 'create',
            resourceId: 7,
            action,
        })
    })

    it('defaults to no record, no action and no description', () => {
        expect(fieldContract({}, { attribute: 'rating' })).toEqual({ errors: [], errorsByKey: {}, describedBy: null, context: 'create', resourceId: null, action: null })
    })

    it('shows the own error, else the first keyed one', () => {
        expect(fieldMessage(errors, 'rating')).toBe('Required.')
        expect(fieldMessage(errors, 'tags')).toBe('Too long.')
        expect(fieldMessage(errors, 'missing')).toBeNull()
        expect(fieldMessage(undefined, 'rating')).toBeNull()
    })
})
