<script setup>
import { computed, ref, useId } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { areaPath, labelIndices, linePath, nearestIndex, valueDomain } from './geometry'

const props = defineProps({
    points: { type: Array, required: true }, // [{ label, value }]
    format: { type: Function, default: (v) => String(v) },
    title: { type: String, default: '' },
})

const { t } = useI18n()
const uid = useId()
const active = ref(null)
const plot = ref(null)

const domain = computed(() => valueDomain(props.points.map((p) => p.value)))
const n = computed(() => props.points.length)

const xPct = (i) => (n.value <= 1 ? 50 : (i / (n.value - 1)) * 100)
const yPct = (v) => {
    const { min, max } = domain.value

    return 100 - ((v - min) / (max - min || 1)) * 100
}

const coords = computed(() => props.points.map((p, i) => ({ x: xPct(i), y: p.value === null || p.value === undefined ? null : yPct(p.value) })))
const line = computed(() => linePath(coords.value))
const area = computed(() => areaPath(coords.value, yPct(Math.max(0, domain.value.min))))
const xLabels = computed(() => labelIndices(n.value, 5))
const activePoint = computed(() => (active.value === null ? null : props.points[active.value]))
const activeCoord = computed(() => (active.value === null ? null : coords.value[active.value]))

const align = (i) => (i === 0 && n.value > 1 ? '0' : i === n.value - 1 && n.value > 1 ? '-100%' : '-50%')

function onMove(event) {
    const rect = plot.value.getBoundingClientRect()

    if (!rect.width) return

    active.value = nearestIndex((event.clientX - rect.left) / rect.width, n.value)
}

function onKey(event) {
    const last = n.value - 1
    const current = active.value ?? (event.key === 'ArrowLeft' ? last + 1 : -1)
    const next = { ArrowRight: current + 1, ArrowLeft: current - 1, Home: 0, End: last }[event.key]

    if (next === undefined) return

    event.preventDefault()
    active.value = Math.min(last, Math.max(0, next))
}

function onFocus() {
    if (active.value === null && n.value) active.value = n.value - 1
}

const summary = computed(() => (activePoint.value ? `${activePoint.value.label}: ${props.format(activePoint.value.value)}` : ''))
</script>

<template>
    <div class="flex gap-2">
        <div class="relative h-44 w-12 shrink-0 text-right text-xs text-fg-muted" aria-hidden="true">
            <span
                v-for="tick in domain.ticks"
                :key="tick"
                class="absolute right-0 -translate-y-1/2 tabular-nums"
                :style="{ top: `${yPct(tick)}%` }"
            >
                {{ format(tick) }}
            </span>
        </div>

        <div class="min-w-0 flex-1">
            <div
                ref="plot"
                class="relative h-44 rounded-sm"
                tabindex="0"
                role="group"
                :aria-label="title"
                @mousemove="onMove"
                @mouseleave="active = null"
                @focus="onFocus"
                @blur="active = null"
                @keydown="onKey"
            >
                <svg class="absolute inset-0 size-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" role="img" :aria-labelledby="`${uid}-t ${uid}-d`">
                    <title :id="`${uid}-t`">{{ title }}</title>
                    <desc :id="`${uid}-d`">{{ t('chart_data') }}: {{ points.length }}</desc>
                    <line
                        v-for="tick in domain.ticks"
                        :key="tick"
                        x1="0"
                        x2="100"
                        :y1="yPct(tick)"
                        :y2="yPct(tick)"
                        stroke="var(--la-border-muted)"
                        stroke-width="1"
                        vector-effect="non-scaling-stroke"
                    />
                    <path :d="area" fill="var(--la-chart-1)" fill-opacity="0.14" />
                    <path
                        :d="line"
                        fill="none"
                        stroke="var(--la-chart-1)"
                        stroke-width="2"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        vector-effect="non-scaling-stroke"
                    />
                    <line
                        v-if="activeCoord"
                        :x1="activeCoord.x"
                        :x2="activeCoord.x"
                        y1="0"
                        y2="100"
                        stroke="var(--la-fg-subtle)"
                        stroke-width="1"
                        stroke-dasharray="3 3"
                        vector-effect="non-scaling-stroke"
                    />
                </svg>

                <span
                    v-if="activeCoord && activeCoord.y !== null"
                    class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[var(--la-canvas-default)] bg-[var(--la-chart-1)]"
                    :style="{ left: `${activeCoord.x}%`, top: `${activeCoord.y}%` }"
                />

                <div
                    v-if="activePoint"
                    class="pointer-events-none absolute top-0 z-10 rounded-md border border-default bg-canvas px-2 py-1 text-xs whitespace-nowrap shadow-overlay"
                    :style="{ left: `${activeCoord.x}%`, transform: `translateX(${align(active)})` }"
                >
                    <div class="text-fg-muted">{{ activePoint.label }}</div>
                    <div class="font-semibold tabular-nums">{{ format(activePoint.value) }}</div>
                </div>
            </div>

            <div class="relative mt-1 h-4 text-xs text-fg-muted" aria-hidden="true">
                <span
                    v-for="i in xLabels"
                    :key="i"
                    class="absolute whitespace-nowrap"
                    :style="{ left: `${xPct(i)}%`, transform: `translateX(${align(i)})` }"
                >
                    {{ points[i].label }}
                </span>
            </div>

            <p class="sr-only" aria-live="polite">{{ summary }}</p>

            <table class="sr-only">
                <caption>{{ title }} - {{ t('chart_data') }}</caption>
                <tbody>
                    <tr v-for="(p, i) in points" :key="i">
                        <th scope="row">{{ p.label }}</th>
                        <td>{{ format(p.value) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
