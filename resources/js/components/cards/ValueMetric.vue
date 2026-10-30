<script setup>
import { useI18n } from '../../composables/useI18n'
import { formatNumber } from '../../composables/useNumberFormat'
import IconArrowDown from '../icons/IconArrowDown.vue'
import IconArrowUp from '../icons/IconArrowUp.vue'
import IconDash from '../icons/IconDash.vue'
import MetricCard from './MetricCard.vue'
import { changeDirection } from './valueChange'

defineProps({ card: { type: Object, required: true } })

const { t, locale } = useI18n()

const fmt = (r, value = r.value) => formatNumber(value, { format: r.format, prefix: r.prefix, suffix: r.suffix }, locale.value)
const pct = (change) => formatNumber(Math.abs(change), { format: { maximumFractionDigits: 1 } }, locale.value)

// Icons are decorative (aria-hidden); the text carries the meaning.
const CHANGE = {
    up: { icon: IconArrowUp, class: 'text-success-fg', key: 'change_up' },
    down: { icon: IconArrowDown, class: 'text-danger-fg', key: 'change_down' },
    none: { icon: IconDash, class: 'text-fg-muted', key: 'no_change' },
}

const change = (r) => CHANGE[changeDirection(r.change)] ?? null
</script>

<template>
    <MetricCard :card="card" skeleton-class="h-20" :empty="(r) => r.value === null && !r.zeroResult">
        <template #default="{ result }">
            <div class="text-3xl font-semibold tabular-nums">{{ fmt(result) }}</div>

            <p v-if="change(result)" class="mt-1 flex items-center gap-1 text-sm text-fg-muted">
                <component :is="change(result).icon" :class="change(result).class" />
                <span>{{ t(change(result).key, { value: pct(result.change) }) }}</span>
            </p>
            <p v-else-if="result.previous === 0" class="mt-1 text-sm text-fg-muted">{{ t('no_previous') }}</p>
        </template>
    </MetricCard>
</template>
