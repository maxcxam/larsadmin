// Partition payload helpers (CR11-9). `additive: false` (average/min/max) means slices must not be summed:
// the server then sends `total: null` and the donut hides its centre total and percentage shares.
// An older payload without the flag is additive.
export const isAdditive = (result) => result?.additive !== false

export function partitionEmpty(result) {
    const slices = result?.slices ?? []

    if (!slices.length) return true
    if (isAdditive(result)) return !Number(result.total)

    return slices.every((slice) => slice.value === null || slice.value === undefined || !Number(slice.value))
}
