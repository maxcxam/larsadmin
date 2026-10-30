<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { formatSize } from '../files'
import { isIndexPayload, useFileLinks } from './fileLinks'
import IconFile from '../../components/icons/IconFile.vue'
import IconDownload from '../../components/icons/IconDownload.vue'

// `file-field` on index (first name + "+N") and detail (list with sizes and download links).
const props = defineProps({
    field: { type: Object, required: true },
})

const { t, tc, locale } = useI18n()
const { follow, status } = useFileLinks(() => props.field)

const files = computed(() => props.field.files ?? [])
const inIndex = computed(() => isIndexPayload(props.field))
const total = computed(() => props.field.count ?? files.value.length)

// Images open inline in a new tab; everything else is served as an attachment.
const linkAttrs = (file) =>
    file.isImage
        ? { target: '_blank', rel: 'noopener noreferrer', 'aria-label': t('files_open_named', { name: file.name }) }
        : { download: file.name, 'aria-label': t('files_download_named', { name: file.name }) }
</script>

<template>
    <span v-if="!files.length && !total" class="text-fg-subtle">—</span>

    <span v-else-if="inIndex" class="inline-flex max-w-full items-center gap-1.5">
        <IconFile class="text-fg-muted" />
        <template v-if="files[0]">
            <a v-if="files[0].url" :href="files[0].url" v-bind="linkAttrs(files[0])" class="truncate text-accent-fg hover:underline" @click="follow($event, 0)">{{ files[0].name }}</a>
            <span v-else class="truncate">{{ files[0].name }}</span>
        </template>
        <span v-if="total > 1" class="larsadmin-counter" :title="tc('files_count', total)">
            +{{ total - 1 }}<span class="sr-only"> ({{ tc('files_count', total) }})</span>
        </span>
        <span class="sr-only" aria-live="polite">{{ status ? t(status) : '' }}</span>
    </span>

    <div v-else>
        <ul class="grid gap-1">
            <li v-for="(file, index) in files" :key="file.token ?? index" class="flex min-w-0 items-center gap-2">
                <IconFile class="text-fg-muted" />
                <a v-if="file.url" :href="file.url" v-bind="linkAttrs(file)" class="min-w-0 truncate text-accent-fg hover:underline" @click="follow($event, index)">{{ file.name }}</a>
                <span v-else class="min-w-0 truncate">{{ file.name }}</span>
                <span v-if="file.size !== null && file.size !== undefined" class="shrink-0 text-xs text-fg-muted">{{ formatSize(file.size, locale) }}</span>
                <a
                    v-if="file.downloadUrl && file.downloadUrl !== file.url"
                    :href="file.downloadUrl"
                    :download="file.name"
                    class="shrink-0 rounded-md p-1 text-fg-muted hover:bg-neutral-muted hover:text-fg"
                    :aria-label="t('files_download_named', { name: file.name })"
                    :title="t('files_download')"
                    @click="follow($event, index, 'downloadUrl')"
                >
                    <IconDownload />
                </a>
            </li>
        </ul>
        <span class="sr-only" aria-live="polite">{{ status ? t(status) : '' }}</span>
    </div>
</template>
