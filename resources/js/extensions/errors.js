// Which errors switch an extension component off (CRX-01). Vue passes `info` to errorCaptured: a readable string in
// development ("render function", "mounted hook", ...) and https://vuejs.org/error-reference/#runtime-{code} in
// production builds. Only errors after which the component cannot render are fatal: setup, render, the
// create / mount / update lifecycle hooks, component update and a failed async component loader. Event handlers,
// watchers, directive / vnode hooks and unmount hooks are reported, but the component keeps working, so the
// user's input is not dropped.
const FATAL_CODES = new Set(['0', '1', '13', '15', 'bc', 'c', 'bm', 'm', 'bu', 'u', 'sp'])
const FATAL_TEXT = new Set([
    'setup function',
    'render function',
    'async component loader',
    'component update',
    'beforeCreate hook',
    'created hook',
    'beforeMount hook',
    'mounted hook',
    'beforeUpdate hook',
    'updated',
    'serverPrefetch hook',
])

export function isFatalComponentError(info) {
    const text = String(info ?? '')
    const code = /#runtime-([\w]+)$/.exec(text)

    return code ? FATAL_CODES.has(code[1]) : FATAL_TEXT.has(text)
}
