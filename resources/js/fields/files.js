// Pure helpers for File / Image fields (design 1.1-files §4, §7). No Vue, no Inertia: unit-tested in files.test.js.

const FILE_COMPONENTS = ['file-field', 'image-field']
const UNITS = ['byte', 'kilobyte', 'megabyte', 'gigabyte', 'terabyte']
const RASTER = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']

// 1536 -> "1,5 кБ" (uk) / "1.5 kB" (en). Binary steps (1024), matching Laravel's "max" rule in kilobytes.
export function formatSize(bytes, locale = 'uk') {
    if (bytes === null || bytes === undefined || bytes === '' || !Number.isFinite(Number(bytes))) return ''

    let value = Math.max(0, Number(bytes))
    let unit = 0

    while (value >= 1024 && unit < UNITS.length - 1) {
        value /= 1024
        unit++
    }

    const options = { style: 'unit', unit: UNITS[unit], unitDisplay: 'short', maximumFractionDigits: unit === 0 ? 0 : 1 }

    try {
        return new Intl.NumberFormat(locale, options).format(value)
    } catch {
        return `${Math.round(value * 10) / 10} ${UNITS[unit]}`
    }
}

// Lower-cased last extension of a file name ("Звіт.PDF" -> "pdf"); '' when there is none.
export function extensionOf(name) {
    const base = String(name ?? '').split(/[\\/]/).pop()
    const dot = base.lastIndexOf('.')

    return dot > 0 ? base.slice(dot + 1).toLowerCase() : ''
}

// Client-side type check against the field's extension allow-list (empty list = any type; the server decides).
export function matchesAccept(file, types) {
    if (!Array.isArray(types) || types.length === 0) return true

    const extension = extensionOf(file?.name)

    return extension !== '' && types.map((type) => String(type).toLowerCase().replace(/^\./, '')).includes(extension)
}

export function isRasterImage(file) {
    return RASTER.includes(extensionOf(file?.name)) || /^image\/(jpeg|png|gif|webp|avif)$/.test(file?.type ?? '')
}

const positive = (value) => (typeof value === 'number' && value > 0 ? value : null)

function smallest(...values) {
    const numbers = values.map(positive).filter((value) => value !== null)

    return numbers.length ? Math.min(...numbers) : null
}

// Effective per-file limit in bytes: the field limit, capped by PHP's upload_max_filesize.
export function maxFileSize(field) {
    return smallest(field?.maxSize, field?.uploadLimit?.file)
}

// How many files the field may hold after save (single = 1).
export function maxFileCount(field) {
    return field?.multiple ? positive(field?.maxFiles) : 1
}

/**
 * Splits picked/dropped files into accepted and rejected ones (§7.2). `kept` = number of stored files that stay,
 * `pending` = uploads already queued. Rejections carry an i18n key + params so the caller translates them.
 * Single fields accept one file which replaces the pending one, so `pending` is ignored there.
 */
export function precheck(files, field, { kept = 0, pending = [] } = {}) {
    const accepted = []
    const rejected = []
    const multiple = !!field?.multiple
    const queued = multiple ? [...pending] : []
    const maxSize = maxFileSize(field)
    const maxCount = maxFileCount(field)
    const uploadCount = positive(field?.uploadLimit?.count)
    const requestLimit = positive(field?.uploadLimit?.request)
    const total = () => [...queued, ...accepted].reduce((sum, file) => sum + (file.size ?? 0), 0)

    for (const file of Array.from(files ?? [])) {
        const name = file?.name ?? ''

        if (!matchesAccept(file, field?.types)) {
            rejected.push({ name, key: 'files_type_not_allowed', params: { name } })
        } else if (maxSize !== null && file.size > maxSize) {
            rejected.push({ name, key: 'files_too_large', params: { name, max: maxSize } })
        } else if (!multiple && accepted.length >= 1) {
            rejected.push({ name, key: 'files_too_many', params: { max: 1 } })
        } else if (multiple && maxCount !== null && kept + queued.length + accepted.length >= maxCount) {
            rejected.push({ name, key: 'files_too_many', params: { max: maxCount } })
        } else if (uploadCount !== null && queued.length + accepted.length >= uploadCount) {
            rejected.push({ name, key: 'files_too_many', params: { max: uploadCount } })
        } else if (requestLimit !== null && total() + (file.size ?? 0) > requestLimit) {
            rejected.push({ name, key: 'files_request_too_large', params: { max: requestLimit } })
        } else {
            accepted.push(file)
        }
    }

    return { accepted, rejected }
}

// Field-level message from the Inertia error bag (§4.3): `cover`, then `cover.upload`, `cover.keep`.
export function fieldError(errors, attribute) {
    const bag = errors ?? {}

    return bag[attribute] ?? bag[`${attribute}.upload`] ?? bag[`${attribute}.keep`] ?? bag[`${attribute}.present`] ?? null
}

// Per-upload messages: { 0: '…', 2: '…' } from `cover.upload.0`, `cover.upload.2`.
export function uploadErrors(errors, attribute) {
    const prefix = `${attribute}.upload.`
    const out = {}

    for (const [key, message] of Object.entries(errors ?? {})) {
        if (!key.startsWith(prefix)) continue

        const index = key.slice(prefix.length)

        if (/^\d+$/.test(index)) out[Number(index)] = message
    }

    return out
}

export function isFileField(field) {
    if (FILE_COMPONENTS.includes(field?.component)) return true

    // Custom subclasses with their own component keep the §4.1 value shape.
    const value = field?.value

    return !!value && typeof value === 'object' && 'present' in value && 'upload' in value && Array.isArray(field?.files)
}

export function hasFileFields(fields) {
    return Array.isArray(fields) && fields.some(isFileField)
}

// True when the field's signed / temporary URLs have (almost) expired; `skew` ms early to absorb clock drift.
export function isExpired(field, now = Date.now(), skew = 5000) {
    if (!field?.urlsExpireAt) return false

    const at = Date.parse(field.urlsExpireAt)

    return Number.isFinite(at) && at - skew <= now
}

/**
 * How EntityForm sends its data (§4.2, ADR-014). Forms without file fields: exactly today's request (same method,
 * same data object, JSON). Forms with file fields: multipart POST, updates spoofed with `_method=PUT`; every file
 * field is sent as { present, known, keep, upload } (CRF-1: `known` = tokens the form had at load, sent unchanged,
 * so the server only removes files this form knew about).
 */
export function buildSubmission(fields, method, data) {
    if (!hasFileFields(fields)) return { method, data, multipart: false }

    const verb = String(method ?? 'post').toLowerCase()
    const out = data === null || data === undefined ? data : { ...data }

    if (out) {
        for (const field of fields) {
            if (isFileField(field) && field.attribute in out) out[field.attribute] = fileValue(out[field.attribute], field)
        }
    }

    return {
        method: 'post',
        data: verb === 'post' || !out ? out : { ...out, _method: verb.toUpperCase() },
        multipart: true,
    }
}

// Wire shape of one file field; `known` falls back to the server's initial value when the client lost it.
export function fileValue(value, field) {
    const known = value?.known ?? field?.value?.known
    const out = { present: 1 }

    if (Array.isArray(known)) out.known = [...known]
    out.keep = [...(value?.keep ?? [])]
    out.upload = [...(value?.upload ?? [])]

    return out
}

// Initial §4.2 payload for a file field when the server sent none (defensive; the server always sends `value`).
export function emptyValue(field) {
    const keep = (field?.files ?? []).map((file) => file.token).filter(Boolean)

    return { present: 1, known: [...keep], keep, upload: [] }
}

// Remove / Undo of a stored file: drops the token from `keep`, or restores it in stored order.
export function toggleStored(keep, token, order = []) {
    if (keep.includes(token)) return keep.filter((kept) => kept !== token)

    return [...new Set([...keep, token])].sort((a, b) => order.indexOf(a) - order.indexOf(b))
}

// One toggle button per stored file (CRF-5: the element is patched, so focus stays on it). `removed` = state now.
export function storedToggle(removed, name) {
    return removed
        ? { label: 'files_undo', aria: { key: 'files_undo_named', params: { name } } }
        : { label: 'files_remove', aria: { key: 'files_remove_named', params: { name } } }
}

// Live-region message after toggling a stored file; `removed` = state after the toggle.
export function storedAnnouncement(removed, name) {
    return removed ? { key: 'files_removed_on_save', params: { name } } : { key: 'files_kept', params: { name } }
}

// After a row's action button disappears: the button now at the same position, or -1 for the native input.
export function focusIndexAfterRemoval(position, remaining) {
    return position >= 0 && position < remaining ? position : -1
}
