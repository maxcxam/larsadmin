<script setup>
import { computed } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { formComponent, isFieldUnavailable } from '../fields'
import { buildSubmission, fieldError, fieldErrors, fieldErrorsByKey, formatSize, isFileField, isMultipartField, uploadErrors } from '../fields/files'
import { isWrapped } from '../extensions/components'
import { useI18n } from '../composables/useI18n'
import FieldBlocks from './layout/FieldBlocks.vue'
import IconAlert from './icons/IconAlert.vue'

const props = defineProps({
    fields: { type: Array, required: true },
    // Server layout tree (panels/tabs); null renders every field in one box as before
    layout: { type: Array, default: null },
    action: { type: String, required: true },
    method: { type: String, default: 'post' },
    submitLabel: { type: String, required: true },
    cancelHref: { type: String, required: true },
    // 'create' | 'update' — forwarded to inputs that talk to JSON endpoints (BelongsTo options)
    context: { type: String, default: 'create' },
    resourceId: { type: [String, Number], default: null },
})

const { t, locale } = useI18n()
const page = usePage()

// ADR-008: keep via* params on store/update so the server can redirect back to the parent.
const submitUrl = computed(() => {
    const source = new URLSearchParams(page.url.split('?')[1] ?? '')
    const via = new URLSearchParams()

    for (const key of ['viaEntity', 'viaId', 'viaField']) {
        if (source.has(key)) via.set(key, source.get(key))
    }

    const query = via.toString()

    return query ? `${props.action}${props.action.includes('?') ? '&' : '?'}${query}` : props.action
})

// Field values live under `values`: an attribute named like a form method or property (post, get, put, delete, reset,
// errors...) would otherwise be shadowed by it and silently dropped from the request. Sent flat, as before.
const form = useForm({ values: Object.fromEntries(props.fields.map((field) => [field.attribute, field.value])) })

// Fail closed (ADR-032): extension fields whose component is missing or crashed are left out of the payload, so the
// server keeps the stored value. Reactive: a late extension makes its fields available again.
const excluded = computed(() => props.fields.filter(isFieldUnavailable).map((field) => field.attribute))

function submit() {
    const exclude = excluded.value
    const { method, multipart } = buildSubmission(props.fields, props.method, null, { exclude })

    if (!multipart) {
        // Without exclusions the transform returns the same data object: the request is the 1.4 one.
        form.transform((data) => buildSubmission(props.fields, props.method, data.values, { exclude }).data).submit(method, submitUrl.value, {
            preserveScroll: true,
        })

        return
    }

    // Forms with File/Image fields (ADR-014): multipart POST, updates spoofed with _method=PUT (PHP does not
    // parse multipart bodies of real PUT requests). Wire format per field: {attr}[present|keep|upload].
    form.transform((data) => buildSubmission(props.fields, props.method, data.values, { exclude }).data).submit(method, submitUrl.value, {
        preserveScroll: true,
        forceFormData: true,
        onHttpException: (response) => {
            if (response.status !== 413) return

            // post_max_size exceeded: Laravel answers 413 before any package code runs.
            router.replaceProp('toast', { type: 'error', message: t('files_request_too_large', { max: requestLimit() }) })

            return false
        },
    })
}

const multipartForm = computed(() => props.fields.some(isMultipartField))

// id for aria-describedby: the error message, else the help text.
function describedBy(field) {
    if (fieldError(form.errors, field.attribute)) return `${field.attribute}-error`

    return field.help ? `${field.attribute}-help` : null
}

// Extension and override components get the full form contract (design 1.5 §5.4); built-in components get
// exactly the 1.4 props, so nothing new falls through onto their root element.
function contractProps(field, component) {
    if (!isWrapped(component)) return {}

    return { errors: fieldErrors(form.errors, field.attribute), errorsByKey: fieldErrorsByKey(form.errors, field.attribute), describedBy: describedBy(field) }
}

function requestLimit() {
    const limits = props.fields.filter(isFileField).map((field) => field.uploadLimit?.request).filter((limit) => limit > 0)

    return limits.length ? formatSize(Math.min(...limits), locale.value) : '—'
}

const uploadPercent = computed(() => Math.round(form.progress?.percentage ?? 0))
</script>

<template>
    <form @submit.prevent="submit">
        <FieldBlocks :layout="layout" :fields="fields" :errors="form.errors">
            <template #field="{ field, stacked }">
                <div :class="stacked ? 'grid gap-2 px-4 py-4' : 'grid gap-2 px-4 py-4 md:grid-cols-4 md:gap-4'">
                    <label :for="field.attribute" class="pt-1 font-semibold">
                        {{ field.name }}
                        <span v-if="field.required" class="text-danger-fg" aria-hidden="true">*</span>
                        <span v-if="field.required" class="sr-only">({{ t('required') }})</span>
                    </label>

                    <div :class="stacked ? 'min-w-0' : 'min-w-0 md:col-span-3'">
                        <component
                            :is="formComponent(field)"
                            v-model="form.values[field.attribute]"
                            :field="field"
                            :invalid="!!fieldError(form.errors, field.attribute)"
                            :context="context"
                            :resource-id="resourceId"
                            v-bind="{ ...(isFileField(field) ? { uploadErrors: uploadErrors(form.errors, field.attribute) } : {}), ...contractProps(field, formComponent(field)) }"
                        />
                        <p v-if="fieldError(form.errors, field.attribute)" :id="`${field.attribute}-error`" class="mt-1.5 flex items-center gap-1 text-xs text-danger-fg" role="alert">
                            <IconAlert />{{ fieldError(form.errors, field.attribute) }}
                        </p>
                        <p v-else-if="field.help" :id="`${field.attribute}-help`" class="mt-1.5 text-xs text-fg-muted">{{ field.help }}</p>
                    </div>
                </div>
            </template>
        </FieldBlocks>

        <div class="mt-4 flex items-center justify-end gap-2">
            <div v-if="multipartForm && form.processing && form.progress" class="mr-auto flex items-center gap-2">
                <progress
                    class="h-2 w-40 accent-accent-emphasis"
                    max="100"
                    :value="uploadPercent"
                    :aria-label="t('files_uploading', { percent: uploadPercent })"
                >
                    {{ uploadPercent }}%
                </progress>
                <span class="text-xs text-fg-muted" aria-hidden="true">{{ t('files_uploading', { percent: uploadPercent }) }}</span>
            </div>
            <Link :href="cancelHref" class="larsadmin-btn">{{ t('cancel') }}</Link>
            <button type="submit" class="larsadmin-btn-primary" :disabled="form.processing">
                {{ submitLabel }}
            </button>
        </div>
    </form>
</template>
