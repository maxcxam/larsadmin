<script setup>
defineProps({
    field: { type: Object, required: true },
    invalid: { type: Boolean, default: false },
})

const model = defineModel()
</script>

<template>
    <textarea
        :id="field.attribute"
        v-model="model"
        :rows="field.rows"
        :placeholder="field.placeholder"
        :readonly="field.readonly"
        class="larsadmin-input"
        :class="{ 'larsadmin-input-invalid': invalid }"
    />
</template>
