<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { useNumberFormat } from '../../composables/useNumberFormat'
import { isFailedRun, percent } from './notifications'

// Progress of a background run: a determinate progressbar (processed of total) or, for a standalone or
// still queued run, an indeterminate one without aria-valuenow. Ticks are not announced (no live region);
// the finish is announced by a toast.
const props = defineProps({
    run: { type: Object, required: true },
})

const { t } = useI18n()
const { formatNumber } = useNumberFormat()

const value = computed(() => percent(props.run))
const determinate = computed(() => value.value !== null && Number(props.run.total) > 0)
const total = computed(() => Number(props.run.total) || 0)
const processed = computed(() => Math.min(total.value, Math.max(0, Number(props.run.processed) || 0)))
const valueText = computed(() => (determinate.value ? t('runs_progress', { processed: formatNumber(processed.value), total: formatNumber(total.value) }) : undefined))
const color = computed(() => {
    if (isFailedRun(props.run)) return 'var(--la-danger-emphasis)'
    if (props.run.status === 'completed') return 'var(--la-success-emphasis)'
    if (props.run.status === 'cancelled') return 'var(--la-fg-subtle)'

    return 'var(--la-accent-emphasis)'
})
</script>

<template>
    <div class="grid gap-1">
        <div
            class="h-2 w-full overflow-hidden rounded-full bg-neutral-muted"
            role="progressbar"
            aria-valuemin="0"
            :aria-valuemax="determinate ? total : undefined"
            :aria-valuenow="determinate ? processed : undefined"
            :aria-valuetext="valueText"
            :aria-label="t('runs_progress_label', { action: run.action ?? '' })"
        >
            <div
                v-if="determinate"
                class="h-full rounded-full transition-[width] motion-reduce:transition-none"
                :style="{ width: `${value}%`, background: color }"
            />
            <div v-else class="h-full w-full animate-pulse rounded-full opacity-60 motion-reduce:animate-none" :style="{ background: color }" />
        </div>
        <p v-if="determinate" class="text-xs text-fg-muted" aria-hidden="true">{{ valueText }}</p>
    </div>
</template>

