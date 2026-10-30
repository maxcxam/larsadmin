// Pure helpers for FieldBlocks (ADR-009). The server sends an attribute-keyed layout tree; a payload without
// one falls back to a single implicit panel holding every non-relation field.

const implicitPanel = (attributes) => ({
    type: 'panel',
    key: 'p0',
    name: null,
    description: null,
    collapsible: false,
    collapsed: false,
    columns: 1,
    fields: attributes,
})

export function normalizeLayout(layout, fields) {
    const inline = fields.filter((field) => !field.panel)

    if (!Array.isArray(layout) || layout.length === 0) {
        return inline.length ? [implicitPanel(inline.map((field) => field.attribute))] : []
    }

    return layout
}

export function nodeAttributes(node) {
    return node.type === 'tabs' ? node.tabs.flatMap((tab) => tab.fields) : node.fields
}

export function hasError(attribute, errors) {
    return Object.keys(errors ?? {}).some((key) => key === attribute || key.startsWith(`${attribute}.`))
}

export function errorCount(attributes, errors) {
    return attributes.filter((attribute) => hasError(attribute, errors)).length
}

// Index of the first tab holding an error, or -1.
export function firstTabWithErrors(tabs, errors) {
    return tabs.findIndex((tab) => errorCount(tab.fields, errors) > 0)
}
