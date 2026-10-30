<script setup>
import { Link } from '@inertiajs/vue3'
import { useI18n } from '../composables/useI18n'
import IconChevronRight from './icons/IconChevronRight.vue'

const { t } = useI18n()

defineProps({
    items: { type: Array, required: true }, // [{ label, href? }]
})
</script>

<template>
    <nav :aria-label="t('breadcrumb')" class="mb-2">
        <ol class="flex flex-wrap items-center gap-1 text-sm text-fg-muted">
            <li v-for="(item, index) in items" :key="index" class="flex items-center gap-1">
                <IconChevronRight v-if="index" class="size-3" />
                <Link v-if="item.href" :href="item.href" class="text-accent-fg hover:underline">{{ item.label }}</Link>
                <span v-else aria-current="page">{{ item.label }}</span>
            </li>
        </ol>
    </nav>
</template>
