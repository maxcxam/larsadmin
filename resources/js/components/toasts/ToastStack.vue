<script setup>
import { computed, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from '../../composables/useI18n'
import { useToasts } from '../../composables/useToasts'
import { isPanelHref, safeHref } from '../notifications/notifications'
import LevelIcon from '../notifications/LevelIcon.vue'
import IconX from '../icons/IconX.vue'

// The panel's toast stack (bottom left). Flash toasts (shared prop `toast`, a string or { message, type })
// keep their 1.3 behaviour: shown for 4 s. Errors go to an assertive live region, everything else to a
// polite one; both regions stay mounted so screen readers announce additions. Timers pause on hover/focus.
const page = usePage()
const { t, tc } = useI18n()
const toasts = useToasts()

watch(
    () => page.props.toast,
    (value) => {
        if (!value) return

        const toast = typeof value === 'string' ? { message: value, type: 'success' } : { type: 'success', ...value }

        toasts.push({ message: toast.message, type: toast.type, timeout: 4000 })
    },
    { immediate: true },
)

const LEVEL = { success: 'success', info: 'info', warning: 'warning', error: 'danger' }

const errors = computed(() => toasts.visible.value.filter((toast) => toast.type === 'error'))
const others = computed(() => toasts.visible.value.filter((toast) => toast.type !== 'error'))

function linkOf(toast) {
    const href = toast.link ? safeHref(toast.link.href) : null

    return href ? { ...toast.link, href } : null
}

function open(toast, event) {
    const link = linkOf(toast)

    if (!link) return

    link.onOpen?.()

    if (link.download) {
        toasts.dismiss(toast.id)

        return // the <a download> does the rest
    }

    event.preventDefault()
    toasts.dismiss(toast.id)

    if (isPanelHref(link.href, page.props.larsadmin?.path)) router.visit(link.href)
    else window.location.assign(link.href)
}
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 left-4 z-40 flex max-w-[calc(100vw-2rem)] flex-col gap-2 sm:max-w-sm">
        <template v-for="region in [{ list: errors, live: 'assertive' }, { list: others, live: 'polite' }]" :key="region.live">
            <div :aria-live="region.live" aria-relevant="additions text" class="flex flex-col gap-2">
                <TransitionGroup
                    enter-from-class="translate-y-2 opacity-0"
                    enter-active-class="transition motion-reduce:transition-none"
                    leave-to-class="opacity-0"
                    leave-active-class="transition motion-reduce:transition-none"
                >
                    <div
                        v-for="toast in region.list"
                        :key="toast.id"
                        class="pointer-events-auto flex items-start gap-2 rounded-md border border-default bg-canvas py-2 pr-2 pl-3 text-sm text-fg shadow-overlay"
                        @mouseenter="toasts.pause(toast.id)"
                        @mouseleave="toasts.resume(toast.id)"
                        @focusin="toasts.pause(toast.id)"
                        @focusout="toasts.resume(toast.id)"
                    >
                        <LevelIcon :level="LEVEL[toast.type]" class="mt-0.5" />
                        <div class="min-w-0 flex-1">
                            <span class="sr-only">{{ t(`notifications_level_${LEVEL[toast.type]}`) }}:</span>
                            <p v-if="toast.title" class="font-semibold break-words">{{ toast.title }}</p>
                            <p v-if="toast.message" class="break-words whitespace-pre-line">{{ toast.message }}</p>
                            <a
                                v-if="linkOf(toast)"
                                :href="linkOf(toast).href"
                                :download="linkOf(toast).download ? '' : undefined"
                                class="mt-1 inline-block font-semibold text-accent-fg hover:underline"
                                @click="open(toast, $event)"
                            >
                                {{ linkOf(toast).label }}
                            </a>
                        </div>
                        <button type="button" class="rounded p-1 text-fg-muted hover:bg-neutral-muted" :aria-label="t('dismiss')" @click="toasts.dismiss(toast.id)">
                            <IconX />
                        </button>
                    </div>
                </TransitionGroup>
                <p
                    v-if="region.live === 'polite' && toasts.hiddenCount.value > 0"
                    class="pointer-events-auto self-start rounded-md border border-default bg-canvas px-3 py-1 text-xs text-fg-muted shadow-overlay"
                >
                    {{ tc('notifications_more_toasts', toasts.hiddenCount.value) }}
                </p>
            </div>
        </template>
    </div>
</template>
