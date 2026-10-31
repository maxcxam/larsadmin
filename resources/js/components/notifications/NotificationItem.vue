<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { absoluteTime, normalizeLevel, relativeTime, safeHref } from './notifications'
import LevelIcon from './LevelIcon.vue'
import IconCheck from '../icons/IconCheck.vue'
import IconTrash from '../icons/IconTrash.vue'

// One notification: level icon (+ sr-only level), title, body (plain text), time, link or download,
// "mark as read" and "delete". Unread = bold title + dot + sr-only "Unread" (never colour alone).
const props = defineProps({
    item: { type: Object, required: true },
    timezone: { type: String, default: null },
    now: { type: Number, default: () => Date.now() },
    busy: { type: Boolean, default: false },
})

const emit = defineEmits(['open', 'read', 'remove'])

const { t, locale } = useI18n()

const level = computed(() => normalizeLevel(props.item.level))
const title = computed(() => String(props.item.title ?? ''))
const href = computed(() => safeHref(props.item.url))
const linkLabel = computed(() => props.item.urlLabel || t(props.item.download ? 'notifications_download' : 'notifications_open'))
const ago = computed(() => relativeTime(props.item.createdAt, locale.value, props.now) ?? t('notifications_just_now'))
const full = computed(() => absoluteTime(props.item.createdAt, locale.value, props.timezone))
</script>

<template>
    <li class="flex gap-3 px-4 py-3" :data-notification="item.id">
        <LevelIcon :level="level" :icon="item.icon" class="mt-0.5" />

        <div class="min-w-0 flex-1">
            <p class="flex items-start gap-1.5 break-words" :class="item.read ? 'font-normal' : 'font-semibold'">
                <span v-if="!item.read" class="mt-1.5 size-2 shrink-0 rounded-full bg-accent-emphasis" aria-hidden="true" />
                <span>
                    <span class="sr-only">{{ t(`notifications_level_${level}`) }}: </span>
                    <span v-if="!item.read" class="sr-only">{{ t('notifications_unread') }}: </span>
                    {{ title }}
                </span>
            </p>
            <p v-if="item.body" class="mt-0.5 break-words whitespace-pre-line text-fg-muted">{{ item.body }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                <time v-if="item.createdAt" :datetime="item.createdAt" :title="full" class="text-fg-muted">{{ ago }}</time>
                <a
                    v-if="href"
                    :href="href"
                    :download="item.download ? '' : undefined"
                    class="font-semibold text-accent-fg hover:underline"
                    @click="emit('open', item, $event)"
                >
                    {{ linkLabel }}
                </a>
            </div>
        </div>

        <div class="flex shrink-0 items-start gap-1">
            <button
                v-if="!item.read"
                type="button"
                class="rounded p-1 text-fg-muted hover:bg-neutral-muted disabled:opacity-50"
                :aria-label="t('notifications_mark_read', { title })"
                :title="t('notifications_mark_read', { title })"
                :disabled="busy"
                @click="emit('read', item)"
            >
                <IconCheck />
            </button>
            <button
                type="button"
                class="rounded p-1 text-fg-muted hover:bg-neutral-muted hover:text-danger-fg disabled:opacity-50"
                :aria-label="t('notifications_delete', { title })"
                :title="t('notifications_delete', { title })"
                :disabled="busy"
                @click="emit('remove', item)"
            >
                <IconTrash />
            </button>
        </div>
    </li>
</template>
