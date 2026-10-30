<script setup>
import { computed } from 'vue'

const props = defineProps({
    percentage: { type: Number, default: 0 },
    avoid: { type: Boolean, default: false },
    label: { type: String, default: '' },
    valueText: { type: String, default: '' },
})

const clamped = computed(() => Math.min(100, Math.max(0, props.percentage ?? 0)))
// Reaching the target is good, unless lower is better (avoid) - then it is a warning.
const color = computed(() => {
    if (props.percentage >= 100) return props.avoid ? 'var(--la-danger-emphasis)' : 'var(--la-success-emphasis)'

    return 'var(--la-accent-emphasis)'
})
</script>

<template>
    <div
        class="h-2.5 w-full overflow-hidden rounded-full bg-neutral-muted"
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="Math.round(clamped)"
        :aria-valuetext="valueText || undefined"
        :aria-label="label"
    >
        <div class="h-full rounded-full transition-[width]" :style="{ width: `${clamped}%`, background: color }" />
    </div>
</template>
