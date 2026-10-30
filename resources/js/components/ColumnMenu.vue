<script setup>
import { nextTick, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from '../composables/useI18n'
import IconArrowDown from './icons/IconArrowDown.vue'
import IconArrowUp from './icons/IconArrowUp.vue'
import IconChevronDown from './icons/IconChevronDown.vue'

// "Columns" popover button: show/hide and reorder (up/down buttons, keyboard accessible).
const props = defineProps({
    // [{ column, visible }] from useColumnPreferences
    items: { type: Array, required: true },
    customised: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle', 'move', 'reset'])

const { t } = useI18n()

const panelId = useId()
const open = ref(false)
const root = ref(null)
const button = ref(null)
const panel = ref(null)

function onOutside(event) {
    if (root.value && !root.value.contains(event.target)) close()
}

async function show() {
    open.value = true
    document.addEventListener('pointerdown', onOutside)
    await nextTick()
    panel.value?.querySelector('input, button')?.focus()
}

function close(restoreFocus = false) {
    open.value = false
    document.removeEventListener('pointerdown', onOutside)
    if (restoreFocus) button.value?.focus()
}

function onKeydown(event) {
    if (event.key === 'Escape' && open.value) {
        event.stopPropagation()
        close(true)
    }
}

function onFocusOut(event) {
    if (open.value && event.relatedTarget && !root.value.contains(event.relatedTarget)) close()
}

// Keep focus on the moved row's button (or its sibling when it hit the edge).
async function move(attribute, delta) {
    emit('move', attribute, delta)
    await nextTick()
    const row = panel.value?.querySelector(`[data-column="${CSS.escape(attribute)}"]`)
    const wanted = row?.querySelector(delta < 0 ? '[data-dir="up"]' : '[data-dir="down"]')
    const other = row?.querySelector(delta < 0 ? '[data-dir="down"]' : '[data-dir="up"]')

    ;(wanted && !wanted.disabled ? wanted : other)?.focus()
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside))

const iconBtn = 'rounded p-1 text-fg-muted hover:bg-neutral-muted disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent'
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown" @focusout="onFocusOut">
        <button
            ref="button"
            type="button"
            class="larsadmin-btn"
            aria-haspopup="dialog"
            :aria-expanded="open"
            :aria-controls="open ? panelId : undefined"
            @click="open ? close() : show()"
        >
            {{ t('columns') }}
            <IconChevronDown />
        </button>

        <div
            v-if="open"
            :id="panelId"
            ref="panel"
            role="dialog"
            :aria-label="t('columns')"
            class="fixed inset-x-4 z-50 mt-2 rounded-md border border-default bg-canvas text-sm text-fg shadow-overlay sm:absolute sm:inset-x-auto sm:left-0 sm:w-72"
        >
            <ul class="max-h-96 overflow-y-auto py-1">
                <li
                    v-for="(item, index) in items"
                    :key="item.column.attribute"
                    :data-column="item.column.attribute"
                    class="flex items-center gap-2 px-3 py-1 hover:bg-neutral-muted"
                >
                    <label class="flex min-w-0 flex-1 items-center gap-2">
                        <input
                            type="checkbox"
                            class="larsadmin-checkbox shrink-0"
                            :checked="item.visible"
                            @change="emit('toggle', item.column.attribute)"
                        >
                        <span class="truncate">{{ item.column.name }}</span>
                    </label>
                    <button
                        type="button"
                        data-dir="up"
                        :class="iconBtn"
                        :disabled="index === 0"
                        :aria-label="t('column_move_up', { name: item.column.name })"
                        :title="t('column_move_up', { name: item.column.name })"
                        @click="move(item.column.attribute, -1)"
                    >
                        <IconArrowUp />
                    </button>
                    <button
                        type="button"
                        data-dir="down"
                        :class="iconBtn"
                        :disabled="index === items.length - 1"
                        :aria-label="t('column_move_down', { name: item.column.name })"
                        :title="t('column_move_down', { name: item.column.name })"
                        @click="move(item.column.attribute, 1)"
                    >
                        <IconArrowDown />
                    </button>
                </li>
            </ul>
            <div class="flex justify-end border-t border-default px-3 py-2">
                <button type="button" class="larsadmin-btn" :disabled="!customised" @click="emit('reset')">{{ t('columns_reset') }}</button>
            </div>
        </div>
    </div>
</template>
