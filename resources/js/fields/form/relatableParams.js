// Query params for the relatable options endpoint. Action fields have no resource, so they send the action's uriKey.
export function relatableParams({ context, resourceId = null, action = null }) {
    return context === 'action' ? { context, action } : { context, resourceId }
}
