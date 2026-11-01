// A visit whose request never gets a response (connection lost, server down, proxy failure) ends in Inertia's
// `networkError` event; unless the event is cancelled Inertia rejects the visit, which surfaces as an uncaught
// "Network error" while the user sees nothing. The panel cancels it and shows its error toast instead. The visit
// still finishes, so useForm resets `processing` and keeps the entered data: the user can simply retry.
// A visit that handles the error itself (`onNetworkError` returning false) never reaches this listener.
//
// Returns the function that removes the listener.
export function handleNetworkErrors(router, { notify }) {
    return router.on('networkError', (event) => {
        event.preventDefault()

        try {
            notify(event.detail?.error ?? null)
        } catch (error) {
            console.error('[larsadmin] Could not show the network error toast', error)
        }
    })
}
