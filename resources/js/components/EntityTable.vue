<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { displayComponent } from '../fields'
import { useLarsadmin, withParams } from '../composables/useLarsadmin'
import { useI18n } from '../composables/useI18n'
import { normalizeColumn } from '../composables/columnPreferences'
import SelectionCheckbox from './actions/SelectionCheckbox.vue'
import IconArrowDown from './icons/IconArrowDown.vue'
import IconArrowUp from './icons/IconArrowUp.vue'
import IconDatabase from './icons/IconDatabase.vue'
import IconEye from './icons/IconEye.vue'
import IconPencil from './icons/IconPencil.vue'
import IconTrash from './icons/IconTrash.vue'

const props = defineProps({
    // { uriKey } of the entity the rows belong to
    entity: { type: Object, required: true },
    columns: { type: Array, required: true },
    // Paginator JSON: only `data` is read here
    records: { type: Object, required: true },
    // { search, sort, direction, defaultSort? }
    query: { type: Object, required: true },
    // Extra query params appended to row edit URLs (ADR-008), e.g. { viaEntity, viaId }
    via: { type: Object, default: null },
    // Dim the body while a refetch is in flight
    busy: { type: Boolean, default: false },
    // Checkbox column (index page only)
    selectable: { type: Boolean, default: false },
    isSelected: { type: Function, default: () => false },
    pageSelected: { type: Boolean, default: false },
    pageIndeterminate: { type: Boolean, default: false },
    // Actions with `showInline`, rendered as per-row buttons
    inlineActions: { type: Array, default: () => [] },
})

const emit = defineEmits(['sort', 'destroy', 'toggle', 'toggle-page', 'run-action'])

const { recordUrl } = useLarsadmin()
const { t } = useI18n()

// The server's default ordering is shown as the active sort while no explicit sort is chosen.
const sortState = computed(() => {
    if (props.query.sort) return { attribute: props.query.sort, direction: props.query.direction ?? 'asc' }

    const fallback = props.query.defaultSort

    return fallback?.attribute ? { attribute: fallback.attribute, direction: fallback.direction ?? 'asc' } : null
})

// Older payloads may lack the width/align/wrap/truncate keys.
const cols = computed(() => props.columns.map(normalizeColumn))

function ariaSort(column) {
    if (!column.sortable || sortState.value?.attribute !== column.attribute) return undefined

    return sortState.value.direction === 'asc' ? 'ascending' : 'descending'
}

const alignClass = { left: 'text-left', center: 'text-center', right: 'text-right' }
const align = (column) => alignClass[column.align] ?? 'text-left'

const widthStyle = (column) => (column.width ? { width: column.width, minWidth: column.width } : undefined)

function cellClass(column) {
    return [
        'px-4 py-2',
        align(column),
        column.truncate === 0 ? 'max-w-0 truncate' : column.wrap ? 'break-words whitespace-normal' : 'whitespace-nowrap',
    ]
}

function cellField(record, column) {
    return record.fields.find((field) => field.attribute === column.attribute) ?? null
}

// truncate = N cuts the displayed text to N characters; the full value stays available in `title`.
function shownField(field, column) {
    const text = field?.displayedAs

    if (column.truncate > 0 && typeof text === 'string' && text.length > column.truncate) {
        return { ...field, displayedAs: `${text.slice(0, column.truncate)}…` }
    }

    return field
}

function cellTitle(field, column) {
    const text = field?.displayedAs

    return (column.truncate !== null && (typeof text === 'string' || typeof text === 'number') && String(text) !== '') ? String(text) : undefined
}

const actionBtn = 'inline-flex rounded-md p-1.5 text-fg-muted hover:bg-neutral-muted'
</script>

<template>
    <div class="overflow-x-auto" tabindex="0" :aria-busy="busy || undefined">
        <table class="w-full text-left">
            <colgroup v-if="cols.some((column) => column.width)">
                <col v-if="selectable" style="width: 2.5rem">
                <col v-for="column in cols" :key="column.attribute" :style="widthStyle(column)">
                <col>
            </colgroup>
            <thead>
                <tr class="border-b border-default bg-canvas-subtle text-xs text-fg-muted">
                    <th v-if="selectable" scope="col" class="w-10 px-4 py-2">
                        <SelectionCheckbox
                            :model-value="pageSelected"
                            :indeterminate="pageIndeterminate"
                            :label="t('act.select_page')"
                            @update:model-value="emit('toggle-page', $event)"
                        />
                    </th>
                    <th
                        v-for="column in cols"
                        :key="column.attribute"
                        scope="col"
                        :aria-sort="ariaSort(column)"
                        :class="['px-4 py-2 font-semibold whitespace-nowrap', align(column)]"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            class="inline-flex items-center gap-1 font-semibold hover:text-fg"
                            @click="emit('sort', column)"
                        >
                            {{ column.name }}
                            <template v-if="sortState?.attribute === column.attribute">
                                <IconArrowUp v-if="sortState.direction === 'asc'" class="size-3" />
                                <IconArrowDown v-else class="size-3" />
                            </template>
                        </button>
                        <template v-else>{{ column.name }}</template>
                    </th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">{{ t('actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-muted" :class="{ 'opacity-60': busy }">
                <tr v-for="record in records.data" :key="record.id" class="hover:bg-canvas-subtle">
                    <td v-if="selectable" class="w-10 px-4 py-2">
                        <SelectionCheckbox
                            :model-value="isSelected(record.id)"
                            :label="t('act.select_row')"
                            @update:model-value="emit('toggle', record.id, $event)"
                        />
                    </td>
                    <td
                        v-for="column in cols"
                        :key="column.attribute"
                        :class="cellClass(column)"
                        :title="cellTitle(cellField(record, column), column)"
                    >
                        <component
                            :is="displayComponent(cellField(record, column))"
                            v-if="cellField(record, column)"
                            :field="shownField(cellField(record, column), column)"
                        />
                    </td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        <div class="inline-flex gap-0.5">
                            <Link v-if="recordUrl(entity.uriKey, record, 'show')" :href="recordUrl(entity.uriKey, record, 'show')" :class="actionBtn" :title="t('view')" :aria-label="t('view')">
                                <IconEye />
                            </Link>
                            <Link v-if="recordUrl(entity.uriKey, record, 'edit')" :href="withParams(recordUrl(entity.uriKey, record, 'edit'), via)" :class="actionBtn" :title="t('edit')" :aria-label="t('edit')">
                                <IconPencil />
                            </Link>
                            <button
                                v-if="recordUrl(entity.uriKey, record, 'destroy')"
                                type="button"
                                :class="[actionBtn, 'hover:text-danger-fg']"
                                :title="t('delete')"
                                :aria-label="t('delete')"
                                @click="emit('destroy', record)"
                            >
                                <IconTrash />
                            </button>
                            <button
                                v-for="action in inlineActions"
                                :key="action.uriKey"
                                type="button"
                                :class="[actionBtn, 'px-2 text-xs font-semibold', action.destructive ? 'text-danger-fg' : 'text-accent-fg']"
                                @click="emit('run-action', action, record)"
                            >
                                {{ action.name }}
                            </button>
                            <slot name="row-actions" :record="record" :action-class="actionBtn" />
                        </div>
                    </td>
                </tr>
                <tr v-if="!records.data.length">
                    <td :colspan="cols.length + 1 + (selectable ? 1 : 0)" class="px-4 py-12 text-center">
                        <IconDatabase class="mx-auto mb-2 size-6 text-fg-muted" />
                        <h2 class="text-base font-semibold">{{ t('no_results') }}</h2>
                        <p class="mt-1 text-fg-muted">
                            {{ query.search ? t('no_results_search_hint') : t('no_records_hint') }}
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
