<script setup>
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import { useI18n } from '../../composables/useI18n'
import IconAlert from '../icons/IconAlert.vue'
import IconLock from '../icons/IconLock.vue'

// Card frame (public SDK component, design 1.5 §5.5): title, help tooltip, loading skeleton, forbidden / error
// states with "Retry", the `default` slot for the content and the `actions` slot in the header (e.g. a range
// picker). Built-in metrics render through it too (MetricCard).
const props = defineProps({
    card: { type: Object, required: true },
    // useJsonResource / useCardData status: idle | loading | error | forbidden
    status: { type: String, default: 'idle' },
    // There is content to show (false while the first load is running: the skeleton is shown instead)
    ready: { type: Boolean, default: true },
    // Show the "no data" state instead of the slot
    empty: { type: Boolean, default: false },
    skeletonClass: { type: String, default: 'h-24' },
})

const emit = defineEmits(['retry'])

const { t } = useI18n()
const uid = useId()

const busy = computed(() => props.status === 'loading')
const showSkeleton = computed(() => busy.value && !props.ready)

// Help tooltip (WCAG 1.4.13): opens on hover or focus, stays open while the pointer is over the trigger or the
// tooltip itself, closes on Escape. The text is the button's description, so it is announced once.
const hovering = ref(false)
const focused = ref(false)
const dismissed = ref(false)
const helpOpen = computed(() => (hovering.value || focused.value) && !dismissed.value)

function onHelpEnter() {
    hovering.value = true
    dismissed.value = false
}

function onHelpFocus() {
    focused.value = true
    dismissed.value = false
}

function onEscape(event) {
    if (event.key === 'Escape' && helpOpen.value) dismissed.value = true
}

watch(helpOpen, (open) => (open ? document.addEventListener('keydown', onEscape) : document.removeEventListener('keydown', onEscape)))
onBeforeUnmount(() => document.removeEventListener('keydown', onEscape))
</script>

<template>
    <section class="larsadmin-box flex h-full flex-col" :aria-labelledby="`${uid}-h`" :aria-busy="busy">
        <header class="flex items-center gap-2 px-4 pt-3">
            <h2 :id="`${uid}-h`" class="min-w-0 flex-1 truncate text-sm font-semibold" :title="card.name">{{ card.name }}</h2>

            <span v-if="card.helpText" class="relative flex" @mouseenter="onHelpEnter" @mouseleave="hovering = false">
                <button
                    type="button"
                    class="flex size-5 items-center justify-center rounded-full border border-default text-xs text-fg-muted hover:bg-neutral-muted"
                    :aria-label="t('metric_help')"
                    :aria-describedby="`${uid}-help`"
                    @focus="onHelpFocus"
                    @blur="focused = false"
                >
                    ?
                </button>
                <!-- pt-1 instead of a margin: no gap, so the pointer can move onto the tooltip without closing it -->
                <span v-show="helpOpen" class="absolute top-full right-0 z-20 w-56 pt-1">
                    <span
                        :id="`${uid}-help`"
                        role="tooltip"
                        class="block rounded-md border border-default bg-canvas px-2 py-1 text-xs font-normal shadow-overlay"
                    >
                        {{ card.helpText }}
                    </span>
                </span>
            </span>

            <slot name="actions" />
        </header>

        <div class="flex-1 px-4 pt-2 pb-4">
            <div v-if="showSkeleton" class="animate-pulse space-y-2" :class="skeletonClass" role="status">
                <span class="sr-only">{{ t('loading') }}</span>
                <div class="h-8 w-1/2 rounded bg-neutral-muted" />
                <div class="h-3 w-1/3 rounded bg-neutral-muted" />
                <div class="h-10 w-full rounded bg-neutral-muted" />
            </div>

            <div v-else-if="status === 'forbidden'" class="flex flex-col items-center gap-1 py-6 text-center text-sm text-fg-muted" role="alert">
                <IconLock />
                {{ t('metric_forbidden') }}
            </div>

            <div v-else-if="status === 'error'" class="flex flex-col items-center gap-2 py-6 text-center text-sm" role="alert">
                <IconAlert class="text-danger-fg" />
                <span class="text-fg-muted">{{ t('metric_error') }}</span>
                <button type="button" class="larsadmin-btn larsadmin-btn-sm" @click="emit('retry')">{{ t('retry') }}</button>
            </div>

            <div v-else-if="empty" class="py-6 text-center text-sm text-fg-muted">{{ t('no_data') }}</div>

            <div v-else-if="ready" :class="busy ? 'opacity-60 transition-opacity' : ''">
                <slot />
            </div>
        </div>
    </section>
</template>
