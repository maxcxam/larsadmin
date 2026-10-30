<script setup>
defineProps({
    field: { type: Object, required: true },
    invalid: { type: Boolean, default: false },
})

const model = defineModel({ type: Boolean })
</script>

<template>
    <input
        :id="field.attribute"
        v-model="model"
        type="checkbox"
        :disabled="field.readonly"
        class="larsadmin-checkbox"
    >
</template>
