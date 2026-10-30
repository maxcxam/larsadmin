<script setup>
import { computed } from 'vue'

const props = defineProps({
    field: { type: Object, required: true },
    invalid: { type: Boolean, default: false },
})

const model = defineModel()

const type = computed(() => (props.field.component === 'password-field' ? 'password' : props.field.inputType ?? 'text'))
</script>

<template>
    <input
        :id="field.attribute"
        v-model="model"
        :type="type"
        :placeholder="field.placeholder"
        :readonly="field.readonly"
        :autocomplete="type === 'password' ? 'new-password' : undefined"
        class="larsadmin-input"
        :class="{ 'larsadmin-input-invalid': invalid }"
    >
</template>
