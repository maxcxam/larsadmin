<script setup>
import { computed, ref, useId } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { formatNumber } from '../../composables/useNumberFormat'
import { donutSegments } from './geometry'

const props = defineProps({
    slices: { type: Array, required: true }, // [{ label, value, color }]
    format: { type: Function, default: (v) => String(v) },
    title: { type: String, default: '' },
})

const { t, locale } = useI18n()
const uid = useId()
const hovered = ref(null)

const RADIUS = 100 / (2 * Math.PI)

const colorOf = (slice, i) => slice.color || `var(--la-chart-${(i % 8) + 1})`
const total = computed(() => props.slices.reduce((sum, s) => sum + (Number(s.value) > 0 ? Number(s.value) : 0), 0))
const segments = computed(() => donutSegments(props.slices.map((s) => Number(s.value))))
const share = (value) => formatNumber(total.value ? Number(value) / total.value : 0, { format: { style: 'percent', maximumFractionDigits: 1 } }, locale.value)
const current = computed(() => (hovered.value === null ? null : props.slices[hovered.value]))
</script>

<template>
    <div class="flex flex-col items-center gap-4 sm:flex-row sm:items-center">
        <div class="relative size-36 shrink-0">
            <svg viewBox="0 0 42 42" class="size-full -rotate-90" role="img" :aria-labelledby="`${uid}-t ${uid}-d`">
                <title :id="`${uid}-t`">{{ title }}</title>
                <desc :id="`${uid}-d`">{{ t('chart_data') }}: {{ slices.length }}</desc>
                <circle cx="21" cy="21" :r="RADIUS" fill="none" stroke="var(--la-neutral-muted)" stroke-width="5" />
                <circle
                    v-for="seg in segments"
                    :key="seg.index"
                    cx="21"
                    cy="21"
                    :r="RADIUS"
                    fill="none"
                    :stroke="colorOf(slices[seg.index], seg.index)"
                    :stroke-width="hovered === seg.index ? 6 : 5"
                    :stroke-dasharray="seg.dasharray"
                    :stroke-dashoffset="seg.dashoffset"
                    pathLength="100"
                    :opacity="hovered === null || hovered === seg.index ? 1 : 0.45"
                    @mouseenter="hovered = seg.index"
                    @mouseleave="hovered = null"
                />
            </svg>
            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-6 text-center">
                <span class="text-lg leading-tight font-semibold tabular-nums">{{ format(current ? current.value : total) }}</span>
                <span v-if="current" class="line-clamp-2 text-xs text-fg-muted">{{ current.label }}</span>
            </div>
        </div>

        <ul class="min-w-0 flex-1 space-y-1 self-stretch text-sm">
            <li
                v-for="(slice, i) in slices"
                :key="i"
                class="flex items-center gap-2 rounded px-1 py-0.5"
                :class="hovered === i ? 'bg-neutral-muted' : ''"
                tabindex="0"
                @mouseenter="hovered = i"
                @mouseleave="hovered = null"
                @focus="hovered = i"
                @blur="hovered = null"
            >
                <span class="size-2.5 shrink-0 rounded-sm" :style="{ background: colorOf(slice, i) }" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate" :title="slice.label">{{ slice.label }}</span>
                <span class="tabular-nums">{{ format(slice.value) }}</span>
                <span class="w-12 text-right text-xs text-fg-muted tabular-nums">{{ share(slice.value) }}</span>
            </li>
        </ul>

        <table class="sr-only">
            <caption>{{ title }} - {{ t('chart_data') }}</caption>
            <tbody>
                <tr v-for="(slice, i) in slices" :key="i">
                    <th scope="row">{{ slice.label }}</th>
                    <td>{{ format(slice.value) }} ({{ share(slice.value) }})</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
