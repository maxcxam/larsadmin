<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from '../../composables/useI18n'
import IconKebab from '../icons/IconKebab.vue'

// Per-row "⋯" menu (ADR-010). `actions` are the entity's inline actions, `runnable` the uriKeys the server
// says the user may run on this row. Same keyboard model as ActionMenu. The panel is teleported to <body>
// with fixed positioning because the table wrapper scrolls horizontally and would clip it.
const props = defineProps({
    actions: { type: Array, default: () => [] },
    runnable: { type: Array, default: () => [] },
})

const emit = defineEmits(['run'])
const { t } = useI18n()

const menuId = useId()
const open = ref(false)
const button = ref(null)
const menu = ref(null)
const items = ref([])
const position = ref({ top: 0, right: 0 })

const available = computed(() => props.actions.filter((a) => props.runnable.includes(a.uriKey)))
// Destructive items go last, separated
const ordered = computed(() => [...available.value.filter((a) => !a.destructive), ...available.value.filter((a) => a.destructive)])
const separatorAt = computed(() => {
    const normal = available.value.filter((a) => !a.destructive).length

    return normal > 0 && normal < ordered.value.length ? normal : -1
})

function place() {
    const rect = button.value.getBoundingClientRect()

    position.value = { top: rect.bottom + 4, right: Math.max(window.innerWidth - rect.right, 8) }
}

function onOutside(event) {
    if (!menu.value?.contains(event.target) && !button.value?.contains(event.target)) close()
}

async function show() {
    place()
    open.value = true
    document.addEventListener('pointerdown', onOutside)
    window.addEventListener('resize', onViewportChange)
    window.addEventListener('scroll', onViewportChange, true)
    await nextTick()
    items.value[0]?.focus()
}

function close(restoreFocus = false) {
    open.value = false
    document.removeEventListener('pointerdown', onOutside)
    window.removeEventListener('resize', onViewportChange)
    window.removeEventListener('scroll', onViewportChange, true)
    if (restoreFocus) button.value?.focus()
}

function onViewportChange() {
    if (open.value) close()
}

function choose(action) {
    close(true)
    emit('run', action)
}

function move(event, step) {
    event.preventDefault()
    const list = items.value.filter(Boolean)
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
        items.value[0]?.focus()
    } else if (event.key === 'End') {
        event.preventDefault()
        items.value.filter(Boolean).at(-1)?.focus()
    } else if (event.key === 'Tab') close()
}

function onButtonKeydown(event) {
    if (event.key === 'ArrowDown' && !open.value) {
        event.preventDefault()
        show()
    }
}

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onOutside)
    window.removeEventListener('resize', onViewportChange)
    window.removeEventListener('scroll', onViewportChange, true)
})
</script>

<template>
    <div v-if="available.length" class="inline-flex">
        <button
            ref="button"
            type="button"
            class="inline-flex rounded-md p-1.5 text-fg-muted hover:bg-neutral-muted"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="open ? menuId : undefined"
            :title="t('row_actions')"
            :aria-label="t('row_actions')"
            @click="open ? close() : show()"
            @keydown="onButtonKeydown"
        >
            <IconKebab />
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                :id="menuId"
                ref="menu"
                role="menu"
                :aria-label="t('row_actions')"
                class="fixed z-50 w-56 rounded-md border border-default bg-canvas py-1 text-sm text-fg shadow-overlay"
                :style="{ top: `${position.top}px`, right: `${position.right}px` }"
                @keydown="onMenuKeydown"
            >
                <template v-for="(action, index) in ordered" :key="action.uriKey">
                    <div v-if="index === separatorAt" class="my-1 border-t border-muted" role="separator" />
                    <button
                        :ref="(el) => (items[index] = el)"
                        type="button"
                        role="menuitem"
                        class="flex w-full items-center px-3 py-1.5 text-left hover:bg-neutral-muted"
                        :class="action.destructive ? 'text-danger-fg' : ''"
                        @click="choose(action)"
                    >
                        {{ action.name }}
                    </button>
                </template>
            </div>
        </Teleport>
    </div>
</template>
