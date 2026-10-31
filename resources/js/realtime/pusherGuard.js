// Imported by echo.js BEFORE pusher-js: ES modules evaluate in import order, so this snapshot is taken
// before pusher-js runs its web runtime setup, which assigns `window.Pusher` as a side effect.
// echo.js then calls restoreWindowPusher(), so the page's window.Pusher is left exactly as it was.
const hasWindow = typeof window !== 'undefined'
const had = hasWindow && Object.prototype.hasOwnProperty.call(window, 'Pusher')
const previous = had ? window.Pusher : undefined

export function restoreWindowPusher() {
    if (!hasWindow) return

    if (had) window.Pusher = previous
    else delete window.Pusher
}
