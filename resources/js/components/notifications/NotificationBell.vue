<script setup>
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from '../../composables/useI18n'
import { useNotifications } from '../../composables/useNotifications'
import { badgeText } from './notifications'
import IconBell from '../icons/IconBell.vue'

// Header bell: a button whose accessible name carries the unread count, a numeric badge (aria-hidden, it
// repeats the name), a ring-dot while a background task runs, and a non-modal dialog (dropdown) with the list.
// Closes on Esc (focus back to the button), on a click outside and when focus leaves it with Tab.
// The dialog's content is a separate chunk, loaded on the first open (keeps app.js small).
const NotificationPanel = defineAsyncComponent(() => import('./NotificationPanel.vue'))

const { t, tc } = useI18n()
const store = useNotifications()
const state = store.state

const panelId = useId()
const titleId = useId()
const root = ref(null)
const button = ref(null)
const panel = ref(null)
const open = ref(false)

const badge = computed(() => badgeText(state.unread))
const label = computed(() => {
    const count = Number(state.unread) || 0
    const base = count > 0 ? tc('notifications_bell_unread', count) : t('notifications')

    return store.hasActiveRuns.value ? `${base}. ${t('runs_active')}` : base
})

function show() {
    open.value = true
}

// The panel emits 'ready' once mounted (after its chunk loaded): focus its heading (2.4.3).
function onReady(api) {
    (api ?? panel.value)?.focusHeading()
}

function close({ restoreFocus = true } = {}) {
    if (!open.value) return

    open.value = false

    if (restoreFocus) button.value?.focus()
}

function toggle() {
    open.value ? close() : show()
}

function onKeydown(event) {
    if (event.key === 'Escape' && open.value) {
        event.stopPropagation()
        close()
    }
}

function onPointerDown(event) {
    if (open.value && root.value && !root.value.contains(event.target)) close({ restoreFocus: false })
}

// Tab out of the dialog (focus moved somewhere else on the page) closes it without stealing focus back.
function onFocusOut(event) {
    if (open.value && event.relatedTarget && root.value && !root.value.contains(event.relatedTarget)) close({ restoreFocus: false })
}

let removeNavigateListener = null

onMounted(() => {
    document.addEventListener('pointerdown', onPointerDown)
    removeNavigateListener = router.on('navigate', () => close({ restoreFocus: false }))
})

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown)
    removeNavigateListener?.()
})
</script>

<template>
    <div v-if="state.enabled" ref="root" class="sm:relative" @keydown="onKeydown" @focusout="onFocusOut">
        <button
            ref="button"
            type="button"
            class="relative rounded-md border border-white/20 p-1.5 hover:bg-white/10"
            aria-haspopup="dialog"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="open ? panelId : undefined"
            :aria-label="label"
            @click="toggle"
        >
            <IconBell />
            <span
                v-if="badge"
                class="absolute -top-1.5 -right-1.5 min-w-[1.125rem] rounded-full bg-danger-emphasis px-1 text-center text-[10px] leading-[1.125rem] font-semibold text-fg-on-emphasis ring-2 ring-header"
                aria-hidden="true"
            >
                {{ badge }}
            </span>
            <span
                v-if="store.hasActiveRuns.value"
                class="absolute -bottom-1 -left-1 size-2.5 rounded-full border-2 border-accent-emphasis bg-header motion-safe:animate-pulse"
                aria-hidden="true"
            />
        </button>

        <div
            v-if="open"
            class="fixed inset-x-0 top-14 z-50 border-y border-default bg-canvas shadow-overlay sm:absolute sm:inset-x-auto sm:top-full sm:right-0 sm:mt-2 sm:w-96 sm:rounded-md sm:border"
        >
            <NotificationPanel :id="panelId" ref="panel" :title-id="titleId" @close="close" @ready="onReady" />
        </div>
    </div>
</template>
