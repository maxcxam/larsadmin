<script setup>
defineProps({
    field: { type: Object, required: true },
})
</script>

<template>
    <p v-if="field.displayedAs" class="whitespace-pre-line">{{ field.displayedAs }}</p>
    <span v-else class="text-fg-subtle">—</span>
</template>
