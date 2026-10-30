<script setup>
defineProps({
    field: { type: Object, required: true },
})
</script>

<template>
    <span v-if="field.displayedAs !== null && field.displayedAs !== ''">{{ field.displayedAs }}</span>
    <span v-else class="text-fg-subtle">—</span>
</template>
