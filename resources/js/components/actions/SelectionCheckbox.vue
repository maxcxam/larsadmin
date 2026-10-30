<script setup>
import { onMounted, ref, watch } from 'vue'

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    indeterminate: { type: Boolean, default: false },
    label: { type: String, required: true },
})

const emit = defineEmits(['update:modelValue'])
const input = ref(null)

const sync = () => input.value && (input.value.indeterminate = props.indeterminate)
onMounted(sync)
watch(() => props.indeterminate, sync)
</script>

<template>
    <input
        ref="input"
        type="checkbox"
        class="size-4 cursor-pointer rounded border-default accent-accent-fg"
        :checked="modelValue"
        :aria-label="label"
        @change="emit('update:modelValue', $event.target.checked)"
        @click.stop
    >
</template>
