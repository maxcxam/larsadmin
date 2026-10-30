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
        :type="field.component === 'datetime-field' ? 'datetime-local' : 'date'"
        :step="field.component === 'datetime-field' ? 1 : undefined"
        :readonly="field.readonly"
        class="larsadmin-input max-w-xs"
        :class="{ 'larsadmin-input-invalid': invalid }"
    >
</template>
