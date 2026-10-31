// The only module that imports laravel-echo and pusher-js. realtime.js loads it with import(), so Vite puts
// both libraries into a separate chunk that is fetched only when the server enabled realtime.
// The Echo instance is local: Pusher is passed explicitly (Echo never falls back to window.Pusher),
// `withoutInterceptors` keeps Echo away from any global axios / jQuery / Turbo, and window.Echo is never
// set. pusher-js assigns window.Pusher while it loads; pusherGuard (imported first) undoes that.
import { restoreWindowPusher } from './pusherGuard.js'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

restoreWindowPusher()

export function createEcho(options) {
    return new Echo({ ...options, Pusher, withoutInterceptors: true })
}
