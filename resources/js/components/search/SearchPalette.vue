<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from '../../composables/useI18n'
import { useJsonResource } from '../../composables/useJsonResource'
import { buildSections, currentPlatform, enterAction, highlight, modifierLabel, nextIndex, searchUrl, shouldSearch, wantsNewTab } from './palette'
import IconAlert from '../icons/IconAlert.vue'
import IconChevronRight from '../icons/IconChevronRight.vue'
import IconSearch from '../icons/IconSearch.vue'
import IconX from '../icons/IconX.vue'

// Global search command palette: a modal dialog with a combobox input and a grouped listbox.
// Mount with v-if; emits `close`. Focus stays in the input (options use aria-activedescendant),
// is trapped inside the dialog and returns to the opener on close.
const props = defineProps({
    // JSON endpoint: GET ?search= -> { search, groups, failed, partial }
    endpoint: { type: String, required: true },
    minLength: { type: Number, default: 2 },
    maxLength: { type: Number, default: 100 },
    // Element that gets focus back on close; defaults to the element focused when the palette opened.
    returnTo: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const { t, tc } = useI18n()

const modifier = modifierLabel(currentPlatform())

const DEBOUNCE_MS = 250
const LOADING_DELAY_MS = 150

const uid = useId()
const inputId = `${uid}-input`
const listboxId = `${uid}-listbox`
const keysId = `${uid}-keys`
const optionId = (index) => `${uid}-option-${index}`
const groupId = (key) => `${uid}-group-${key.replace(/[^\w-]/g, '_')}`

const dialog = ref(null)
const input = ref(null)
const listbox = ref(null)
const scroller = ref(null)
const returnTarget = props.returnTo ?? document.activeElement

const query = ref('')
const response = ref(null) // last successful body
const searched = ref('') // the term `response` answers
const active = ref(-1)
const showLoading = ref(false)
const announcement = ref('')

const resource = useJsonResource()
const status = resource.status // idle | loading | error | forbidden

let debounceTimer = null
let loadingTimer = null

const searchable = computed(() => shouldSearch(query.value, props.minLength))
const built = computed(() => buildSections(response.value?.groups ?? []))
const sections = computed(() => built.value.sections)
const options = computed(() => built.value.options)
const failed = computed(() => (Array.isArray(response.value?.failed) ? response.value.failed : []))
const failedLabels = computed(() => failed.value.map((entry) => entry?.label ?? entry?.entity).filter(Boolean).join(', '))
const partial = computed(() => response.value?.partial === true)
const errored = computed(() => status.value === 'error' || status.value === 'forbidden')
const loading = computed(() => status.value === 'loading')
const hasOptions = computed(() => searchable.value && !errored.value && options.value.length > 0)
const noResults = computed(() => searchable.value && !errored.value && response.value !== null && options.value.length === 0 && !loading.value)
const activeDescendant = computed(() => (hasOptions.value && active.value >= 0 ? optionId(active.value) : undefined))

watch(loading, (value) => {
    clearTimeout(loadingTimer)

    if (value) loadingTimer = setTimeout(() => (showLoading.value = true), LOADING_DELAY_MS)
    else showLoading.value = false
})

function summary(body) {
    const { sections, results } = buildSections(body?.groups ?? [])
    const parts = []

    if (results === 0) parts.push(t('global_search_no_results', { search: searched.value }))
    else parts.push(tc('global_search_results_in', sections.length, { results: tc('global_search_results', results), groups: sections.length }))

    if (body?.partial === true) parts.push(t('global_search_partial'))

    const names = (Array.isArray(body?.failed) ? body.failed : []).map((entry) => entry?.label ?? entry?.entity).filter(Boolean)
    if (names.length) parts.push(t('global_search_failed', { entities: names.join(', ') }))

    return parts.join(' ')
}

function scrollActiveIntoView() {
    if (!activeDescendant.value) return

    listbox.value?.querySelector(`[id="${activeDescendant.value}"]`)?.scrollIntoView({ block: 'nearest' })
}

async function run() {
    clearTimeout(debounceTimer)

    const term = query.value.trim()

    if (!shouldSearch(term, props.minLength)) return

    const body = await resource.load(searchUrl(props.endpoint, term))

    // Superseded or cancelled: a newer request owns the state.
    if (body === undefined) return

    if (body === null) {
        announcement.value = t('global_search_error')

        return
    }

    response.value = body && typeof body === 'object' ? body : { groups: [] }
    searched.value = typeof body?.search === 'string' ? body.search : term
    active.value = options.value.length ? 0 : -1
    announcement.value = summary(response.value)

    await nextTick()
    scroller.value?.scrollTo?.({ top: 0 })
}

function onInput(event) {
    query.value = event.target.value
    clearTimeout(debounceTimer)

    if (!shouldSearch(query.value, props.minLength)) {
        resource.cancel()
        response.value = null
        searched.value = ''
        active.value = -1
        announcement.value = ''

        return
    }

    debounceTimer = setTimeout(run, DEBOUNCE_MS)
}

function choose(option, newTab = false) {
    if (!option?.url) return

    if (newTab) {
        window.open(option.url, '_blank', 'noopener')

        return
    }

    emit('close')
    router.visit(option.url)
}

function move(delta) {
    active.value = nextIndex(active.value, options.value.length, delta)
    nextTick(scrollActiveIntoView)
}

function onInputKeydown(event) {
    if (event.isComposing) return

    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault()
            move(1)
            break
        case 'ArrowUp':
            event.preventDefault()
            move(-1)
            break
        case 'Enter': {
            // Typed within the debounce window: the options on screen answer an older term, so search now.
            const action = enterAction({ term: query.value, searched: searched.value, hasActive: !!activeDescendant.value, searchable: searchable.value })

            if (action === 'choose') {
                event.preventDefault()
                choose(options.value[active.value], wantsNewTab(event))
            } else if (action === 'search') {
                event.preventDefault()
                run()
            }
            break
        }
    }
}

const focusable = () =>
    [...(dialog.value?.querySelectorAll('a[href]:not([tabindex="-1"]), button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])') ?? [])].filter(
        (el) => el.offsetParent !== null,
    )

function onDialogKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault()
        event.stopPropagation()
        emit('close')
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
        } else if (!dialog.value?.contains(document.activeElement)) {
            event.preventDefault()
            first.focus()
        }
    }
}

// Options render real links (CRS-16): a modified click (Ctrl/⌘/Shift) or middle click keeps the browser's
// native new-tab/window behaviour; a plain click is an Inertia visit.
function onOptionClick(option, event) {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return

    event.preventDefault()
    choose(option)
}

// Focus the input and select its text (Ctrl/⌘+K while the palette is already open).
function focusInput() {
    input.value?.focus()
    input.value?.select()
}

defineExpose({ focusInput })

onMounted(async () => {
    document.body.style.overflow = 'hidden'
    await nextTick()
    input.value?.focus()
})

onBeforeUnmount(() => {
    clearTimeout(debounceTimer)
    clearTimeout(loadingTimer)
    resource.cancel()
    document.body.style.overflow = ''
    if (returnTarget instanceof HTMLElement && document.contains(returnTarget)) returnTarget.focus()
})

const optionClass = (index) => [
    'relative flex cursor-pointer rounded-md',
    active.value === index
        ? 'bg-neutral-muted before:absolute before:top-1.5 before:bottom-1.5 before:left-0 before:w-1 before:rounded-full before:bg-accent-emphasis'
        : '',
]
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-start justify-center bg-overlay sm:p-4 sm:pt-[12dvh]" @mousedown.self="emit('close')">
            <div
                ref="dialog"
                role="dialog"
                aria-modal="true"
                :aria-label="t('global_search_dialog')"
                class="flex max-h-[70dvh] w-full flex-col overflow-hidden border-b border-default bg-canvas text-sm text-fg shadow-overlay sm:max-w-xl sm:rounded-md sm:border"
                @keydown="onDialogKeydown"
            >
                <div class="flex shrink-0 items-center gap-2 border-b border-muted px-3 focus-within:shadow-[inset_0_-2px_0_var(--la-accent-fg)]">
                    <IconSearch class="text-fg-muted" />
                    <label :for="inputId" class="sr-only">{{ t('global_search_label') }}</label>
                    <input
                        :id="inputId"
                        ref="input"
                        type="text"
                        role="combobox"
                        aria-autocomplete="list"
                        :aria-expanded="hasOptions"
                        :aria-controls="hasOptions ? listboxId : undefined"
                        :aria-activedescendant="activeDescendant"
                        :aria-describedby="keysId"
                        :maxlength="maxLength"
                        :value="query"
                        :placeholder="t('global_search_placeholder')"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        enterkeyhint="go"
                        class="h-12 min-w-0 flex-1 bg-transparent text-[16px] text-fg placeholder:text-fg-subtle focus:outline-none sm:text-sm"
                        @input="onInput"
                        @keydown="onInputKeydown"
                    />
                    <span v-if="showLoading" class="shrink-0 text-xs text-fg-muted" aria-hidden="true">{{ t('global_search_loading') }}</span>
                    <button
                        type="button"
                        class="-mr-1 shrink-0 rounded-md p-1.5 text-fg-muted hover:bg-neutral-muted"
                        :aria-label="t('global_search_close')"
                        @click="emit('close')"
                    >
                        <IconX />
                    </button>
                </div>

                <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto">
                    <p v-if="!searchable" class="px-4 py-6 text-center text-fg-muted">
                        {{ tc('global_search_hint', minLength) }}
                    </p>

                    <div v-else-if="errored" class="flex flex-wrap items-center gap-2 px-4 py-4 text-danger-fg">
                        <IconAlert />
                        <span class="flex-1">{{ t('global_search_error') }}</span>
                        <button type="button" class="larsadmin-btn larsadmin-btn-sm" @click="run">{{ t('global_search_retry') }}</button>
                    </div>

                    <template v-else>
                        <div
                            v-if="hasOptions"
                            :id="listboxId"
                            ref="listbox"
                            role="listbox"
                            :aria-label="t('global_search_dialog')"
                            :aria-busy="loading"
                            class="space-y-2 p-2 transition-opacity"
                            :class="{ 'opacity-60': loading }"
                            @mousedown.prevent
                        >
                            <div v-for="section in sections" :key="section.key" role="group" :aria-labelledby="groupId(section.key)">
                                <div :id="groupId(section.key)" class="px-3 pt-1 pb-0.5 text-xs font-semibold text-fg-muted">
                                    {{ section.label }}
                                </div>
                                <div
                                    v-for="option in section.options"
                                    :id="optionId(option.index)"
                                    :key="option.key"
                                    role="option"
                                    :aria-selected="active === option.index"
                                    :class="optionClass(option.index)"
                                    @mousemove="active = option.index"
                                >
                                    <a
                                        :href="option.url"
                                        tabindex="-1"
                                        class="flex min-w-0 flex-1 items-center gap-2 px-3 py-1.5 text-fg no-underline"
                                        @click="onOptionClick(option, $event)"
                                    >
                                    <template v-if="option.kind === 'result'">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate">
                                                <template v-for="(segment, position) in highlight(option.title, searched)" :key="position">
                                                    <mark v-if="segment.match" class="rounded-sm bg-accent-subtle font-semibold text-fg underline decoration-accent-fg underline-offset-2">{{ segment.text }}</mark>
                                                    <template v-else>{{ segment.text }}</template>
                                                </template>
                                            </span>
                                            <span v-if="option.subtitle" class="block truncate text-xs text-fg-muted">{{ option.subtitle }}</span>
                                        </span>
                                    </template>
                                    <template v-else>
                                        <span class="min-w-0 flex-1 truncate text-accent-fg">{{ t('global_search_view_all', { entity: option.label }) }}</span>
                                        <IconChevronRight class="text-fg-muted" />
                                    </template>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <p v-else-if="noResults" class="px-4 py-6 text-center text-fg-muted">
                            {{ t('global_search_no_results', { search: searched }) }}
                        </p>

                        <div v-if="response && (partial || failed.length)" class="space-y-1 border-t border-muted px-4 py-2 text-xs text-fg-muted">
                            <p v-if="partial" class="flex items-start gap-1.5"><IconAlert class="mt-0.5" />{{ t('global_search_partial') }}</p>
                            <p v-if="failed.length" class="flex items-start gap-1.5 text-danger-fg">
                                <IconAlert class="mt-0.5" />{{ t('global_search_failed', { entities: failedLabels }) }}
                            </p>
                        </div>
                    </template>
                </div>

                <p :id="keysId" class="hidden shrink-0 border-t border-muted px-4 py-2 text-xs text-fg-muted sm:block">
                    {{ t('global_search_keys', { modifier }) }}
                </p>

                <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>
            </div>
        </div>
    </Teleport>
</template>
