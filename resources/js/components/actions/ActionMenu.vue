<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from '../../composables/useI18n'
import IconChevronDown from '../icons/IconChevronDown.vue'

// GitHub-style "Actions" dropdown. Emits `run` with the chosen action; the parent opens ActionModal.
const props = defineProps({
    actions: { type: Array, default: () => [] },
    // 'index' | 'detail'
    context: { type: String, default: 'index' },
    // Number of selected rows (index); 0 = none
    selectedCount: { type: Number, default: 0 },
    // Detail only: uriKeys runnable on this record (record.actions); null = unknown, treat all as runnable
    runnable: { type: Array, default: null },
})

const emit = defineEmits(['run'])
const { t } = useI18n()

const menuId = useId()
const open = ref(false)
const root = ref(null)
const button = ref(null)
const items = ref([])

// Detail: actions the user cannot run on this record are hidden, like the per-row menu (CR11-17).
const isRunnable = (a) => props.context !== 'detail' || a.standalone || props.runnable === null || props.runnable.includes(a.uriKey)
const visible = computed(() => props.actions.filter((a) => (props.context === 'detail' ? a.showOnDetail : a.showOnIndex) && isRunnable(a)))
// Standalone actions need no selection; the rest require at least one selected row (index only)
const isEnabled = (a) => props.context === 'detail' || a.standalone || props.selectedCount > 0
const normal = computed(() => visible.value.filter((a) => !a.destructive))
const destructive = computed(() => visible.value.filter((a) => a.destructive))
const ordered = computed(() => [...normal.value, ...destructive.value])

function onOutside(event) {
    if (root.value && !root.value.contains(event.target)) close()
}

async function show() {
    open.value = true
    document.addEventListener('pointerdown', onOutside)
    await nextTick()
    enabledItems()[0]?.focus()
}

function close(restoreFocus = false) {
    open.value = false
    document.removeEventListener('pointerdown', onOutside)
    if (restoreFocus) button.value?.focus()
}

function choose(action) {
    if (!isEnabled(action)) return
    // Don't restore focus to the trigger: the modal remembers it as its own return target
    close(true)
    emit('run', action)
}

function enabledItems() {
    return items.value.filter((el) => el && !el.disabled)
}

function move(event, step) {
    event.preventDefault()
    const list = enabledItems()
    if (!list.length) return
    const index = list.indexOf(document.activeElement)
    list[(index + step + list.length) % list.length]?.focus()
}

function onMenuKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation()
        close(true)
    } else if (event.key === 'ArrowDown') move(event, 1)
    else if (event.key === 'ArrowUp') move(event, -1)
    else if (event.key === 'Home') {
        event.preventDefault()
        enabledItems()[0]?.focus()
    } else if (event.key === 'End') {
        event.preventDefault()
        enabledItems().at(-1)?.focus()
    } else if (event.key === 'Tab') close()
}

function onButtonKeydown(event) {
    if (event.key === 'ArrowDown' && !open.value) {
        event.preventDefault()
        show()
    }
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside))
</script>

<template>
    <div v-if="visible.length" ref="root" class="relative">
        <button
            ref="button"
            type="button"
            class="larsadmin-btn"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="open ? menuId : undefined"
            @click="open ? close() : show()"
            @keydown="onButtonKeydown"
        >
            {{ t('act.title') }}
            <span v-if="context === 'index' && selectedCount > 0" class="larsadmin-counter">{{ selectedCount }}</span>
            <IconChevronDown />
        </button>

        <div
            v-if="open"
            :id="menuId"
            role="menu"
            :aria-label="t('act.title')"
            class="absolute right-0 z-50 mt-2 w-64 rounded-md border border-default bg-canvas py-1 text-sm text-fg shadow-overlay"
            @keydown="onMenuKeydown"
        >
            <p v-if="context === 'index'" class="px-3 py-1.5 text-xs text-fg-muted">
                {{ selectedCount > 0 ? t('act.selected', { count: selectedCount }) : t('act.none_selected') }}
            </p>
            <template v-for="(action, index) in ordered" :key="action.uriKey">
                <div v-if="destructive.length && normal.length && index === normal.length" class="my-1 border-t border-muted" role="separator" />
                <button
                    :ref="(el) => (items[index] = el)"
                    type="button"
                    role="menuitem"
                    :disabled="!isEnabled(action)"
                    class="flex w-full items-center px-3 py-1.5 text-left hover:bg-neutral-muted disabled:cursor-not-allowed disabled:text-fg-muted disabled:hover:bg-transparent"
                    :class="action.destructive && isEnabled(action) ? 'text-danger-fg' : ''"
                    @click="choose(action)"
                >
                    {{ action.name }}
                </button>
            </template>
        </div>
    </div>
</template>
