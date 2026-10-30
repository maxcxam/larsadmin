// Colours from the server (PartitionResult::colors()) are bound to `stroke`/`style`. Only plain colour values pass
// (SEC11-6): #hex, rgb()/rgba(), hsl()/hsla(), oklch() and var(--token). Anything else (url(), expressions,
// nested functions) falls back to the palette, so a colour taken from user data cannot trigger a request.
const HEX = /^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i
const FUNCTION = /^(?:rgba?|hsla?|oklch)\([0-9a-z.%\s,/+-]*\)$/i
const VARIABLE = /^var\(\s*--[a-z0-9_-]+\s*\)$/i

export function isSafeColor(value) {
    if (typeof value !== 'string') return false

    const color = value.trim()

    return color.length > 0 && color.length <= 64 && (HEX.test(color) || FUNCTION.test(color) || VARIABLE.test(color))
}

// The palette colour for a series index: --la-chart-1..8, cycling.
export const paletteColor = (index) => `var(--la-chart-${(index % 8) + 1})`

export function seriesColor(value, index) {
    return isSafeColor(value) ? value.trim() : paletteColor(index)
}
