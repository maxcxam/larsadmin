// `<html lang>` is rendered once by the root Blade view (app.blade.php). An Inertia visit swaps only the page, so
// after the in-panel language switch (POST locale -> redirect) the attribute would keep the old language until a
// full reload: screen readers would read the new text with the old voice. Called on every Inertia navigation.
// The panel has no RTL locales, so `dir` is left alone (the Blade view does not set it either).

// Laravel locale ('uk', 'pt_BR') -> BCP 47 tag ('uk', 'pt-BR'), as in app.blade.php; anything unusable -> null.
export function documentLang(locale) {
    if (typeof locale !== 'string') return null

    const tag = locale.trim().replaceAll('_', '-')

    return /^[A-Za-z]{2,8}(-[A-Za-z0-9]{1,8})*$/.test(tag) ? tag : null
}

export function syncDocumentLocale(page, root = typeof document === 'undefined' ? null : document.documentElement) {
    const lang = documentLang(page?.props?.larsadmin?.locale)

    if (!root || !lang || root.getAttribute('lang') === lang) return

    root.setAttribute('lang', lang)
}
