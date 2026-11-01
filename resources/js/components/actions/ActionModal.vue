<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { formComponent } from '../../fields'
import { isWrapped } from '../../extensions/components'
import { actionPayload, fieldContract, fieldMessage } from './actionPayload'
import { useI18n } from '../../composables/useI18n'
import { useLarsadmin } from '../../composables/useLarsadmin'
import IconAlert from '../icons/IconAlert.vue'
import IconHistory from '../icons/IconHistory.vue'
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
const queuedId = useId()
// Prefix for the ids of field messages (aria-describedby): the modal sits on top of a page with its own ids.
const fieldIdPrefix = useId()
const dialog = ref(null)
const returnTarget = document.activeElement

const fields = computed(() => props.action.fields ?? [])
const immediate = computed(() => props.action.withoutConfirmation && fields.value.length === 0)
// Queued actions (1.4): the dialog says the run continues in the background; after dispatch the server
// answers with the 'queued' toast and the run is followed in the bell (shared prop larsadmin.run).
const queued = computed(() => props.action.queued === true)
const describedBy = computed(() => [props.action.confirmText ? descId : null, queued.value ? queuedId : null].filter(Boolean).join(' ') || undefined)

// The record the action runs on, when it is exactly one (detail page, one selected row); else null.
const resourceId = computed(() => (!props.action.standalone && Array.isArray(props.resources) && props.resources.length === 1 ? props.resources[0] : null))
// `action` prop of extension form components (design 1.6 §8.3); forms outside an action pass null.
const actionContext = computed(() => ({ uriKey: props.action.uriKey, entity: props.entity }))

const form = useForm({
    resources: props.action.standalone ? [] : props.resources,
    ...(props.search ? { search: props.search } : {}),
    ...(props.filters ? { filters: props.filters } : {}),
    // Under `values`, as in EntityForm: a field named like a form method (post, reset...) must not be shadowed.
    values: Object.fromEntries(fields.value.map((field) => [field.attribute, field.value ?? null])),
})

const errorId = (field) => `${fieldIdPrefix}-${field.attribute}-error`
const helpId = (field) => `${fieldIdPrefix}-${field.attribute}-help`
const message = (field) => fieldMessage(form.errors, field.attribute)

// Props for one field's form component. Built-in components get exactly their 1.4 props (BelongsTo, which is not
// overridable: the relatable options of the action); extension and override components get the full form contract
// of design 1.5 §5.4 plus `action` (design 1.6 §8.3), as in EntityForm.
function fieldProps(field) {
    if (!isWrapped(formComponent(field))) return field.component === 'belongs-to-field' ? { context: 'action', action: props.action.uriKey } : {}

    const describedBy = message(field) ? errorId(field) : field.help ? helpId(field) : null

    return fieldContract(form.errors, field, { describedBy, resourceId: resourceId.value, action: actionContext.value })
}

function close() {
    emit('close')
}

function submit() {
    // Fail closed: unavailable extension fields are not sent; file fields make the request multipart.
    const { multipart } = actionPayload(fields.value, null)

    form.transform(({ values, ...data }) => actionPayload(fields.value, { ...data, ...values }).data).post(entityUrl(props.entity, 'actions', props.action.uriKey), {
        preserveScroll: true,
        preserveState: true,
        ...(multipart ? { forceFormData: true } : {}),
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
                :aria-describedby="describedBy"
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
                        <p v-if="queued" :id="queuedId" class="flex items-center gap-1.5 text-xs text-fg-muted">
                            <IconHistory />{{ t('actions_queued_hint') }}
                        </p>

                        <div v-for="field in fields" :key="field.attribute" class="grid gap-1.5">
                            <label :for="field.attribute" class="font-semibold">
                                {{ field.name }}
                                <span v-if="field.required" class="text-danger-fg" aria-hidden="true">*</span>
                                <span v-if="field.required" class="sr-only">({{ t('required') }})</span>
                            </label>
                            <component
                                :is="formComponent(field)"
                                v-model="form.values[field.attribute]"
                                :field="field"
                                :invalid="!!message(field)"
                                v-bind="fieldProps(field)"
                            />
                            <p v-if="message(field)" :id="errorId(field)" class="flex items-center gap-1 text-xs text-danger-fg" role="alert">
                                <IconAlert />{{ message(field) }}
                            </p>
                            <p v-else-if="field.help" :id="helpId(field)" class="text-xs text-fg-muted">{{ field.help }}</p>
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
