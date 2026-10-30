<script setup>
import { useI18n } from '../composables/useI18n'

const props = defineProps({
    filter: { type: Object, required: true },
    modelValue: { type: Object, default: null },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

function set(part, event) {
    const next = { from: props.modelValue?.from ?? '', to: props.modelValue?.to ?? '', [part]: event.target.value }

    emit('update:modelValue', next.from === '' && next.to === '' ? null : next)
}
</script>

<template>
    <fieldset>
        <legend class="mb-1 block text-xs font-semibold text-fg-muted">{{ filter.label ?? filter.name }}</legend>
        <div class="flex items-center gap-2">
            <input
                type="date"
                :value="modelValue?.from ?? ''"
                :max="modelValue?.to || undefined"
                :aria-label="`${filter.label ?? filter.name}: ${t('filter_from')}`"
                class="larsadmin-input"
                @change="set('from', $event)"
            >
            <input
                type="date"
                :value="modelValue?.to ?? ''"
                :min="modelValue?.from || undefined"
                :aria-label="`${filter.label ?? filter.name}: ${t('filter_to')}`"
                class="larsadmin-input"
                @change="set('to', $event)"
            >
        </div>
    </fieldset>
</template>
