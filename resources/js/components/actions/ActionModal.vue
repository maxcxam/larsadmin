<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { formComponent } from '../../fields'
import { useI18n } from '../../composables/useI18n'
import { useLarsadmin } from '../../composables/useLarsadmin'
import IconAlert from '../icons/IconAlert.vue'
import IconX from '../icons/IconX.vue'

// Accessible dialog that runs an action. Mount with v-if="pendingAction"; emits `close` when done/cancelled.
// With `withoutConfirmation` and no fields it runs immediately on mount (no dialog UI is shown).
const props = defineProps({
    action: { type: Object, required: true },
    entity: { type: String, required: true },
    // array of ids, or 'all'. Ignored (sent as []) for standalone actions.
    resources: { type: [Array, String], default: () => [] },
    search: { type: String, default: null },
    filters: { type: [Object, Array, String], default: null },
})

const emit = defineEmits(['close'])

const { t } = useI18n()
const { entityUrl } = useLarsadmin()

const titleId = useId()
const descId = useId()
const dialog = ref(null)
const returnTarget = document.activeElement

const fields = computed(() => props.action.fields ?? [])
const immediate = computed(() => props.action.withoutConfirmation && fields.value.length === 0)

const form = useForm({
    resources: props.action.standalone ? [] : props.resources,
    ...(props.search ? { search: props.search } : {}),
    ...(props.filters ? { filters: props.filters } : {}),
    ...Object.fromEntries(fields.value.map((field) => [field.attribute, field.value ?? null])),
})

function close() {
    emit('close')
}

function submit() {
    form.post(entityUrl(props.entity, 'actions', props.action.uriKey), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: close,
        onError: () => {
            // Immediate runs have no dialog to show errors in; the server toast carries the message
            if (immediate.value) return close()
            nextTick(() => dialog.value?.querySelector('.larsadmin-input-invalid')?.focus())
        },
    })
}

const focusable = () =>
    [...(dialog.value?.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') ?? [])]
        .filter((el) => el.offsetParent !== null)

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation()
        if (!form.processing) close()
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
    if (immediate.value) return submit()

    document.body.style.overflow = 'hidden'
    await nextTick()
    // First field, else the cancel button (safe default for destructive actions)
    const target = dialog.value?.querySelector('input, select, textarea') ?? dialog.value?.querySelector('[data-cancel]')
    target?.focus()
})

onBeforeUnmount(() => {
    document.body.style.overflow = ''
    if (returnTarget instanceof HTMLElement && document.contains(returnTarget)) returnTarget.focus()
})
</script>

<template>
    <Teleport v-if="!immediate" to="body">
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center" @mousedown.self="!form.processing && close()">
            <div
                ref="dialog"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :aria-describedby="action.confirmText ? descId : undefined"
                class="larsadmin-box w-full max-w-lg bg-canvas shadow-overlay"
                @keydown="onKeydown"
            >
                <form @submit.prevent="submit">
                    <div class="flex items-center justify-between gap-2 border-b border-muted px-4 py-3">
                        <h2 :id="titleId" class="text-base font-semibold" :class="{ 'text-danger-fg': action.destructive }">{{ action.name }}</h2>
                        <button type="button" class="rounded p-1 text-fg-muted hover:bg-neutral-muted" :aria-label="t('act.close')" @click="close">
                            <IconX />
                        </button>
                    </div>

                    <div class="space-y-4 px-4 py-4">
                        <p v-if="action.confirmText" :id="descId" class="text-sm">{{ action.confirmText }}</p>

                        <div v-for="field in fields" :key="field.attribute" class="grid gap-1.5">
                            <label :for="field.attribute" class="font-semibold">
                                {{ field.name }}
                                <span v-if="field.required" class="text-danger-fg" aria-hidden="true">*</span>
                                <span v-if="field.required" class="sr-only">({{ t('required') }})</span>
                            </label>
                            <component
                                :is="formComponent(field)"
                                v-model="form[field.attribute]"
                                :field="field"
                                :invalid="!!form.errors[field.attribute]"
                                v-bind="field.component === 'belongs-to-field' ? { context: 'action', action: action.uriKey } : {}"
                            />
                            <p v-if="form.errors[field.attribute]" class="flex items-center gap-1 text-xs text-danger-fg" role="alert">
                                <IconAlert />{{ form.errors[field.attribute] }}
                            </p>
                            <p v-else-if="field.help" class="text-xs text-fg-muted">{{ field.help }}</p>
                        </div>

                        <p v-if="form.errors.resources" class="flex items-center gap-1 text-xs text-danger-fg" role="alert">
                            <IconAlert />{{ form.errors.resources }}
                        </p>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-muted px-4 py-3">
                        <button type="button" class="larsadmin-btn" data-cancel :disabled="form.processing" @click="close">
                            {{ action.cancelButtonText || t('act.cancel') }}
                        </button>
                        <button type="submit" :class="action.destructive ? 'larsadmin-btn-danger' : 'larsadmin-btn-primary'" :disabled="form.processing">
                            {{ action.confirmButtonText || t('act.run') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>
