// Whether a sidebar dashboard link is the current page (CR11-14). The server names the shown dashboard in
// `page.props.dashboard.uriKey`, so the home route and /dashboards/{homeKey} both match the home entry,
// and a hidden home dashboard never lights up another entry.
export function isDashboardActive(dashboard, page) {
    if (page?.component !== 'Dashboard') return false

    const current = page.props?.dashboard?.uriKey

    return current !== undefined && current !== null && dashboard?.uriKey === current
}

// Whether a sidebar tool link is the current page (design 1.5 §4.5): the server renders the `Tool` page with the
// shown tool's uriKey in `page.props.tool.uriKey`.
export function isToolActive(tool, page) {
    if (page?.component !== 'Tool') return false

    const current = page.props?.tool?.uriKey

    return current !== undefined && current !== null && tool?.uriKey === current
}

// Sidebar entries for tools from the shared prop `tools` = [{ name, uriKey, icon, url }]. Entries without a URL or
// a name are skipped; an icon outside the allow-list falls back to the default one.
export function toolLinks(tools, names, fallbackIcon = 'file') {
    if (!Array.isArray(tools)) return []

    return tools
        .filter((tool) => tool && typeof tool.url === 'string' && tool.url !== '' && typeof tool.name === 'string')
        .map((tool) => ({ ...tool, icon: names.includes(tool.icon) ? tool.icon : fallbackIcon }))
}
