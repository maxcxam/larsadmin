<script setup>
import { useId } from 'vue'
import { useI18n } from '../composables/useI18n'

defineProps({
    filter: { type: Object, required: true },
    modelValue: { type: [String, Number, Boolean], default: null },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const id = useId()

function onChange(event) {
    emit('update:modelValue', event.target.value === '' ? null : event.target.value)
}
</script>

<template>
    <div>
        <label :for="id" class="mb-1 block text-xs font-semibold text-fg-muted">{{ filter.label ?? filter.name }}</label>
        <select :id="id" :value="modelValue ?? ''" class="larsadmin-input" @change="onChange">
            <option value="">{{ t('filter_any') }}</option>
            <option v-for="option in filter.options ?? []" :key="option.value" :value="String(option.value)">{{ option.label }}</option>
        </select>
    </div>
</template>
