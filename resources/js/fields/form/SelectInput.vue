<script setup>
import { useI18n } from '../../composables/useI18n'

defineProps({
    field: { type: Object, required: true },
    invalid: { type: Boolean, default: false },
})

const model = defineModel()

const { t } = useI18n()
</script>

<template>
    <select
        :id="field.attribute"
        v-model="model"
        :disabled="field.readonly"
        class="larsadmin-input max-w-xs"
        :class="{ 'larsadmin-input-invalid': invalid }"
    >
        <option :value="null" :disabled="!field.nullable">{{ field.placeholder ?? t('choose_option') }}</option>
        <option v-for="option in field.options" :key="option.value" :value="option.value">
            {{ option.label }}
        </option>
    </select>
</template>
