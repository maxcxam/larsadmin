<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { isActiveRun, isFailedRun, isFinishedRun, runStatusKey, safeHref } from './notifications'
import RunProgress from './RunProgress.vue'
import IconX from '../icons/IconX.vue'

// One background run in the bell: action, entity, status text, progress, cancel, result link.
const props = defineProps({
    run: { type: Object, required: true },
    cancelling: { type: Boolean, default: false },
    now: { type: Number, default: () => Date.now() },
})

const emit = defineEmits(['cancel', 'open'])

const { t } = useI18n()

const active = computed(() => isActiveRun(props.run))
const statusText = computed(() => t(runStatusKey(props.run, props.now)))
// Active runs unless the server says otherwise; a finished-looking run only when the server allows it
// (a stale run the server gave up on stays cancellable).
const showCancel = computed(() => (active.value && props.run.cancellable !== false) || props.run.cancellable === true)
const canCancel = computed(() => showCancel.value && !props.run.cancelRequested)
const href = computed(() => (isFinishedRun(props.run) ? safeHref(props.run.url) : null))
const linkLabel = computed(() => props.run.urlLabel || t(props.run.download ? 'notifications_download' : 'notifications_open'))
const statusClass = computed(() => {
    if (isFailedRun(props.run)) return 'text-danger-fg'

    return props.run.status === 'completed' ? 'text-success-fg' : 'text-fg-muted'
})
</script>

<template>
    <li class="grid gap-1.5 px-4 py-3" :aria-busy="active ? 'true' : 'false'">
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="font-semibold break-words">{{ run.action }}</p>
                <p class="text-xs text-fg-muted">
                    <span v-if="run.entity?.label">{{ run.entity.label }} · </span>
                    <span :class="statusClass">{{ statusText }}</span>
                </p>
            </div>
            <button
                v-if="showCancel"
                type="button"
                class="larsadmin-btn larsadmin-btn-sm shrink-0"
                :disabled="!canCancel || cancelling"
                :aria-label="cancelling || run.cancelRequested ? t('runs_cancelling') : t('runs_cancel', { action: run.action ?? '' })"
                @click="emit('cancel', run)"
            >
                <IconX v-if="!(cancelling || run.cancelRequested)" />
                <span aria-hidden="true">{{ cancelling || run.cancelRequested ? t('runs_cancelling') : t('act.cancel') }}</span>
            </button>
        </div>

        <RunProgress v-if="active || run.total" :run="run" />

        <p v-if="!active && run.message" class="text-xs break-words whitespace-pre-line text-fg-muted">{{ run.message }}</p>
        <a
            v-if="href"
            :href="href"
            :download="run.download ? '' : undefined"
            class="justify-self-start text-xs font-semibold text-accent-fg hover:underline"
            @click="emit('open', run, $event)"
        >
            {{ linkLabel }}
        </a>
    </li>
</template>
