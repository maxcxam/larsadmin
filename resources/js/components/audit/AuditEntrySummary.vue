<script setup>
import { computed, ref, useId } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from '../../composables/useI18n'
import { formatDateTime, hasDetails, recordText, safeUrl, summary } from './audit'
import AuditActor from './AuditActor.vue'
import AuditChanges from './AuditChanges.vue'
import IconChevronDown from '../icons/IconChevronDown.vue'
import IconHistory from '../icons/IconHistory.vue'
import IconPencil from '../icons/IconPencil.vue'
import IconPlus from '../icons/IconPlus.vue'
import IconTrash from '../icons/IconTrash.vue'
import IconX from '../icons/IconX.vue'

// One audit entry: "Actor · Updated: 3 fields · 30 Oct 2026, 14:05" plus an expand button for the diff.
// With `context` (global page cards) the entity, the record and the IP / user agent are shown too.
const props = defineProps({
    entry: { type: Object, required: true },
    timezone: { type: String, default: null },
    context: { type: Boolean, default: false },
})

const { t, tc, locale } = useI18n()

const detailsId = `${useId()}-details`
const open = ref(false)

const text = computed(() => summary(props.entry, t, tc))
const time = computed(() => formatDateTime(props.entry.createdAt, locale.value, props.timezone))
const expandable = computed(() => hasDetails(props.entry, { meta: props.context }))
const recordHref = computed(() => safeUrl(props.entry.record?.url))

// Decorative: the event is always spelled out in `text`.
const icon = computed(() => ({
    created: IconPlus,
    updated: IconPencil,
    deleted: IconTrash,
    attached: IconPlus,
    detached: IconX,
}[props.entry.event] ?? IconHistory))
</script>

<template>
    <div>
        <div class="flex items-start gap-3">
            <span class="mt-0.5 rounded-full border border-default p-1 text-fg-muted">
                <component :is="icon" class="size-3.5" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="break-words">
                    <AuditActor :entry="entry" />
                    <span class="text-fg-muted" aria-hidden="true"> · </span>
                    <span>{{ text }}</span>
                </p>
                <p class="flex flex-wrap gap-x-2 text-xs text-fg-muted">
                    <time :datetime="entry.createdAt">{{ time }}</time>
                    <template v-if="context && entry.entity">
                        <span aria-hidden="true">·</span>
                        <span>{{ entry.entity.label }}</span>
                    </template>
                    <template v-if="context && entry.record">
                        <span aria-hidden="true">·</span>
                        <Link v-if="recordHref" :href="recordHref" class="text-accent-fg hover:underline">{{ recordText(entry.record) }}</Link>
                        <span v-else>{{ recordText(entry.record) }}</span>
                    </template>
                </p>
            </div>

            <button
                v-if="expandable"
                type="button"
                class="shrink-0 rounded-md p-1.5 text-fg-muted hover:bg-neutral-muted hover:text-fg"
                :aria-expanded="open"
                :aria-controls="detailsId"
                :aria-label="t('audit_toggle', { event: text, time })"
                :title="t('audit_toggle', { event: text, time })"
                @click="open = !open"
            >
                <IconChevronDown :class="['transition-transform', open ? 'rotate-180' : '']" />
            </button>
        </div>

        <div v-if="expandable" v-show="open" :id="detailsId" class="mt-2 sm:pl-9">
            <AuditChanges v-if="open" :entry="entry" :timezone="timezone" :show-meta="context" />
        </div>
    </div>
</template>
