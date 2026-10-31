<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from '../../composables/useI18n'
import { useNotifications } from '../../composables/useNotifications'
import { isPanelHref, safeHref } from './notifications'
import NotificationItem from './NotificationItem.vue'
import RunItem from './RunItem.vue'
import IconX from '../icons/IconX.vue'

// Content of the bell's non-modal dialog: heading, "Mark all as read", background tasks (only when there
// are any), the notification list with "Show more", and the loading / error / empty states.
defineProps({
    id: { type: String, required: true },
    titleId: { type: String, required: true },
})

const emit = defineEmits(['close', 'ready'])

const page = usePage()
const { t } = useI18n()
const store = useNotifications()
const state = store.state

const heading = ref(null)
const list = ref(null)
const busy = ref({})
const markingAll = ref(false)
const now = ref(Date.now())
let clock = null

const hasUnread = computed(() => (state.unread ?? 0) > 0 || state.items.some((item) => !item.read))

function focusHeading() {
    heading.value?.focus()
}

defineExpose({ focusHeading })

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30_000)
    store.open()
    emit('ready', { focusHeading })
})

onBeforeUnmount(() => clearInterval(clock))

async function withBusy(id, callback) {
    busy.value = { ...busy.value, [id]: true }

    try {
        return await callback()
    } finally {
        const { [id]: _, ...rest } = busy.value
        busy.value = rest
    }
}

function navigate(href, event, download) {
    if (download) return // <a download> handles it

    event?.preventDefault()
    emit('close', { restoreFocus: false })

    if (isPanelHref(href, page.props.larsadmin?.path)) router.visit(href)
    else window.location.assign(href)
}

function openItem(item, event) {
    const href = safeHref(item.url)

    if (!item.read) store.markRead(item.id)
    if (href) navigate(href, event, item.download)
}

function openRun(run, event) {
    const href = safeHref(run.url)

    if (href) navigate(href, event, run.download)
}

// After a row disappears focus moves to the next row's first control, else to the heading (2.4.3).
async function remove(item) {
    const index = state.items.findIndex((entry) => entry.id === item.id)
    const removed = await withBusy(item.id, () => store.remove(item.id))

    if (!removed) return

    await nextTick()

    const rows = list.value?.querySelectorAll('[data-notification]') ?? []
    const target = rows[Math.min(index, rows.length - 1)]?.querySelector('a[href], button:not([disabled])')

    if (target) target.focus()
    else focusHeading()
}

async function markRead(item) {
    const index = state.items.findIndex((entry) => entry.id === item.id)

    await withBusy(item.id, () => store.markRead(item.id))
    await nextTick()

    // The "mark as read" button is gone: keep focus inside the row.
    if (!list.value?.contains(document.activeElement)) {
        const row = list.value?.querySelectorAll('[data-notification]')[index]

        const target = row?.querySelector('a[href], button:not([disabled])')

        if (target) target.focus()
        else focusHeading()
    }
}

async function markAll() {
    markingAll.value = true

    try {
        await store.markAllRead()
    } finally {
        markingAll.value = false
    }
}

async function loadMore() {
    const added = await store.loadMore()

    await nextTick()

    if (!state.nextCursor && added.length) {
        // The button is gone: focus the first newly loaded row.
        list.value?.querySelector(`[data-notification="${CSS.escape(String(added[0].id))}"] a[href], [data-notification="${CSS.escape(String(added[0].id))}"] button`)?.focus()
    }
}
</script>

<template>
    <div :id="id" role="dialog" :aria-labelledby="titleId" class="flex max-h-[70dvh] flex-col overflow-hidden text-sm text-fg">
        <div class="flex items-center justify-between gap-2 border-b border-muted px-4 py-2.5">
            <h2 :id="titleId" ref="heading" tabindex="-1" class="text-base font-semibold focus:outline-none focus-visible:underline">{{ t('notifications') }}</h2>
            <div class="flex items-center gap-1">
                <button type="button" class="larsadmin-btn larsadmin-btn-sm" :disabled="!hasUnread || markingAll" @click="markAll">
                    {{ t('notifications_mark_all_read') }}
                </button>
                <button type="button" class="rounded p-1 text-fg-muted hover:bg-neutral-muted" :aria-label="t('act.close')" @click="emit('close', { restoreFocus: true })">
                    <IconX />
                </button>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
            <section v-if="state.runs.length" :aria-labelledby="`${titleId}-runs`" class="border-b border-muted">
                <h3 :id="`${titleId}-runs`" class="px-4 pt-3 text-xs font-semibold text-fg-muted">{{ t('runs_title') }}</h3>
                <ul class="divide-y divide-muted">
                    <RunItem
                        v-for="run in state.runs"
                        :key="run.id"
                        :run="run"
                        :now="now"
                        :cancelling="!!state.cancelling[run.id]"
                        @cancel="store.cancelRun(run.id)"
                        @open="openRun"
                    />
                </ul>
            </section>

            <div :aria-busy="state.listStatus === 'loading' || state.loadingMore ? 'true' : 'false'">
                <p v-if="state.listStatus === 'loading' && !state.items.length" class="px-4 py-6 text-center text-fg-muted">
                    {{ t('notifications_loading') }}
                </p>

                <div v-else-if="state.listStatus === 'error' && !state.items.length" class="grid justify-items-center gap-2 px-4 py-6 text-center" role="alert">
                    <p class="text-danger-fg">{{ t('notifications_error') }}</p>
                    <button type="button" class="larsadmin-btn larsadmin-btn-sm" @click="store.retry()">{{ t('notifications_retry') }}</button>
                </div>

                <p v-else-if="state.loaded && !state.items.length" class="px-4 py-6 text-center text-fg-muted">
                    {{ t('notifications_empty') }}
                </p>

                <ul v-else ref="list" class="divide-y divide-muted">
                    <NotificationItem
                        v-for="item in state.items"
                        :key="item.id"
                        :item="item"
                        :now="now"
                        :timezone="state.timezone"
                        :busy="!!busy[item.id]"
                        @open="openItem"
                        @read="markRead"
                        @remove="remove"
                    />
                </ul>

                <div v-if="state.nextCursor" class="border-t border-muted px-4 py-2">
                    <p v-if="state.moreError" class="mb-2 text-xs text-danger-fg" role="alert">{{ t('notifications_error') }}</p>
                    <button type="button" class="larsadmin-btn larsadmin-btn-sm larsadmin-btn-block" :disabled="state.loadingMore" @click="loadMore">
                        {{ state.loadingMore ? t('notifications_loading') : t('notifications_load_more') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
