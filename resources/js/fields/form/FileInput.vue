<script setup>
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, useId, watch } from 'vue'
import { useI18n } from '../../composables/useI18n'
import {
    emptyValue,
    focusIndexAfterRemoval,
    formatSize,
    isRasterImage,
    maxFileCount,
    maxFileSize,
    precheck,
    storedAnnouncement,
    storedToggle,
    toggleStored,
} from '../files'
import { useFileLinks } from '../display/fileLinks'
import IconAlert from '../../components/icons/IconAlert.vue'
import IconFile from '../../components/icons/IconFile.vue'
import IconUpload from '../../components/icons/IconUpload.vue'

// Form control for `file-field` / `image-field` (design 1.1-files §7.2, §7.3).
// v-model is the §4.2 payload { present: 1, keep: [token…], upload: [File…] }; stored files come from `field.files`.
const props = defineProps({
    field: { type: Object, required: true },
    invalid: { type: Boolean, default: false },
    // { [uploadIndex]: message } from `{attr}.upload.{i}` validation errors
    uploadErrors: { type: Object, default: () => ({}) },
})

const model = defineModel({ type: Object, default: null })

const { t, tc, locale } = useI18n()
const { follow, status: linkStatus } = useFileLinks(() => props.field)
const uid = useId()
const hintId = `${uid}-hint`
const root = ref(null)
const input = ref(null)

const value = computed(() => model.value ?? emptyValue(props.field))
const existing = computed(() => props.field.files ?? [])
const keep = computed(() => value.value.keep ?? [])
const pending = computed(() => value.value.upload ?? [])
const multiple = computed(() => !!props.field.multiple)
const readonly = computed(() => !!props.field.readonly)
const removable = computed(() => props.field.removable !== false)

// Single field with a new file chosen: the stored file is replaced on save (server: upload wins over keep).
const replacing = computed(() => !multiple.value && pending.value.length > 0)
const isRemoved = (file) => replacing.value || !keep.value.includes(file.token)
const keptCount = computed(() => existing.value.filter((file) => keep.value.includes(file.token)).length)
const hasStored = computed(() => existing.value.some((file) => !isRemoved(file)) || pending.value.length > 0)

const chooseLabel = computed(() => {
    if (multiple.value) return t('files_choose_multiple')

    return hasStored.value ? t('files_replace') : t('files_choose')
})

const hint = computed(() => {
    const size = maxFileSize(props.field)
    const types = (props.field.types ?? []).join(', ')
    const parts = []

    if (size !== null) parts.push(types ? t('files_limits', { size: formatSize(size, locale.value), types }) : t('files_max_size', { size: formatSize(size, locale.value) }))
    if (multiple.value && maxFileCount(props.field) !== null) parts.push(t('files_max_files', { max: maxFileCount(props.field) }))

    return parts.join(' ')
})

// `known` (tokens the form had at load, CRF-1) is carried through unchanged.
function update(patch) {
    const known = value.value.known

    model.value = { present: 1, ...(Array.isArray(known) ? { known: [...known] } : {}), keep: [...keep.value], upload: [...pending.value], ...patch }
}

// Stable keys for pending File objects.
const keys = new WeakMap()
let nextKey = 0
const keyOf = (file) => {
    if (!keys.has(file)) keys.set(file, `new-${nextKey++}`)

    return keys.get(file)
}

// Client previews of pending raster images; object URLs are revoked when the file leaves the list or on unmount.
const previews = shallowRef(new Map())

watch(
    pending,
    (files) => {
        const next = new Map()

        for (const file of files) {
            if (previews.value.has(file)) next.set(file, previews.value.get(file))
            else if (isRasterImage(file) && typeof URL !== 'undefined' && URL.createObjectURL) next.set(file, URL.createObjectURL(file))
        }

        for (const [file, url] of previews.value) if (!next.has(file)) URL.revokeObjectURL(url)

        previews.value = next
    },
    { immediate: true },
)

onBeforeUnmount(() => {
    for (const url of previews.value.values()) URL.revokeObjectURL(url)
})

// Per-upload server errors refer to upload indices of the last submit; they go stale once the list changes.
const errorsFresh = ref(true)
watch(() => props.uploadErrors, () => (errorsFresh.value = true))
const uploadError = (index) => (errorsFresh.value ? props.uploadErrors?.[index] ?? null : null)
const uploadErrorId = (index) => `${uid}-upload-${index}`
const showInvalid = computed(() => props.invalid || pending.value.some((file, index) => uploadError(index)))

const describedBy = computed(() => {
    const ids = [hintId]

    if (props.invalid) ids.push(`${props.field.attribute}-error`)
    pending.value.forEach((file, index) => uploadError(index) && ids.push(uploadErrorId(index)))

    return ids.join(' ')
})

// Polite announcements (WCAG 4.1.3); cleared first so a repeated message is read again.
const announcement = ref('')
const rejections = ref([])

function announce(message) {
    announcement.value = ''
    nextTick(() => (announcement.value = message))
}

const rejectionParams = (rejection) =>
    ['files_too_large', 'files_request_too_large'].includes(rejection.key)
        ? { ...rejection.params, max: formatSize(rejection.params.max, locale.value) }
        : rejection.params

function add(files) {
    if (readonly.value || !files?.length) return

    const { accepted, rejected } = precheck(files, props.field, { kept: keptCount.value, pending: pending.value })

    rejections.value = rejected.map((rejection) => t(rejection.key, rejectionParams(rejection)))

    if (accepted.length) {
        update({ upload: multiple.value ? [...pending.value, ...accepted] : [accepted[0]] })
        errorsFresh.value = false
    }

    announce([accepted.length ? tc('files_added', accepted.length) : '', ...rejections.value].filter(Boolean).join(' '))
}

function onChange(event) {
    add(Array.from(event.target.files ?? []))
    // Allows picking the same file again after removing it; the payload holds the File objects.
    event.target.value = ''
}

// After a row disappears, focus the button now at the same position, else the native input (§7.3).
function refocus(position) {
    nextTick(() => {
        const buttons = root.value?.querySelectorAll('[data-file-action]') ?? []
        const index = focusIndexAfterRemoval(position, buttons.length)

        ;(index >= 0 ? buttons[index] : input.value)?.focus()
    })
}

function positionOf(event) {
    return Array.from(root.value?.querySelectorAll('[data-file-action]') ?? []).indexOf(event.currentTarget)
}

// Remove / Undo share one button per row (CRF-5): Vue patches its label, so keyboard focus stays on it.
function toggleStoredFile(file) {
    const next = toggleStored(keep.value, file.token, existing.value.map((stored) => stored.token))
    const message = storedAnnouncement(!next.includes(file.token), file.name)

    update({ keep: next })
    announce(t(message.key, message.params))
}

const toggleFor = (file) => {
    const toggle = storedToggle(isRemoved(file), file.name)

    return { label: t(toggle.label), aria: t(toggle.aria.key, toggle.aria.params) }
}

function removePending(event, index) {
    const position = positionOf(event)

    update({ upload: pending.value.filter((_, i) => i !== index) })
    errorsFresh.value = false
    rejections.value = []
    refocus(position)
}

// Drag & drop upload zone (no re-ordering in 1.1, D12). Counter handles dragenter/leave on child elements.
const dragging = ref(false)
let depth = 0
const carriesFiles = (event) => Array.from(event.dataTransfer?.types ?? []).includes('Files')

function onDragEnter(event) {
    if (readonly.value || !carriesFiles(event)) return
    event.preventDefault()
    depth++
    dragging.value = true
}

function onDragOver(event) {
    if (readonly.value || !carriesFiles(event)) return
    event.preventDefault()
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy'
}

function onDragLeave() {
    depth = Math.max(0, depth - 1)
    if (depth === 0) dragging.value = false
}

function onDrop(event) {
    if (readonly.value || !carriesFiles(event)) return
    event.preventDefault()
    depth = 0
    dragging.value = false
    add(Array.from(event.dataTransfer?.files ?? []))
}

const storedLinkAttrs = (file) =>
    file.isImage
        ? { target: '_blank', rel: 'noopener noreferrer', 'aria-label': t('files_open_named', { name: file.name }) }
        : { download: file.name, 'aria-label': t('files_download_named', { name: file.name }) }
</script>

<template>
    <div ref="root" class="rounded-md border bg-canvas" :class="showInvalid ? 'border-danger-fg' : 'border-default'">
        <ul v-if="existing.length || pending.length" class="divide-y divide-muted">
            <li
                v-for="(file, fileIndex) in existing"
                :key="file.token"
                class="flex min-w-0 items-center gap-2 px-3 py-2"
            >
                <img
                    v-if="file.isImage && file.thumbnailUrl"
                    :src="file.thumbnailUrl"
                    :alt="t('files_preview_alt', { name: file.name })"
                    width="32"
                    height="32"
                    loading="lazy"
                    decoding="async"
                    class="size-8 shrink-0 rounded-md border border-default bg-canvas-subtle object-cover"
                    :class="{ 'opacity-50': isRemoved(file) }"
                >
                <IconFile v-else class="text-fg-muted" />

                <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 items-center gap-2">
                        <component
                            :is="file.url && !isRemoved(file) ? 'a' : 'span'"
                            v-bind="file.url && !isRemoved(file) ? { href: file.url, ...storedLinkAttrs(file) } : {}"
                            class="min-w-0 truncate"
                            :class="isRemoved(file) ? 'text-fg-muted line-through' : file.url ? 'text-accent-fg hover:underline' : ''"
                            @click="file.url && !isRemoved(file) && follow($event, fileIndex)"
                        >{{ file.name }}</component>
                        <span v-if="file.size !== null && file.size !== undefined" class="shrink-0 text-xs text-fg-muted">{{ formatSize(file.size, locale) }}</span>
                    </div>
                    <p v-if="isRemoved(file)" class="text-xs text-fg-muted">{{ t('files_removed_on_save', { name: file.name }) }}</p>
                </div>

                <template v-if="!readonly && !replacing">
                    <button
                        v-if="removable || isRemoved(file)"
                        type="button"
                        data-file-action
                        class="larsadmin-btn larsadmin-btn-sm"
                        :class="{ 'text-danger-fg': !isRemoved(file) }"
                        :aria-label="toggleFor(file).aria"
                        @click="toggleStoredFile(file)"
                    >
                        {{ toggleFor(file).label }}
                    </button>
                </template>
            </li>

            <li v-for="(file, index) in pending" :key="keyOf(file)" class="px-3 py-2">
                <div class="flex min-w-0 items-center gap-2">
                    <img
                        v-if="previews.get(file)"
                        :src="previews.get(file)"
                        :alt="t('files_preview_alt', { name: file.name })"
                        width="32"
                        height="32"
                        class="size-8 shrink-0 rounded-md border border-default bg-canvas-subtle object-cover"
                    >
                    <IconFile v-else class="text-fg-muted" />

                    <div class="flex min-w-0 flex-1 items-center gap-2">
                        <span class="min-w-0 truncate">{{ file.name }}</span>
                        <span class="larsadmin-label larsadmin-label-success shrink-0">{{ t('files_new') }}</span>
                        <span class="shrink-0 text-xs text-fg-muted">{{ formatSize(file.size, locale) }}</span>
                    </div>

                    <button
                        v-if="!readonly"
                        type="button"
                        data-file-action
                        class="larsadmin-btn larsadmin-btn-sm text-danger-fg"
                        :aria-label="t('files_remove_named', { name: file.name })"
                        :aria-describedby="uploadError(index) ? uploadErrorId(index) : undefined"
                        @click="removePending($event, index)"
                    >
                        {{ t('files_remove') }}
                    </button>
                </div>
                <p v-if="uploadError(index)" :id="uploadErrorId(index)" class="mt-1 flex items-center gap-1 text-xs text-danger-fg">
                    <IconAlert />{{ uploadError(index) }}
                </p>
            </li>
        </ul>

        <p v-else-if="readonly" class="px-3 py-2 text-fg-muted">{{ t('files_none') }}</p>

        <div
            v-if="!readonly"
            class="m-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-3 py-3"
            :class="dragging ? 'border-solid border-accent-fg bg-accent-subtle' : 'border-dashed border-fg-subtle'"
            @dragenter="onDragEnter"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
        >
            <input
                :id="field.attribute"
                ref="input"
                type="file"
                class="peer sr-only"
                :accept="field.accept || undefined"
                :multiple="multiple"
                :aria-describedby="describedBy"
                :aria-invalid="showInvalid ? 'true' : undefined"
                @change="onChange"
            >
            <label
                :for="field.attribute"
                class="larsadmin-btn peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent-fg"
            >
                <IconUpload />{{ chooseLabel }}
            </label>
            <span class="text-fg-muted" :class="{ 'font-semibold text-fg': dragging }">
                {{ dragging ? t('files_drop_active') : t('files_drop_hint') }}
            </span>
            <p :id="hintId" class="w-full text-xs text-fg-muted">{{ hint }}</p>
        </div>

        <ul v-if="rejections.length" class="grid gap-0.5 border-t border-muted px-3 py-2 text-xs text-danger-fg">
            <li v-for="(message, index) in rejections" :key="index" class="flex items-center gap-1"><IconAlert />{{ message }}</li>
        </ul>

        <p class="sr-only" role="status" aria-live="polite">{{ announcement || (linkStatus ? t(linkStatus) : '') }}</p>
    </div>
</template>
