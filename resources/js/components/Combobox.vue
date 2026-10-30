<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from '../composables/useI18n'
import { useJsonResource } from '../composables/useJsonResource'
import IconAlert from './icons/IconAlert.vue'
import IconCheck from './icons/IconCheck.vue'
import IconChevronDown from './icons/IconChevronDown.vue'
import IconX from './icons/IconX.vue'

const props = defineProps({
    modelValue: { type: [String, Number], default: null },
    // Initial { id, title } of the current value (label shown before any fetch)
    selected: { type: Object, default: null },
    // JSON options endpoint: GET -> { data: [{ id, title }], hasMore }
    url: { type: String, required: true },
    // Extra query params (e.g. { context, resourceId }); null/undefined values are skipped
    params: { type: Object, default: () => ({}) },
    searchable: { type: Boolean, default: false },
    nullable: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    placeholder: { type: String, default: null },
    id: { type: String, default: null },
})

const emit = defineEmits(['update:modelValue', 'select'])

const { t } = useI18n()

const DEBOUNCE_MS = 300

const uid = useId()
const listboxId = `${uid}-listbox`
const optionId = (index) => `${uid}-option-${index}`

const root = ref(null)
const control = ref(null)
const listbox = ref(null)

const open = ref(false)
const query = ref('')
const options = ref([])
const hasMore = ref(false)
const page = ref(1)
const resource = useJsonResource()
const status = resource.status // idle | loading | error | forbidden
const loadingMore = ref(false)
const active = ref(-1)
const current = ref(props.selected ?? null)

let timer = null

watch(
    () => props.modelValue,
    (value) => {
        if (value === null || value === undefined || value === '') current.value = null
        else if (current.value?.id !== value) current.value = props.selected?.id === value ? props.selected : { id: value, title: String(value) }
    },
)

const label = computed(() => (current.value?.hidden ? t('hidden_record') : (current.value?.title ?? null)))
const hasValue = computed(() => props.modelValue !== null && props.modelValue !== undefined && props.modelValue !== '')
const showClear = computed(() => props.nullable && hasValue.value && !props.disabled)
// Rows of the popup: the options plus a synthetic "load more" row.
const rowCount = computed(() => options.value.length + (hasMore.value ? 1 : 0))
const inputValue = computed(() => (open.value ? query.value : (label.value ?? '')))
const placeholderText = computed(() => (open.value && label.value ? label.value : (props.placeholder ?? t('choose_option'))))
const activeDescendant = computed(() => (open.value && active.value >= 0 ? optionId(active.value) : undefined))

function buildUrl(pageNumber) {
    const search = new URLSearchParams()

    for (const [key, value] of Object.entries(props.params ?? {})) {
        if (value !== null && value !== undefined && value !== '') search.set(key, String(value))
    }

    if (props.searchable && query.value.trim() !== '') search.set('search', query.value.trim())
    if (pageNumber > 1) search.set('page', String(pageNumber))

    const glue = props.url.includes('?') ? '&' : '?'

    return `${props.url}${glue}${search.toString()}`
}

function scrollActiveIntoView() {
    if (!activeDescendant.value) return

    listbox.value?.querySelector(`[id="${activeDescendant.value}"]`)?.scrollIntoView({ block: 'nearest' })
}

async function load(pageNumber = 1) {
    if (pageNumber > 1) loadingMore.value = true

    const body = await resource.load(buildUrl(pageNumber), { background: pageNumber > 1 })

    // Superseded or cancelled: a newer request owns the state now.
    if (body === undefined) return

    loadingMore.value = false

    if (body === null) return

    const data = Array.isArray(body.data) ? body.data : []
    options.value = pageNumber === 1 ? data : [...options.value, ...data]
    hasMore.value = !!body.hasMore
    page.value = pageNumber

    if (pageNumber === 1) {
        const selectedIndex = options.value.findIndex((option) => option.id === props.modelValue)
        active.value = selectedIndex >= 0 ? selectedIndex : options.value.length ? 0 : -1
        await nextTick()
        scrollActiveIntoView()
    }
}

function scheduleSearch() {
    clearTimeout(timer)
    timer = setTimeout(() => load(1), DEBOUNCE_MS)
}

function onOutside(event) {
    if (root.value && !root.value.contains(event.target)) close()
}

function show() {
    if (props.disabled || open.value) return

    open.value = true
    query.value = ''
    options.value = []
    hasMore.value = false
    active.value = -1
    document.addEventListener('pointerdown', onOutside)
    load(1)
}

function close(restoreFocus = false) {
    if (!open.value) return

    open.value = false
    clearTimeout(timer)
    resource.cancel()
    loadingMore.value = false
    document.removeEventListener('pointerdown', onOutside)
    if (restoreFocus) control.value?.focus()
}

function onFocusOut(event) {
    if (root.value && !root.value.contains(event.relatedTarget)) close()
}

function choose(option) {
    current.value = option
    emit('update:modelValue', option.id)
    emit('select', option)
    close(true)
}

function clear() {
    current.value = null
    emit('update:modelValue', null)
    emit('select', null)
    close()
    control.value?.focus()
}

function activate(index) {
    if (index === options.value.length && hasMore.value) {
        if (!loadingMore.value) load(page.value + 1)

        return
    }

    const option = options.value[index]
    if (option) choose(option)
}

function setActive(index) {
    if (rowCount.value === 0) return

    active.value = (index + rowCount.value) % rowCount.value
    nextTick(scrollActiveIntoView)
}

function onInput(event) {
    query.value = event.target.value

    if (!open.value) {
        const typed = query.value
        show()
        query.value = typed
        // show() already fired a search for an empty query; re-run with the typed text.
        scheduleSearch()

        return
    }

    scheduleSearch()
}

function onKeydown(event) {
    if (props.disabled) return

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault()
            if (!open.value) show()
            else setActive(active.value < 0 ? 0 : active.value + 1)
            break
        case 'ArrowUp':
            event.preventDefault()
            if (!open.value) show()
            else setActive(active.value < 0 ? rowCount.value - 1 : active.value - 1)
            break
        case 'Home':
            if (open.value && (!props.searchable || query.value === '')) {
                event.preventDefault()
                setActive(0)
            }
            break
        case 'End':
            if (open.value && (!props.searchable || query.value === '')) {
                event.preventDefault()
                setActive(rowCount.value - 1)
            }
            break
        case 'Enter':
            event.preventDefault()
            if (!open.value) show()
            else if (active.value >= 0) activate(active.value)
            break
        case ' ':
            if (!props.searchable) {
                event.preventDefault()
                if (!open.value) show()
                else if (active.value >= 0) activate(active.value)
            }
            break
        case 'Escape':
            if (open.value) {
                event.preventDefault()
                event.stopPropagation()
                close(true)
            }
            break
        case 'Tab':
            close()
            break
    }
}

onBeforeUnmount(() => {
    clearTimeout(timer)
    resource.cancel()
    document.removeEventListener('pointerdown', onOutside)
})
</script>

<template>
    <div ref="root" class="relative flex items-center gap-1" @focusout="onFocusOut">
        <div class="relative min-w-0 flex-1">
            <component
                :is="searchable ? 'input' : 'button'"
                :id="id"
                ref="control"
                :type="searchable ? 'text' : 'button'"
                role="combobox"
                aria-haspopup="listbox"
                :aria-expanded="open"
                :aria-controls="open ? listboxId : undefined"
                :aria-activedescendant="activeDescendant"
                :aria-autocomplete="searchable ? 'list' : undefined"
                :aria-invalid="invalid || undefined"
                :autocomplete="searchable ? 'off' : undefined"
                :disabled="disabled"
                class="larsadmin-input pr-8 text-left"
                :class="{ 'larsadmin-input-invalid': invalid }"
                v-bind="searchable ? { value: inputValue, placeholder: placeholderText } : {}"
                @input="searchable ? onInput($event) : undefined"
                @click="open && !searchable ? close() : show()"
                @keydown="onKeydown"
            >
                <template v-if="!searchable">
                    <span v-if="label !== null" class="block truncate">{{ label }}</span>
                    <span v-else class="block truncate text-fg-subtle">{{ placeholder ?? t('choose_option') }}</span>
                </template>
            </component>
            <IconChevronDown class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-fg-muted" />

            <div
                v-if="open"
                class="absolute left-0 z-40 mt-1 w-full overflow-hidden rounded-md border border-default bg-canvas text-sm text-fg shadow-overlay"
                @mousedown.prevent
            >
                <ul
                    :id="listboxId"
                    ref="listbox"
                    role="listbox"
                    :aria-label="placeholder ?? t('choose_option')"
                    :aria-busy="status === 'loading'"
                    class="max-h-60 overflow-y-auto py-1"
                >
                    <li
                        v-for="(option, index) in options"
                        :id="optionId(index)"
                        :key="option.id"
                        role="option"
                        :aria-selected="option.id === modelValue"
                        class="flex cursor-pointer items-center gap-2 px-3 py-1.5"
                        :class="active === index ? 'bg-neutral-muted' : ''"
                        @mouseenter="active = index"
                        @click="choose(option)"
                    >
                        <span class="flex-1 truncate">{{ option.title }}</span>
                        <IconCheck v-if="option.id === modelValue" class="text-accent-fg" />
                    </li>
                    <li
                        v-if="hasMore"
                        :id="optionId(options.length)"
                        role="option"
                        aria-selected="false"
                        class="cursor-pointer px-3 py-1.5 text-accent-fg"
                        :class="active === options.length ? 'bg-neutral-muted' : ''"
                        @mouseenter="active = options.length"
                        @click="activate(options.length)"
                    >
                        {{ loadingMore ? t('loading') : t('load_more') }}
                    </li>
                </ul>

                <p v-if="status === 'loading' && options.length === 0" class="px-3 py-2 text-fg-muted" role="status">
                    {{ t('loading') }}
                </p>
                <p v-else-if="status === 'idle' && options.length === 0" class="px-3 py-2 text-fg-muted" role="status">
                    {{ t('no_results') }}
                </p>
                <div v-else-if="status === 'error' || status === 'forbidden'" class="flex items-center gap-2 px-3 py-2 text-danger-fg" role="alert">
                    <IconAlert />
                    <span class="flex-1">{{ status === 'forbidden' ? t('options_forbidden') : t('options_error') }}</span>
                    <button v-if="status === 'error'" type="button" class="larsadmin-btn" @click="load(1)">{{ t('retry') }}</button>
                </div>
            </div>
        </div>

        <button
            v-if="showClear"
            type="button"
            class="rounded-md border border-default p-1.5 text-fg-muted hover:bg-neutral-muted"
            :aria-label="t('clear_selection')"
            @click="clear"
        >
            <IconX />
        </button>
    </div>
</template>
