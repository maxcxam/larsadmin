<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { formatSize } from '../files'
import { isIndexPayload, useFileLinks } from './fileLinks'
import IconFile from '../../components/icons/IconFile.vue'
import IconDownload from '../../components/icons/IconDownload.vue'

// `image-field` on index (32×32 thumbnail, "+N" for multiple) and detail (previews up to 320×240).
// Previews are the originals scaled by CSS, or the developer's ->thumbnail() URL (D11).
const props = defineProps({
    field: { type: Object, required: true },
})

const { t, tc, locale } = useI18n()
const { follow, status } = useFileLinks(() => props.field)

const files = computed(() => props.field.files ?? [])
const inIndex = computed(() => isIndexPayload(props.field))
const total = computed(() => props.field.count ?? files.value.length)
const first = computed(() => files.value[0] ?? null)

const preview = (file) => (file.isImage === false ? null : file.thumbnailUrl ?? null)
</script>

<template>
    <span v-if="!files.length && !total" class="text-fg-subtle">—</span>

    <span v-else-if="inIndex" class="inline-flex items-center gap-1.5 align-middle">
        <template v-if="first">
            <a
                v-if="first.url && preview(first)"
                :href="first.url"
                target="_blank"
                rel="noopener noreferrer"
                class="block shrink-0 rounded-md"
                :aria-label="t('files_open_named', { name: first.name })"
                @click="follow($event, 0)"
            >
                <img
                    :src="preview(first)"
                    :alt="t('files_preview_alt', { name: first.name })"
                    width="32"
                    height="32"
                    loading="lazy"
                    decoding="async"
                    class="size-8 rounded-md border border-default bg-canvas-subtle object-cover"
                >
            </a>
            <span v-else class="inline-flex min-w-0 items-center gap-1.5">
                <IconFile class="text-fg-muted" />
                <span class="truncate">{{ first.name }}</span>
            </span>
        </template>
        <span v-if="total > 1" class="larsadmin-counter" :title="tc('files_count', total)">
            +{{ total - 1 }}<span class="sr-only"> ({{ tc('files_count', total) }})</span>
        </span>
        <span class="sr-only" aria-live="polite">{{ status ? t(status) : '' }}</span>
    </span>

    <div v-else>
        <ul class="flex flex-wrap gap-3">
            <li v-for="(file, index) in files" :key="file.token ?? index" class="grid max-w-80 min-w-0 gap-1">
                <a
                    v-if="file.url && preview(file)"
                    :href="file.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="block w-fit rounded-md"
                    :aria-label="t('files_open_named', { name: file.name })"
                    @click="follow($event, index)"
                >
                    <img
                        :src="preview(file)"
                        :alt="t('files_preview_alt', { name: file.name })"
                        width="320"
                        height="240"
                        loading="lazy"
                        decoding="async"
                        class="h-auto max-h-60 w-auto max-w-full rounded-md border border-default bg-canvas-subtle object-contain"
                    >
                </a>
                <div class="flex min-w-0 items-center gap-1.5 text-xs">
                    <span class="min-w-0 truncate" :title="file.name">{{ file.name }}</span>
                    <span v-if="file.size !== null && file.size !== undefined" class="shrink-0 text-fg-muted">{{ formatSize(file.size, locale) }}</span>
                    <a
                        v-if="file.downloadUrl"
                        :href="file.downloadUrl"
                        :download="file.name"
                        class="shrink-0 rounded-md p-1 text-fg-muted hover:bg-neutral-muted hover:text-fg"
                        :aria-label="t('files_download_named', { name: file.name })"
                        :title="t('files_download')"
                        @click="follow($event, index, 'downloadUrl')"
                    >
                        <IconDownload />
                    </a>
                </div>
            </li>
        </ul>
        <span class="sr-only" aria-live="polite">{{ status ? t(status) : '' }}</span>
    </div>
</template>
