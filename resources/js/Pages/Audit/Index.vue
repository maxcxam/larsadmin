<script setup>
import { computed, reactive, ref, useId, watch } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { useLarsadmin } from '../../composables/useLarsadmin'
import { useI18n } from '../../composables/useI18n'
import { activeFilterValues, eventLabel, filterQuery, formatDateTime, hasDetails, recordText, safeUrl } from '../../components/audit/audit'
import AuditActor from '../../components/audit/AuditActor.vue'
import AuditChanges from '../../components/audit/AuditChanges.vue'
import AuditEntrySummary from '../../components/audit/AuditEntrySummary.vue'
import Breadcrumbs from '../../components/Breadcrumbs.vue'
import FilterMenu from '../../components/FilterMenu.vue'
import IconChevronDown from '../../components/icons/IconChevronDown.vue'
import IconChevronLeft from '../../components/icons/IconChevronLeft.vue'
import IconChevronRight from '../../components/icons/IconChevronRight.vue'
import IconHistory from '../../components/icons/IconHistory.vue'

// Global audit log (§9.3): filters (section, event, user, date), newest first, prev/next without a total.
// Read-only: no edit or delete controls.
const props = defineProps({
    // { data: [entry], page, perPage, prevUrl, nextUrl }
    entries: { type: Object, required: true },
    // Filter::serialize() shape, rendered by the shared FilterMenu
    filters: { type: Array, default: () => [] },
    // { filters: { key: value } }
    query: { type: Object, default: () => ({}) },
    // Panel timezone for the times (the browser's when absent)
    timezone: { type: String, default: null },
})

const page = usePage()
const { url } = useLarsadmin()
const { t, tc, locale } = useI18n()

const uid = useId()
const rows = computed(() => (Array.isArray(props.entries?.data) ? props.entries.data : []))
const activeFilters = computed(() => activeFilterValues(props.query))
const hasActiveFilters = computed(() => Object.keys(filterQuery(activeFilters.value)).length > 0)
const indexUrl = computed(() => page.props.larsadmin?.audit?.url ?? url('audit'))

const processing = ref(false)

function setFilters(values) {
    router.get(indexUrl.value, filterQuery(values, props.filters), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
    })
}

// Expanded rows by entry id; a new page of entries starts collapsed.
const expanded = reactive({})
watch(() => props.entries, () => {
    for (const key of Object.keys(expanded)) delete expanded[key]
})

const detailsId = (entry) => `${uid}-entry-${entry.id}`
const changesText = (entry) => {
    const count = Array.isArray(entry.changes) ? entry.changes.length : 0

    return count > 0 ? tc('audit_changed_fields', count) : ''
}
const time = (entry) => formatDateTime(entry.createdAt, locale.value, props.timezone)

const pagerBtn = 'inline-flex items-center gap-1 rounded-md border border-transparent px-2 py-1'
</script>

<template>
    <Head :title="t('audit_log')" />

    <Breadcrumbs :items="[{ label: t('audit_log') }]" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-default pb-4">
        <h1 class="flex items-center gap-2 text-2xl font-semibold">
            <IconHistory class="size-5 text-fg-muted" />
            {{ t('audit_log') }}
        </h1>
    </div>

    <div class="larsadmin-box">
        <div v-if="filters.length" class="larsadmin-box-header flex flex-wrap items-center justify-end gap-2">
            <FilterMenu :filters="filters" :model-value="activeFilters" @update:model-value="setFilters" />
        </div>

        <div :aria-busy="processing || undefined" :class="{ 'opacity-60': processing }">
            <p v-if="!rows.length" class="px-4 py-12 text-center text-fg-muted">
                {{ hasActiveFilters ? t('audit_no_results') : t('audit_empty') }}
            </p>

            <template v-else>
                <!-- md and up: a table; the expanded diff is a full-width row under its entry -->
                <div class="hidden overflow-x-auto md:block">
                    <table class="w-full text-left text-sm">
                        <caption class="sr-only">{{ t('audit_log') }}</caption>
                        <thead>
                            <tr class="border-b border-default bg-canvas-subtle text-xs text-fg-muted">
                                <th scope="col" class="px-4 py-2 font-semibold whitespace-nowrap">{{ t('audit_col_time') }}</th>
                                <th scope="col" class="px-4 py-2 font-semibold">{{ t('audit_col_user') }}</th>
                                <th scope="col" class="px-4 py-2 font-semibold">{{ t('audit_col_event') }}</th>
                                <th scope="col" class="px-4 py-2 font-semibold">{{ t('audit_col_entity') }}</th>
                                <th scope="col" class="px-4 py-2 font-semibold">{{ t('audit_col_record') }}</th>
                                <th scope="col" class="px-4 py-2 font-semibold">{{ t('audit_col_changes') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-muted">
                            <template v-for="entry in rows" :key="entry.id">
                                <tr class="align-top hover:bg-canvas-subtle">
                                    <td class="px-4 py-2 whitespace-nowrap">
                                        <time :datetime="entry.createdAt">{{ time(entry) }}</time>
                                    </td>
                                    <td class="px-4 py-2"><AuditActor :entry="entry" /></td>
                                    <td class="px-4 py-2 break-words">{{ eventLabel(entry, t) }}</td>
                                    <td class="px-4 py-2">{{ entry.entity?.label ?? entry.entity?.uriKey ?? '' }}</td>
                                    <td class="px-4 py-2 break-words">
                                        <template v-if="entry.record">
                                            <Link v-if="safeUrl(entry.record.url)" :href="safeUrl(entry.record.url)" class="text-accent-fg hover:underline">{{ recordText(entry.record) }}</Link>
                                            <span v-else>{{ recordText(entry.record) }}</span>
                                        </template>
                                        <span v-else class="text-fg-muted"><span aria-hidden="true">—</span><span class="sr-only">{{ t('audit_empty_value') }}</span></span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <button
                                            v-if="hasDetails(entry, { meta: true })"
                                            type="button"
                                            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-accent-fg hover:bg-neutral-muted"
                                            :aria-expanded="!!expanded[entry.id]"
                                            :aria-controls="detailsId(entry)"
                                            @click="expanded[entry.id] = !expanded[entry.id]"
                                        >
                                            <span>{{ changesText(entry) || t('audit_col_changes') }}</span>
                                            <span class="sr-only">, {{ t('audit_toggle', { event: eventLabel(entry, t), time: time(entry) }) }}</span>
                                            <IconChevronDown :class="['transition-transform', expanded[entry.id] ? 'rotate-180' : '']" />
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="hasDetails(entry, { meta: true })" v-show="expanded[entry.id]" :id="detailsId(entry)">
                                    <td colspan="6" class="bg-canvas-subtle px-4 py-3">
                                        <AuditChanges v-if="expanded[entry.id]" :entry="entry" :timezone="timezone" show-meta />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Small screens: cards -->
                <ol class="divide-y divide-muted md:hidden">
                    <li v-for="entry in rows" :key="entry.id" class="px-4 py-3">
                        <AuditEntrySummary :entry="entry" :timezone="timezone" context />
                    </li>
                </ol>
            </template>
        </div>

        <nav
            v-if="entries.prevUrl || entries.nextUrl"
            :aria-label="t('pagination')"
            class="flex items-center justify-between gap-3 border-t border-default px-4 py-2 text-fg-muted"
        >
            <Link v-if="entries.prevUrl" :href="entries.prevUrl" :class="[pagerBtn, 'text-accent-fg hover:border-default']">
                <IconChevronLeft />{{ t('audit_previous') }}
            </Link>
            <span v-else :class="[pagerBtn, 'text-fg-subtle']" aria-disabled="true"><IconChevronLeft />{{ t('audit_previous') }}</span>

            <Link v-if="entries.nextUrl" :href="entries.nextUrl" :class="[pagerBtn, 'text-accent-fg hover:border-default']">
                {{ t('audit_next') }}<IconChevronRight />
            </Link>
            <span v-else :class="[pagerBtn, 'text-fg-subtle']" aria-disabled="true">{{ t('audit_next') }}<IconChevronRight /></span>
        </nav>
    </div>
</template>
