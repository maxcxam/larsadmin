<script setup>
import { useI18n } from '../../composables/useI18n'
import { formatNumber } from '../../composables/useNumberFormat'
import LineChart from '../charts/LineChart.vue'
import MetricCard from './MetricCard.vue'

defineProps({ card: { type: Object, required: true } })

const { locale } = useI18n()

const fmt = (r) => (value) => formatNumber(value, { format: r.format, prefix: r.prefix, suffix: r.suffix }, locale.value)
</script>

<template>
    <MetricCard :card="card" skeleton-class="h-56" :empty="(r) => !r.points?.length">
        <template #default="{ result }">
            <div v-if="result.value !== null && result.value !== undefined" class="mb-2 text-3xl font-semibold tabular-nums">
                {{ fmt(result)(result.value) }}
            </div>
            <LineChart :points="result.points" :format="fmt(result)" :title="card.name" />
        </template>
    </MetricCard>
</template>
