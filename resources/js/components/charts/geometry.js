// Pure chart helpers (no DOM): scales, ticks, paths and donut arcs.

export function scaleLinear([d0, d1], [r0, r1]) {
    const span = d1 - d0

    return (value) => (span === 0 ? (r0 + r1) / 2 : r0 + ((value - d0) / span) * (r1 - r0))
}

// "Nice" axis ticks covering [min, max]. Returns { ticks, min, max } (min/max = outer ticks).
export function niceTicks(min, max, count = 4) {
    if (!Number.isFinite(min) || !Number.isFinite(max)) return { ticks: [0, 1], min: 0, max: 1 }

    if (min === max) {
        if (min === 0) return { ticks: [0, 1], min: 0, max: 1 }

        const pad = Math.abs(min) * 0.1 || 1
        min -= pad
        max += pad
    }

    const rough = (max - min) / Math.max(1, count)
    const magnitude = 10 ** Math.floor(Math.log10(rough))
    const residual = rough / magnitude
    const step = (residual <= 1 ? 1 : residual <= 2 ? 2 : residual <= 5 ? 5 : 10) * magnitude

    const start = Math.floor(min / step + 1e-9) * step
    const end = Math.ceil(max / step - 1e-9) * step
    const ticks = []

    for (let v = start; v <= end + step / 2; v += step) ticks.push(Number(v.toPrecision(12)))

    return { ticks, min: ticks[0], max: ticks[ticks.length - 1] }
}

// Domain for a series: includes 0 so areas keep their baseline.
export function valueDomain(values, count = 4) {
    const nums = values.filter((v) => typeof v === 'number' && Number.isFinite(v))

    if (!nums.length) return niceTicks(0, 1, count)

    return niceTicks(Math.min(0, ...nums), Math.max(0, ...nums), count)
}

const round = (n) => Math.round(n * 100) / 100

function segments(coords) {
    const out = []
    let current = []

    for (const c of coords) {
        if (c.y === null || c.y === undefined || Number.isNaN(c.y)) {
            if (current.length) out.push(current)
            current = []
        } else current.push(c)
    }

    if (current.length) out.push(current)

    return out
}

const top = (seg) => seg.map((c, i) => `${i ? 'L' : 'M'}${round(c.x)} ${round(c.y)}`).join(' ')

// Path of a polyline over [{x, y}]; entries with y === null break the line into segments.
export function linePath(coords) {
    return segments(coords).map(top).join(' ')
}

// Closed area under each segment down to `baseline` (y).
export function areaPath(coords, baseline) {
    return segments(coords)
        .map((seg) => `${top(seg)} L${round(seg[seg.length - 1].x)} ${round(baseline)} L${round(seg[0].x)} ${round(baseline)} Z`)
        .join(' ')
}

// Evenly spread label indices (always first and last), at most `max`.
export function labelIndices(length, max = 5) {
    if (length <= 0) return []
    if (length <= max) return Array.from({ length }, (_, i) => i)

    const picked = new Set()

    for (let i = 0; i < max; i++) picked.add(Math.round((i * (length - 1)) / (max - 1)))

    return [...picked]
}

// Nearest point index for a pointer at fraction `fx` (0..1) across `length` evenly spaced points.
export function nearestIndex(fx, length) {
    if (length <= 1) return 0

    return Math.min(length - 1, Math.max(0, Math.round(fx * (length - 1))))
}

// Donut arcs for a circle with circumference 100 (r = 100 / 2π), drawn with stroke-dasharray.
// Returns [{ index, value, fraction, length, offset, dasharray, dashoffset }]; zero values are skipped.
export function donutSegments(values, gap = 0.6) {
    const nums = values.map((v) => (Number.isFinite(v) && v > 0 ? v : 0))
    const total = nums.reduce((a, b) => a + b, 0)

    if (total === 0) return []

    const g = nums.filter(Boolean).length > 1 ? gap : 0
    let offset = 0

    return nums.flatMap((value, index) => {
        if (!value) return []

        const fraction = value / total
        const length = fraction * 100
        const visible = Math.max(0, length - g)
        const segment = {
            index,
            value,
            fraction,
            length,
            offset,
            dasharray: `${visible} ${100 - visible}`,
            dashoffset: -offset,
        }

        offset += length

        return [segment]
    })
}
