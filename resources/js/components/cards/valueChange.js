// Direction of a value metric's change vs the previous period.
// The percentage is shown with one fraction digit, so anything that rounds to 0.0 counts as "no change".
// Returns 'up' | 'down' | 'none', or null when there is no comparison (change null/undefined/NaN).
export function changeDirection(change) {
    if (change === null || change === undefined || change === '') return null

    const value = Number(change)

    if (Number.isNaN(value)) return null
    if (Math.round(Math.abs(value) * 10) === 0) return 'none'

    return value > 0 ? 'up' : 'down'
}
