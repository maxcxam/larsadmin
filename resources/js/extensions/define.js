// defineExtension() (design 1.5 §5.2): validates the shape of an extension's default export and marks it, so the
// loader can tell a real definition from an arbitrary module. Registration itself is done by the loader.
// Symbol.for: the marker is the same in the panel runtime and in kit/sdk.js (authors' vitest).
export const EXTENSION = Symbol.for('larsadmin.extension')

const NAME = /^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/
const MAPS = ['fields', 'cards', 'filters', 'tools']

const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)

export function defineExtension(options) {
    const label = isPlainObject(options) && typeof options.name === 'string' ? options.name : '(unnamed)'
    const fail = (message) => {
        throw new TypeError(`[larsadmin] defineExtension "${label}": ${message}`)
    }

    if (!isPlainObject(options)) fail('expected an options object.')
    if (typeof options.name !== 'string' || !NAME.test(options.name)) fail('"name" must be the Composer package name (vendor/package).')
    // Only ^MAJOR.MINOR is supported (ADR-034); anything else would only fail later as "incompatible" (CRX-09).
    if (typeof options.requires !== 'string' || !/^\^\d+\.\d+$/.test(options.requires.trim())) fail('"requires" must be an API range of the form "^MAJOR.MINOR", such as "^1.5".')

    for (const map of MAPS) {
        if (options[map] !== undefined && !isPlainObject(options[map])) fail(`"${map}" must be an object keyed by component key.`)
    }

    if (options.setup !== undefined && typeof options.setup !== 'function') fail('"setup" must be a function.')

    Object.defineProperty(options, EXTENSION, { value: true })

    return options
}

export function isExtensionDefinition(value) {
    return isPlainObject(value) && value[EXTENSION] === true
}
