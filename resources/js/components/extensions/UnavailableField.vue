<script setup>
import { computed } from 'vue'
import { registry } from '../../extensions/registry'
import { useI18n } from '../../composables/useI18n'
import IconAlert from '../icons/IconAlert.vue'

// Placeholder for a `vendor/name` component that is not available (design 1.5 §5.3 step 6, §5.4): a skeleton while
// extensions are still loading, then "Unavailable". In a form the field's value is not submitted (EntityForm).
const props = defineProps({
    // form | display | panel | card | filter | tool
    mode: { type: String, default: 'form' },
    field: { type: Object, default: null },
    card: { type: Object, default: null },
    filter: { type: Object, default: null },
    tool: { type: Object, default: null },
})

const { t } = useI18n()

const loading = computed(() => registry.isLoading())
const boxed = computed(() => ['panel', 'card', 'tool'].includes(props.mode))
const title = computed(() => props.card?.name ?? props.tool?.name ?? (props.mode === 'panel' ? props.field?.name : null) ?? null)
</script>

<template>
    <span v-if="mode === 'display'" class="text-fg-subtle" :title="loading ? t('extension_loading') : t('extension_unavailable')">
        <span aria-hidden="true">{{ loading ? '…' : '—' }}</span>
        <span class="sr-only">{{ loading ? t('extension_loading') : t('extension_unavailable') }}</span>
    </span>

    <div
        v-else-if="mode === 'form'"
        :id="field?.attribute"
        class="rounded-md border border-dashed border-default px-3 py-2 text-sm text-fg-muted"
        role="status"
    >
        <div v-if="loading" class="flex items-center gap-2">
            <span class="h-3 w-24 animate-pulse rounded bg-neutral-muted" aria-hidden="true" />
            <span>{{ t('extension_loading') }}</span>
        </div>
        <template v-else>
            <p class="flex items-center gap-1.5"><IconAlert class="shrink-0 text-danger-fg" />{{ t('extension_unavailable') }}</p>
            <p class="mt-1 text-xs">{{ t('field_unavailable_hint') }}</p>
        </template>
    </div>

    <div v-else-if="mode === 'filter'" class="grid gap-1 text-sm" role="status">
        <span v-if="filter?.label" class="font-semibold">{{ filter.label }}</span>
        <span class="flex items-center gap-1.5 text-fg-muted">
            <IconAlert v-if="!loading" class="shrink-0 text-danger-fg" />
            {{ loading ? t('extension_loading') : t('extension_unavailable') }}
        </span>
    </div>

    <section
        v-else-if="boxed"
        class="larsadmin-box flex h-full flex-col gap-1 px-4 py-3 text-sm text-fg-muted"
        role="status"
        :aria-busy="loading"
    >
        <h2 v-if="title" class="truncate font-semibold text-fg">{{ title }}</h2>
        <div v-if="loading" class="animate-pulse space-y-2" aria-hidden="true">
            <div class="h-3 w-1/2 rounded bg-neutral-muted" />
            <div class="h-3 w-1/3 rounded bg-neutral-muted" />
        </div>
        <span v-if="loading" class="sr-only">{{ t('extension_loading') }}</span>
        <span v-else class="flex items-center gap-2">
            <IconAlert class="shrink-0 text-danger-fg" />
            <span class="min-w-0 break-words">{{ t('extension_unavailable') }}</span>
        </span>
    </section>
</template>
