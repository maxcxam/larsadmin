// Whether a sidebar dashboard link is the current page (CR11-14). The server names the shown dashboard in
// `page.props.dashboard.uriKey`, so the home route and /dashboards/{homeKey} both match the home entry,
// and a hidden home dashboard never lights up another entry.
export function isDashboardActive(dashboard, page) {
    if (page?.component !== 'Dashboard') return false

    const current = page.props?.dashboard?.uriKey

    return current !== undefined && current !== null && dashboard?.uriKey === current
}
