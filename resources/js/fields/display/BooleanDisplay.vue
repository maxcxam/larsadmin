<script setup>
import { useI18n } from '../../composables/useI18n'

defineProps({
    field: { type: Object, required: true },
})

const { t } = useI18n()
</script>

<template>
    <span class="larsadmin-label" :class="{ 'larsadmin-label-success': field.value }">{{ field.value ? t('yes') : t('no') }}</span>
</template>
