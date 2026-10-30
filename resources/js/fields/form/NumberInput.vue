<script setup>
defineProps({
    field: { type: Object, required: true },
    invalid: { type: Boolean, default: false },
})

const model = defineModel()
</script>

<template>
    <input
        :id="field.attribute"
        v-model="model"
        type="number"
        :min="field.min"
        :max="field.max"
        :step="field.step ?? 'any'"
        :placeholder="field.placeholder"
        :readonly="field.readonly"
        class="larsadmin-input max-w-xs"
        :class="{ 'larsadmin-input-invalid': invalid }"
    >
</template>
