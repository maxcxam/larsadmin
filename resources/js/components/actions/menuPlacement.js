// Where a fixed-position dropdown opens relative to its trigger (CR11-5). It opens below unless the menu
// would cross the viewport bottom and there is more room above; then it opens upwards, anchored by `bottom`.
// Returns CSS px offsets for a `position: fixed` panel aligned to the trigger's right edge.
export function menuPlacement(trigger, menuHeight, viewport, { gap = 4, margin = 8 } = {}) {
    const right = Math.max(viewport.width - trigger.right, margin)
    const below = viewport.height - trigger.bottom - gap - margin
    const above = trigger.top - gap - margin

    if (menuHeight > below && above > below) {
        return { bottom: viewport.height - trigger.top + gap, right, up: true }
    }

    return { top: trigger.bottom + gap, right, up: false }
}
