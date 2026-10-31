import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'

// Runs the checker against a copy of the package so the field form contract (design 1.5 §5.4, 1.6 §8.3, review
// CR6-04) can be broken without touching the real sources.
const PKG = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const COPIED = ['kit', 'src', 'resources/js', 'dist']

let copy

function check() {
    return spawnSync(process.execPath, [join(copy, 'kit/check-public-api.mjs')], { cwd: copy, encoding: 'utf8' })
}

function edit(file, from, to) {
    const path = join(copy, file)
    const source = readFileSync(path, 'utf8')
    expect(source).toContain(from)
    writeFileSync(path, source.replace(from, to))
    return () => writeFileSync(path, source)
}

describe('check-public-api: field form contract', () => {
    beforeAll(() => {
        copy = mkdtempSync(join(tmpdir(), 'larsadmin-api-'))
        for (const dir of COPIED) cpSync(join(PKG, dir), join(copy, dir), { recursive: true })
    })

    afterAll(() => rmSync(copy, { recursive: true, force: true }))

    it('snapshots the props extension field components receive', () => {
        const { fieldContract } = JSON.parse(readFileSync(join(PKG, 'kit/public-api.json'), 'utf8'))
        const form = ['context', 'describedBy', 'errors', 'errorsByKey', 'field', 'invalid', 'modelValue', 'resourceId']

        expect(fieldContract['form/entity']).toEqual(expect.arrayContaining(form))
        expect(fieldContract['form/action']).toEqual(expect.arrayContaining([...form, 'action']))
        expect(fieldContract.field).toContain('inAction')
    })

    it('passes on the unmodified package', () => {
        const result = check()

        expect(result.stderr).toBe('')
        expect(result.status).toBe(0)
    })

    it.each([
        ['the action prop of an action modal', 'resources/js/components/actions/actionPayload.js', '        action,\n', '', 'field contract form/action', 'prop "action" was removed'],
        ['describedBy of an action modal', 'resources/js/components/actions/actionPayload.js', '        describedBy,\n', '', 'field contract form/action', 'prop "describedBy" was removed'],
        ['errorsByKey of the entity form', 'resources/js/components/EntityForm.vue', 'errorsByKey: fieldErrorsByKey(form.errors, field.attribute), ', '', 'field contract form/entity', 'prop "errorsByKey" was removed'],
        ['resourceId of the entity form', 'resources/js/components/EntityForm.vue', ':resource-id="resourceId"', '', 'field contract form/entity', 'prop "resourceId" was removed'],
        ['field.inAction', 'src/Fields/Field.php', "['inAction' => true]", '[]', 'field contract (Field::jsonSerialize())', 'serialized field key "inAction" was removed'],
    ])('fails when %s disappears', (_, file, from, to, where, message) => {
        const restore = edit(file, from, to)
        try {
            const result = check()

            expect(result.status).toBe(1)
            expect(result.stderr).toContain(where)
            expect(result.stderr).toContain(message)
        } finally {
            restore()
        }
    })

    it('fails when a prop is added without a snapshot', () => {
        const restore = edit('resources/js/components/actions/actionPayload.js', '        action,\n', '        action,\n        extra: null,\n')
        try {
            const result = check()

            expect(result.status).toBe(1)
            expect(result.stderr).toContain('"extra" not in kit/public-api.json')
        } finally {
            restore()
        }
    })
})
