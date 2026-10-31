import { describe, expect, it } from 'vitest'
import { entryImportErrors } from './noEntryImports.js'

const chunk = (fileName, props = {}) => ({ type: 'chunk', fileName, isEntry: false, imports: [], dynamicImports: [], ...props })

describe('entryImportErrors', () => {
    it('passes when lazy chunks import only the shared chunk', () => {
        const bundle = {
            'app.js': chunk('app.js', { isEntry: true, imports: ['chunks/shared-a.js'], dynamicImports: ['chunks/echo-b.js'] }),
            'chunks/shared-a.js': chunk('chunks/shared-a.js'),
            'chunks/echo-b.js': chunk('chunks/echo-b.js', { imports: ['chunks/shared-a.js'] }),
            'app.css': { type: 'asset', fileName: 'app.css' },
        }

        expect(entryImportErrors(bundle)).toEqual([])
    })

    it('fails when a lazy chunk imports the entry', () => {
        const bundle = {
            'app.js': chunk('app.js', { isEntry: true, dynamicImports: ['chunks/NotificationPanel-c.js'] }),
            'chunks/NotificationPanel-c.js': chunk('chunks/NotificationPanel-c.js', { imports: ['app.js'] }),
        }

        expect(entryImportErrors(bundle)).toEqual([
            'chunks/NotificationPanel-c.js imports the entry app.js; chunks must only import shared chunks.',
        ])
    })
})
