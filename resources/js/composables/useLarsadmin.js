import { usePage } from '@inertiajs/vue3'

// Joins URL segments under `base`. Every segment is percent-encoded; `.`/`..` are rejected because
// encodeURIComponent leaves them intact and a browser would resolve them as path traversal.
export function buildUrl(base, segments) {
    const encoded = segments.map((segment) => {
        const value = String(segment)

        if (value === '.' || value === '..') throw new Error(`[larsadmin] Refusing to build a URL with the "${value}" segment.`)

        return encodeURIComponent(value)
    })

    return [base, ...encoded].join('/').replace(/\/{2,}/g, '/')
}

// Appends query params to a URL (`?` or `&`), skipping empty values and keys the URL already carries.
export function withParams(url, params) {
    const [path, hash = ''] = url.split('#')
    const [base, existing = ''] = path.split('?')
    const search = new URLSearchParams(existing)

    for (const [key, value] of Object.entries(params ?? {})) {
        if (value !== null && value !== undefined && value !== '' && !search.has(key)) search.set(key, String(value))
    }

    const query = search.toString()

    return `${base}${query ? `?${query}` : ''}${hash ? `#${hash}` : ''}`
}

export function useLarsadmin() {
    const page = usePage()

    const url = (...segments) => buildUrl(page.props.larsadmin.path, segments)

    const entityUrl = (uriKey, ...segments) => url('entities', uriKey, ...segments)

    // Prefer the server-built `record.urls[name]` (`null` = not allowed, hide the control). Older payloads
    // without `urls` fall back to a locally built URL gated by the `authorizedTo*` flags.
    // Detach has no fallback: its URL is always server-built.
    const recordUrl = (entityKey, record, name) => {
        if (record?.urls && name in record.urls) return record.urls[name] ?? null

        const flag = { show: 'authorizedToView', edit: 'authorizedToUpdate', destroy: 'authorizedToDelete' }[name]

        if (!flag || !record?.[flag]) return null

        return name === 'edit' ? entityUrl(entityKey, record.id, 'edit') : entityUrl(entityKey, record.id)
    }

    return { url, entityUrl, recordUrl }
}
