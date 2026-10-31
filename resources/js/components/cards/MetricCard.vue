<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { useJsonResource } from '../../composables/useJsonResource'
import { withParams } from '../../composables/useLarsadmin'
import CardShell from './CardShell.vue'

// Built-in metric logic (ranges, `{ range, result }` endpoint) inside the public CardShell frame.
const props = defineProps({
    card: { type: Object, required: true },
    // (result) => boolean: show the empty state instead of the slot
    empty: { type: Function, default: () => false },
    skeletonClass: { type: String, default: 'h-24' },
})

const { t } = useI18n()
const { status, load } = useJsonResource()

const ranges = computed(() => props.card.ranges ?? [])
const range = ref(props.card.defaultRange ?? ranges.value[0]?.value ?? null)
const result = ref(null)

async function fetchData() {
    const body = await load(withParams(props.card.endpoint, { range: range.value }))

    if (body === undefined) return

    if (body === null) {
        result.value = null

        return
    }

    result.value = body.result ?? null
}

onMounted(fetchData)
watch(range, fetchData)

const isEmpty = computed(() => Boolean(result.value && props.empty(result.value)))
</script>

<template>
    <CardShell :card="card" :status="status" :ready="!!result" :empty="isEmpty" :skeleton-class="skeletonClass" @retry="fetchData">
        <template #actions>
            <select
                v-if="ranges.length > 1"
                v-model="range"
                class="larsadmin-input w-auto! min-h-6! py-0! text-xs"
                :aria-label="t('metric_range')"
            >
                <option v-for="r in ranges" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
        </template>

        <slot :result="result" />
    </CardShell>
</template>
