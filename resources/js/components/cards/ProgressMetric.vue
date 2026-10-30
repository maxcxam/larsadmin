<script setup>
import { useI18n } from '../../composables/useI18n'
import { formatNumber } from '../../composables/useNumberFormat'
import ProgressBar from '../charts/ProgressBar.vue'
import MetricCard from './MetricCard.vue'

defineProps({ card: { type: Object, required: true } })

const { t, locale } = useI18n()

const fmt = (r, value) => formatNumber(value, { format: r.format, prefix: r.prefix, suffix: r.suffix }, locale.value)
const pct = (value) => formatNumber(value, { format: { maximumFractionDigits: 1 } }, locale.value)
</script>

<template>
    <MetricCard :card="card" skeleton-class="h-16" :empty="(r) => r.value === null || r.value === undefined">
        <template #default="{ result }">
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-semibold tabular-nums">{{ fmt(result, result.value) }}</span>
                <span class="text-sm text-fg-muted tabular-nums">{{ pct(result.percentage) }}%</span>
            </div>
            <ProgressBar
                class="mt-3"
                :percentage="result.percentage"
                :avoid="result.avoid"
                :label="card.name"
                :value-text="`${pct(result.percentage)}%`"
            />
            <p class="mt-2 text-sm text-fg-muted">{{ t('of_target', { target: fmt(result, result.target) }) }}</p>
        </template>
    </MetricCard>
</template>
