<script setup>
import { computed, onMounted, ref, useId, watch } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { useJsonResource } from '../../composables/useJsonResource'
import { appendPage, isHistoryPage, pageUrl } from './audit'
import AuditEntrySummary from './AuditEntrySummary.vue'
import IconAlert from '../icons/IconAlert.vue'
import IconHistory from '../icons/IconHistory.vue'

// "History" panel at the bottom of a record's detail page (§10.1): loads the first 10 entries on mount,
// "Show more" appends the next page. Read-only. Rendered only when the page has an `audit` prop
// (the server decides: audit enabled, entity auditable, viewLarsadminAudit gate).
const props = defineProps({
    // GET {path}/api/entities/{entity}/{id}/audit
    url: { type: String, required: true },
    // The shown record: a change (Inertia reload after save / attach / action) reloads the first page.
    record: { type: Object, default: null },
})

const { t, tc } = useI18n()

const titleId = `${useId()}-title`

const resource = useJsonResource()
const status = resource.status // idle | loading | error | forbidden

const entries = ref(null) // null until the first page has loaded
const page = ref(1)
const hasMore = ref(false)
const timezone = ref(null)
const loadingMore = ref(false)
const refreshing = ref(false) // background reload of page 1 (after a save / attach / action)
const moreFailed = ref(false)
const announcement = ref('')

// Bumped on every first-page load: a "Show more" answer from an older generation (or another record) is dropped.
let generation = 0

async function load() {
    const hadData = entries.value !== null
    const mine = ++generation

    announcement.value = hadData ? '' : t('audit_loading')
    moreFailed.value = false
    loadingMore.value = false
    refreshing.value = hadData

    // Shares the resource with loadMore(): a pending "Show more" request is aborted and its answer ignored.
    const body = await resource.load(pageUrl(props.url, 1), { background: hadData })

    // Superseded by a newer request: it owns the state now.
    if (body === undefined || mine !== generation) return

    refreshing.value = false

    if (!isHistoryPage(body)) {
        if (body !== null) resource.status.value = 'error'
        // A failed background refresh keeps the entries on screen.
        if (hadData && status.value === 'error') status.value = 'idle'
        announcement.value = hadData ? '' : t('audit_error')

        return
    }

    entries.value = body.entries
    page.value = Number(body.page) || 1
    hasMore.value = body.hasMore === true
    timezone.value = typeof body.timezone === 'string' ? body.timezone : null
    announcement.value = ''
}

async function loadMore() {
    // Disabled while page 1 is being refreshed, so the refresh (the user's newest change) is never superseded.
    if (loadingMore.value || refreshing.value || !hasMore.value || entries.value === null) return

    const request = { url: props.url, generation, page: page.value + 1 }

    loadingMore.value = true
    moreFailed.value = false
    announcement.value = t('audit_loading')

    const body = await resource.load(pageUrl(request.url, request.page), { background: true })

    if (body === undefined || request.generation !== generation) return

    loadingMore.value = false

    const next = appendPage({ url: props.url, generation, page: page.value, entries: entries.value }, request, body)

    if (next === null) {
        // Keep what is on screen; offer a retry next to the button.
        status.value = 'idle'
        moreFailed.value = true
        announcement.value = t('audit_error')

        return
    }

    entries.value = next.entries
    page.value = next.page
    hasMore.value = next.hasMore
    if (typeof body.timezone === 'string') timezone.value = body.timezone
    announcement.value = tc('audit_loaded_more', next.added)
}

function retry() {
    entries.value = null
    load()
}

onMounted(load)

// Another record (detail page reused by Inertia) starts over.
watch(() => props.url, () => {
    entries.value = null
    load()
})

// Same record, fresh props after a save / attach / action: reload the first page in the background.
watch(() => props.record, (value, old) => {
    if (value !== old) load()
})

const isEmpty = computed(() => Array.isArray(entries.value) && entries.value.length === 0)
</script>

<template>
    <section class="larsadmin-box" :aria-labelledby="titleId">
        <div class="larsadmin-box-header">
            <h2 :id="titleId" class="flex items-center gap-2 text-base font-semibold">
                <IconHistory class="text-fg-muted" />
                {{ t('audit_history') }}
            </h2>
        </div>

        <p class="sr-only" role="status">{{ announcement }}</p>

        <div v-if="entries === null && status === 'loading'" class="animate-pulse space-y-3 px-4 py-4" aria-hidden="true">
            <div v-for="n in 3" :key="n" class="h-4 rounded bg-neutral-muted" />
        </div>

        <div v-else-if="entries === null && (status === 'error' || status === 'forbidden')" class="flex items-center gap-2 px-4 py-6 text-danger-fg" role="alert">
            <IconAlert />
            <span class="flex-1">{{ status === 'forbidden' ? t('panel_forbidden') : t('audit_error') }}</span>
            <button v-if="status === 'error'" type="button" class="larsadmin-btn" @click="retry">{{ t('audit_retry') }}</button>
        </div>

        <p v-else-if="isEmpty" class="px-4 py-6 text-center text-fg-muted">{{ t('audit_empty') }}</p>

        <template v-else-if="entries">
            <ol class="divide-y divide-muted">
                <li v-for="entry in entries" :key="entry.id" class="px-4 py-3">
                    <AuditEntrySummary :entry="entry" :timezone="timezone" />
                </li>
            </ol>

            <div v-if="hasMore || moreFailed" class="flex flex-wrap items-center justify-center gap-3 border-t border-default px-4 py-2">
                <span v-if="moreFailed" class="flex items-center gap-2 text-danger-fg" role="alert">
                    <IconAlert />{{ t('audit_error') }}
                </span>
                <button
                    v-if="hasMore"
                    type="button"
                    class="larsadmin-btn"
                    :disabled="loadingMore || refreshing"
                    :aria-busy="loadingMore || refreshing || undefined"
                    @click="loadMore"
                >
                    {{ moreFailed ? t('audit_retry') : t('audit_load_more') }}
                </button>
            </div>
        </template>
    </section>
</template>
