<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { useLarsadmin } from '../../composables/useLarsadmin'
import { useI18n } from '../../composables/useI18n'
import ActionMenu from '../../components/actions/ActionMenu.vue'
import ActionModal from '../../components/actions/ActionModal.vue'
import { useSelection } from '../../composables/useSelection'
import ConfirmDialog from '../../components/ConfirmDialog.vue'
import ColumnMenu from '../../components/ColumnMenu.vue'
import FilterMenu from '../../components/FilterMenu.vue'
import { useColumnPreferences } from '../../composables/useColumnPreferences'
import { cleanFilters } from '../../filters'
import EntityTable from '../../components/EntityTable.vue'
import Pagination from '../../components/Pagination.vue'
import IconPlus from '../../components/icons/IconPlus.vue'
import IconSearch from '../../components/icons/IconSearch.vue'

const props = defineProps({
    entity: { type: Object, required: true },
    columns: { type: Array, required: true },
    records: { type: Object, required: true },
    query: { type: Object, required: true },
    filters: { type: Array, default: () => [] },
    actions: { type: Array, default: () => [] },
})

const { entityUrl, recordUrl } = useLarsadmin()
const { t, tc } = useI18n()

const search = ref(props.query.search ?? '')

const columnPrefs = useColumnPreferences(
    () => props.entity.uriKey,
    () => props.columns,
)

// PHP serialises an empty array as [], so anything that is not a plain object means "no filters".
const activeFilters = computed(() => {
    const value = props.query.filters

    return value && typeof value === 'object' && !Array.isArray(value) ? value : {}
})

const hasActions = computed(() => (props.actions ?? []).length > 0)
const hasSelectable = computed(() => (props.actions ?? []).some((a) => a.showOnIndex && !a.standalone))
const inlineActions = computed(() => (props.actions ?? []).filter((a) => a.showInline && !a.standalone))
const pending = ref(null)
const pendingResources = ref([])

const selection = useSelection({
    pageIds: () => props.records.data.map((record) => record.id),
    total: () => props.records.total,
    resetOn: () => [
        props.records.current_page,
        props.query.search,
        props.query.sort,
        props.query.direction,
        props.query.perPage,
        JSON.stringify(activeFilters.value),
    ],
})

function runAction(action, record = null) {
    pendingResources.value = record ? [record.id] : selection.selected.value
    pending.value = action
}

function closeAction() {
    pending.value = null
    selection.clear()
}

function reload(params) {
    // Always build from the local search ref so a sort/per-page click never drops typed text.
    const query = { ...props.query, search: search.value, filters: activeFilters.value, ...params }
    // Cleared filters that have a default are sent as explicit empties, otherwise the server re-applies the default.
    query.filters = cleanFilters(query.filters, props.filters)
    if (Object.keys(query.filters).length === 0) delete query.filters

    router.get(
        entityUrl(props.entity.uriKey),
        Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== '')),
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

let searchTimeout
watch(search, (value) => {
    clearTimeout(searchTimeout)

    // The visible rows are about to change: drop the selection now, not after the debounce.
    selection.clear()

    // Re-sync from the server (e.g. back/forward navigation) must not trigger another request.
    if (value === (props.query.search ?? '')) return

    searchTimeout = setTimeout(() => reload({ search: value, page: null }), 300)
})

watch(
    () => props.query.search,
    (value) => {
        if ((value ?? '') !== search.value) search.value = value ?? ''
    },
)

onBeforeUnmount(() => clearTimeout(searchTimeout))

function sortBy(column) {
    if (!column.sortable) return

    // The default sort counts as the current sort until the user picks one.
    const current = props.query.sort ? { attribute: props.query.sort, direction: props.query.direction } : props.query.defaultSort
    const direction = current?.attribute === column.attribute && current?.direction === 'asc' ? 'desc' : 'asc'

    reload({ sort: column.attribute, direction, page: null })
}

const deleting = ref(null)

function destroy(record) {
    deleting.value = recordUrl(props.entity.uriKey, record, 'destroy') ? record : null
}

function confirmDestroy() {
    const url = recordUrl(props.entity.uriKey, deleting.value, 'destroy')

    deleting.value = null

    if (url) router.delete(url, { preserveScroll: true })
}

// Only filters worth sending: active values plus explicit empties for cleared defaults.
const actionFilters = computed(() => {
    const cleaned = cleanFilters(activeFilters.value, props.filters)

    return Object.keys(cleaned).length ? cleaned : null
})
</script>

<template>
    <Head :title="entity.label" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-default pb-4">
        <h1 class="flex items-center gap-2 text-2xl font-semibold">
            {{ entity.label }}
            <span class="larsadmin-counter" :title="tc('records_count', records.total)">{{ records.total }}</span>
        </h1>

        <Link v-if="entity.authorizedToCreate" :href="entityUrl(entity.uriKey, 'create')" class="larsadmin-btn-primary">
            <IconPlus />{{ t('create') }}
        </Link>
    </div>

    <div class="larsadmin-box">
        <div class="larsadmin-box-header flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div v-if="entity.searchable" class="relative w-full sm:max-w-xs sm:flex-1">
                <IconSearch class="pointer-events-none absolute top-2 left-2.5 text-fg-muted" />
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('search')"
                    :aria-label="t('search')"
                    class="larsadmin-input pl-8"
                >
            </div>

            <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                <FilterMenu
                    v-if="filters.length > 0"
                    :filters="filters"
                    :model-value="activeFilters"
                    @update:model-value="reload({ filters: $event, page: null })"
                />

                <ColumnMenu
                    :items="columnPrefs.items.value"
                    :customised="columnPrefs.customised.value"
                    @toggle="columnPrefs.toggle"
                    @move="columnPrefs.move"
                    @reset="columnPrefs.reset"
                />

                <ActionMenu
                    v-if="hasActions"
                    :actions="actions"
                    context="index"
                    :selected-count="selection.count.value"
                    @run="runAction($event)"
                />

                <label class="flex items-center gap-2 text-fg-muted">
                    <span class="hidden sm:inline">{{ t('per_page') }}</span>
                    <select
                        :value="query.perPage"
                        :aria-label="t('per_page')"
                        class="larsadmin-input w-auto"
                        @change="reload({ perPage: Number($event.target.value), page: null })"
                    >
                        <option v-for="option in entity.perPageOptions" :key="option" :value="option">{{ option }}</option>
                    </select>
                </label>
            </div>
        </div>

        <div
            v-if="hasSelectable && selection.count.value > 0"
            class="flex flex-wrap items-center gap-3 border-b border-default bg-accent-subtle px-4 py-2 text-sm"
            role="status"
        >
            <span>{{ t('act.selected', { count: selection.count.value }) }}</span>
            <button
                v-if="selection.canSelectAllMatching.value"
                type="button"
                class="font-semibold text-accent-fg hover:underline"
                @click="selection.selectAllMatching()"
            >
                {{ t('act.select_all', { count: records.total }) }}
            </button>
            <button type="button" class="text-fg-muted hover:underline" @click="selection.clear()">{{ t('act.clear') }}</button>
        </div>

        <EntityTable
            :entity="entity"
            :columns="columnPrefs.visibleColumns.value"
            :records="records"
            :query="query"
            :selectable="hasSelectable"
            :is-selected="selection.isSelected"
            :page-selected="selection.pageSelected.value"
            :page-indeterminate="selection.pageIndeterminate.value"
            :inline-actions="inlineActions"
            @sort="sortBy"
            @destroy="destroy"
            @toggle="selection.toggle"
            @toggle-page="selection.togglePage"
            @run-action="runAction"
        />

        <div class="border-t border-default px-4 py-2">
            <Pagination :paginator="records" />
        </div>
    </div>

    <ActionModal
        v-if="pending"
        :action="pending"
        :entity="entity.uriKey"
        :resources="pendingResources"
        :search="query.search || null"
        :filters="actionFilters"
        @close="closeAction"
    />

    <ConfirmDialog
        v-if="deleting"
        :message="t('delete_confirm')"
        :confirm-label="t('delete')"
        @confirm="confirmDestroy"
        @cancel="deleting = null"
    />
</template>
