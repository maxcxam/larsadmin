<script setup>
import { useI18n } from '../../composables/useI18n'
import { formatNumber } from '../../composables/useNumberFormat'
import DonutChart from '../charts/DonutChart.vue'
import MetricCard from './MetricCard.vue'

defineProps({ card: { type: Object, required: true } })

const { locale } = useI18n()

const fmt = (r) => (value) => formatNumber(value, { format: r.format, prefix: r.prefix, suffix: r.suffix }, locale.value)
</script>

<template>
    <MetricCard :card="card" skeleton-class="h-40" :empty="(r) => !r.slices?.length || !r.total">
        <template #default="{ result }">
            <DonutChart :slices="result.slices" :format="fmt(result)" :title="card.name" />
        </template>
    </MetricCard>
</template>
