<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { useLarsadmin, withParams } from '../composables/useLarsadmin'
import { useI18n } from '../composables/useI18n'
import { useJsonResource } from '../composables/useJsonResource'
import { cleanFilters } from '../filters'
import AttachPicker from './AttachPicker.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import ColumnMenu from './ColumnMenu.vue'
import FilterMenu from './FilterMenu.vue'
import { useColumnPreferences } from '../composables/useColumnPreferences'
import EntityTable from './EntityTable.vue'
import Pagination from './Pagination.vue'
import IconAlert from './icons/IconAlert.vue'
import IconPlus from './icons/IconPlus.vue'
import IconSearch from './icons/IconSearch.vue'
import IconX from './icons/IconX.vue'

const props = defineProps({
    // has-many-field | belongs-to-many-field JSON
    field: { type: Object, required: true },
    // Parent entity ({ uriKey }) and parent record ({ id })
    entity: { type: Object, required: true },
    record: { type: Object, required: true },
})

const { recordUrl } = useLarsadmin()
const { t, tc } = useI18n()

const uid = useId()
const titleId = `${uid}-title`

const related = computed(() => props.field.relatedEntity)
const isBelongsToMany = computed(() => props.field.component === 'belongs-to-many-field')

const search = ref('')
const sort = ref(null)
const direction = ref(null)
const page = ref(1)
const perPage = ref(null)

const data = ref(null) // { columns, records, query, filters? } of the last successful response
const resource = useJsonResource()
const status = resource.status // loading | idle | error | forbidden
const refreshing = ref(false)

// Local filter state: null until the user touches a filter (then the server's effective values are shown).
const filterValues = ref(null)
const filterDefs = computed(() => (Array.isArray(data.value?.filters) ? data.value.filters : []))
const activeFilters = computed(() => {
    const value = filterValues.value ?? data.value?.query?.filters

    return value && typeof value === 'object' && !Array.isArray(value) ? value : {}
})

let searchTimer = null

function buildUrl() {
    const params = new URLSearchParams()

    if (search.value.trim() !== '') params.set('search', search.value.trim())
    if (sort.value) {
        params.set('sort', sort.value)
        params.set('direction', direction.value ?? 'asc')
    }
    if (page.value > 1) params.set('page', String(page.value))
    if (perPage.value) params.set('perPage', String(perPage.value))

    // Same keys as the index page: filters[key]=v, filters[key][from]=v; cleared defaults go out as filters[key]=
    if (filterValues.value !== null) {
        for (const [key, value] of Object.entries(cleanFilters(filterValues.value, filterDefs.value))) {
            if (value !== null && typeof value === 'object') {
                for (const [part, partValue] of Object.entries(value)) params.set(`filters[${key}][${part}]`, String(partValue))
            } else {
                params.set(`filters[${key}]`, String(value))
            }
        }
    }

    const query = params.toString()
    const glue = props.field.endpoint.includes('?') ? '&' : '?'

    return query ? `${props.field.endpoint}${glue}${query}` : props.field.endpoint
}

async function load() {
    const hadData = data.value !== null

    if (hadData) refreshing.value = true

    const body = await resource.load(buildUrl(), { background: hadData })

    // Superseded by a newer request: it owns the state now.
    if (body === undefined) return

    refreshing.value = false

    if (body === null) return

    // A page that vanished (last row deleted/detached) falls back to the last existing page.
    if (body.records.data.length === 0 && body.records.current_page > body.records.last_page && body.records.last_page >= 1) {
        page.value = body.records.last_page

        return load()
    }

    data.value = body
}

function retry() {
    data.value = null
    load()
}

// Exposed so the page (or a parent) can force a refetch.
function refresh() {
    return load()
}

function setSearch(value) {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
        search.value = value
        page.value = 1
        load()
    }, 300)
}

function sortBy(column) {
    if (!column.sortable) return

    const current = tableQuery.value.sort ? { attribute: tableQuery.value.sort, direction: tableQuery.value.direction } : tableQuery.value.defaultSort

    direction.value = current?.attribute === column.attribute && current?.direction === 'asc' ? 'desc' : 'asc'
    sort.value = column.attribute
    page.value = 1
    load()
}

function goTo(number) {
    page.value = number
    load()
}

function changePerPage(event) {
    perPage.value = Number(event.target.value)
    page.value = 1
    load()
}

const columnPrefs = useColumnPreferences(
    () => `${props.entity.uriKey}.${props.field.attribute}`,
    () => data.value?.columns ?? [],
)

const tableQuery = computed(() => ({
    search: search.value,
    sort: sort.value ?? data.value?.query?.sort ?? null,
    direction: direction.value ?? data.value?.query?.direction ?? null,
    defaultSort: data.value?.query?.defaultSort ?? null,
}))

const currentPerPage = computed(() => perPage.value ?? data.value?.query?.perPage ?? related.value.perPageOptions?.[0])
// The panel's own page size is always selectable, even when it is not one of the entity's options.
const perPageOptions = computed(() => {
    const options = [...(related.value.perPageOptions ?? [])]

    if (currentPerPage.value && !options.includes(Number(currentPerPage.value))) options.push(Number(currentPerPage.value))

    return options.sort((x, y) => x - y)
})

function setFilters(values) {
    filterValues.value = values
    page.value = 1
    load()
}

// Delete / detach stay Inertia visits (ADR-005): CSRF, toast and redirect-back come for free.
// URLs are server-built per row (`row.urls`); a null URL means the control is not shown.
const confirming = ref(null) // { url, message, label }

function ask(url, message, label) {
    if (url) confirming.value = { url, message, label }
}

function proceed() {
    const { url } = confirming.value

    confirming.value = null
    router.delete(url, { preserveScroll: true, onSuccess: () => refresh() })
}

function destroy(row) {
    const url = recordUrl(related.value.uriKey, row, 'destroy')

    ask(url && withParams(url, props.field.via), t('delete_confirm'), t('delete'))
}

function detach(row) {
    ask(row.urls?.detach, t('detach_confirm'), t('detach'))
}

onMounted(load)

onBeforeUnmount(() => {
    clearTimeout(searchTimer)
})

// Navigating between two detail pages can reuse this component.
watch(() => props.field.endpoint, () => {
    data.value = null
    filterValues.value = null
    page.value = 1
    load()
})

defineExpose({ refresh })
</script>

<template>
    <section class="larsadmin-box" :aria-labelledby="titleId">
        <div class="larsadmin-box-header flex flex-wrap items-center justify-between gap-3">
            <h2 :id="titleId" class="flex items-center gap-2 text-base font-semibold">
                {{ field.name }}
                <span v-if="data" class="larsadmin-counter" :title="tc('records_count', data.records.total)">{{ data.records.total }}</span>
            </h2>

            <div class="flex flex-wrap items-start gap-2">
                <div v-if="related.searchable" class="relative w-48 max-w-full">
                    <IconSearch class="pointer-events-none absolute top-2 left-2.5 text-fg-muted" />
                    <input
                        type="search"
                        :placeholder="t('search')"
                        :aria-label="`${t('search')}: ${field.name}`"
                        class="larsadmin-input pl-8"
                        @input="setSearch($event.target.value)"
                    >
                </div>

                <ColumnMenu
                    v-if="data"
                    :items="columnPrefs.items.value"
                    :customised="columnPrefs.customised.value"
                    @toggle="columnPrefs.toggle"
                    @move="columnPrefs.move"
                    @reset="columnPrefs.reset"
                />

                <FilterMenu
                    v-if="filterDefs.length > 0"
                    :filters="filterDefs"
                    :model-value="activeFilters"
                    @update:model-value="setFilters"
                />

                <Link v-if="!isBelongsToMany && field.createUrl" :href="field.createUrl" class="larsadmin-btn-primary">
                    <IconPlus />{{ t('create') }}
                </Link>

                <AttachPicker
                    v-if="isBelongsToMany && field.attachUrl && field.optionsUrl"
                    :resource-id="record.id"
                    :options-url="field.optionsUrl"
                    :attach-url="field.attachUrl"
                    :searchable="!!field.searchable"
                    @attached="refresh"
                />
            </div>
        </div>

        <div v-if="status === 'loading'" class="animate-pulse space-y-3 px-4 py-4" role="status">
            <div v-for="n in 3" :key="n" class="h-4 rounded bg-neutral-muted" />
            <span class="sr-only">{{ t('loading') }}</span>
        </div>

        <div v-else-if="status === 'error' || status === 'forbidden'" class="flex items-center gap-2 px-4 py-6 text-danger-fg" role="alert">
            <IconAlert />
            <span class="flex-1">{{ status === 'forbidden' ? t('panel_forbidden') : t('panel_error') }}</span>
            <button v-if="status === 'error'" type="button" class="larsadmin-btn" @click="retry">{{ t('retry') }}</button>
        </div>

        <template v-else-if="data">
            <EntityTable
                :entity="related"
                :columns="columnPrefs.visibleColumns.value"
                :records="data.records"
                :query="tableQuery"
                :via="field.via"
                :busy="refreshing"
                @sort="sortBy"
                @destroy="destroy"
            >
                <template #row-actions="{ record: row, actionClass }">
                    <button
                        v-if="isBelongsToMany && row.urls?.detach"
                        type="button"
                        :class="[actionClass, 'hover:text-danger-fg']"
                        :title="t('detach')"
                        :aria-label="t('detach')"
                        @click="detach(row)"
                    >
                        <IconX />
                    </button>
                </template>
            </EntityTable>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-default px-4 py-2">
                <Pagination class="min-w-0 flex-1" :paginator="data.records" @navigate="goTo" />

                <label v-if="perPageOptions.length" class="flex items-center gap-2 text-fg-muted">
                    {{ t('per_page') }}
                    <select :value="currentPerPage" class="larsadmin-input w-auto" @change="changePerPage">
                        <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                </label>
            </div>
        </template>

        <ConfirmDialog
            v-if="confirming"
            :message="confirming.message"
            :confirm-label="confirming.label"
            @confirm="proceed"
            @cancel="confirming = null"
        />
    </section>
</template>
