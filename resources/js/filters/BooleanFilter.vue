<script setup>
import { useI18n } from '../composables/useI18n'

const props = defineProps({
    filter: { type: Object, required: true },
    modelValue: { type: [String, Number, Boolean], default: null },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

// Normalised to '1' | '0' | null, which is what goes into the query string.
function current() {
    const value = props.modelValue

    if (value === null || value === undefined || value === '') return null

    return value === true || value === 1 || value === '1' || value === 'true' ? '1' : '0'
}

const choices = [
    { value: null, label: 'filter_any' },
    { value: '1', label: 'yes' },
    { value: '0', label: 'no' },
]
</script>

<template>
    <div role="radiogroup" :aria-label="filter.label ?? filter.name">
        <div class="mb-1 text-xs font-semibold text-fg-muted" aria-hidden="true">{{ filter.label ?? filter.name }}</div>
        <div class="flex gap-1">
            <button
                v-for="choice in choices"
                :key="String(choice.value)"
                type="button"
                role="radio"
                :aria-checked="current() === choice.value"
                class="larsadmin-btn flex-1 justify-center"
                :class="current() === choice.value ? 'bg-neutral-muted font-semibold' : ''"
                @click="emit('update:modelValue', choice.value)"
            >
                {{ t(choice.label) }}
            </button>
        </div>
    </div>
</template>
