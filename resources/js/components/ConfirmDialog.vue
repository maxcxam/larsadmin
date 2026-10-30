<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { useI18n } from '../composables/useI18n'
import IconAlert from './icons/IconAlert.vue'

// Accessible replacement for window.confirm(). Mount with v-if; emits `confirm` or `cancel`.
// Focus starts on "Cancel" (safe default), is trapped inside, and returns to the opener on close.
const props = defineProps({
    message: { type: String, required: true },
    title: { type: String, default: null },
    confirmLabel: { type: String, default: null },
    cancelLabel: { type: String, default: null },
    danger: { type: Boolean, default: true },
})

const emit = defineEmits(['confirm', 'cancel'])

const { t } = useI18n()

const titleId = useId()
const descId = useId()
const dialog = ref(null)
const returnTarget = document.activeElement

const focusable = () =>
    [...(dialog.value?.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') ?? [])]
        .filter((el) => el.offsetParent !== null)

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation()
        emit('cancel')
    } else if (event.key === 'Tab') {
        const list = focusable()
        if (!list.length) return
        const first = list[0]
        const last = list[list.length - 1]
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault()
            last.focus()
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault()
            first.focus()
        }
    }
}

onMounted(async () => {
    document.body.style.overflow = 'hidden'
    await nextTick()
    dialog.value?.querySelector('[data-cancel]')?.focus()
})

onBeforeUnmount(() => {
    document.body.style.overflow = ''
    if (returnTarget instanceof HTMLElement && document.contains(returnTarget)) returnTarget.focus()
})
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center" @mousedown.self="emit('cancel')">
            <div
                ref="dialog"
                role="alertdialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :aria-describedby="descId"
                class="larsadmin-box w-full max-w-md bg-canvas shadow-overlay"
                @keydown="onKeydown"
            >
                <div class="flex items-center gap-2 border-b border-muted px-4 py-3">
                    <IconAlert v-if="props.danger" class="text-danger-fg" />
                    <h2 :id="titleId" class="text-base font-semibold" :class="{ 'text-danger-fg': props.danger }">{{ title ?? t('confirm_title') }}</h2>
                </div>

                <p :id="descId" class="px-4 py-4 text-sm">{{ message }}</p>

                <div class="flex items-center justify-end gap-2 border-t border-muted px-4 py-3">
                    <button type="button" class="larsadmin-btn" data-cancel @click="emit('cancel')">{{ cancelLabel ?? t('cancel') }}</button>
                    <button type="button" :class="props.danger ? 'larsadmin-btn-danger' : 'larsadmin-btn-primary'" @click="emit('confirm')">
                        {{ confirmLabel ?? t('confirm') }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
