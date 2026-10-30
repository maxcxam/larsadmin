<script setup>
import { Link } from '@inertiajs/vue3'
import { useI18n } from '../../composables/useI18n'

defineProps({
    field: { type: Object, required: true },
})

const { t } = useI18n()
</script>

<template>
    <span v-if="field.hidden" class="text-fg-muted">{{ t('hidden_record') }}</span>
    <template v-else-if="field.displayedAs !== null && field.displayedAs !== ''">
        <Link v-if="field.link" :href="field.link" class="text-accent-fg hover:underline">{{ field.displayedAs }}</Link>
        <span v-else>{{ field.displayedAs }}</span>
    </template>
    <span v-else class="text-fg-subtle">—</span>
</template>
