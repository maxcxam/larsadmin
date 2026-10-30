<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from '../../composables/useI18n'
import { formatDateTime, recordText, safeUrl, toValue } from './audit'
import IconFile from '../icons/IconFile.vue'
import IconLock from '../icons/IconLock.vue'

// One audit value by its `kind` (§9.2): empty, text, boolean, datetime, record, files; or the mask.
const props = defineProps({
    value: { type: [Object, String, Number, Boolean], default: null },
    // Panel timezone for datetime values (browser timezone when null)
    timezone: { type: String, default: null },
})

const { t, locale } = useI18n()

const shown = computed(() => toValue(props.value))
const files = computed(() => (Array.isArray(shown.value.files) ? shown.value.files : [])
    .map((file) => (typeof file === 'string' ? file : file?.name ?? ''))
    .filter((name) => name !== ''))
const href = computed(() => (shown.value.kind === 'record' ? safeUrl(shown.value.url) : null))
</script>

<template>
    <span v-if="shown.kind === 'masked'" class="inline-flex items-center gap-1 text-fg-muted">
        <IconLock />{{ t('audit_masked') }}
    </span>

    <span v-else-if="shown.kind === 'empty' || (shown.kind === 'files' && !files.length)" class="text-fg-muted">
        <span aria-hidden="true">—</span><span class="sr-only">{{ t('audit_empty_value') }}</span>
    </span>

    <div v-else class="min-w-0 break-words">
        <template v-if="shown.kind === 'boolean'">{{ shown.value ? t('yes') : t('no') }}</template>

        <time v-else-if="shown.kind === 'datetime'" :datetime="shown.value">{{ formatDateTime(shown.value, locale, timezone) }}</time>

        <template v-else-if="shown.kind === 'record'">
            <Link v-if="href" :href="href" class="text-accent-fg hover:underline">{{ recordText(shown) }}</Link>
            <template v-else>{{ recordText(shown) }}</template>
        </template>

        <ul v-else-if="shown.kind === 'files'" class="space-y-0.5">
            <li v-for="(name, index) in files" :key="index" class="flex items-center gap-1">
                <IconFile class="text-fg-muted" />
                <span class="break-all">{{ name }}</span>
            </li>
        </ul>

        <span v-else class="whitespace-pre-wrap">{{ shown.text }}</span>

        <span v-if="shown.truncated === true" class="ml-1 text-xs text-fg-muted">{{ t('audit_truncated') }}</span>
    </div>
</template>
