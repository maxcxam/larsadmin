<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'
import IconCheck from './icons/IconCheck.vue'

// Compact "pick one of N" menu button (radio menu). Used by ThemeSwitcher and LocaleSwitcher.
const props = defineProps({
    // [{ value, label, icon? }]
    options: { type: Array, required: true },
    modelValue: { type: [String, Number], default: null },
    // Accessible name of the trigger button and of the menu
    buttonLabel: { type: String, required: true },
    menuLabel: { type: String, required: true },
    // 'header' = on the dark header, 'page' = on the regular canvas
    variant: { type: String, default: 'header' },
})

const emit = defineEmits(['select'])

const menuId = useId()
const open = ref(false)
const root = ref(null)
const button = ref(null)
const items = ref([])

const current = computed(() => props.options.find((option) => option.value === props.modelValue) ?? props.options[0])

function onOutside(event) {
    if (root.value && !root.value.contains(event.target)) close()
}

async function show() {
    open.value = true
    document.addEventListener('pointerdown', onOutside)
    await nextTick()
    const index = Math.max(props.options.findIndex((option) => option.value === props.modelValue), 0)
    items.value[index]?.focus()
}

function close(restoreFocus = false) {
    open.value = false
    document.removeEventListener('pointerdown', onOutside)
    if (restoreFocus) button.value?.focus()
}

function choose(value) {
    emit('select', value)
    close(true)
}

function move(event, step) {
    event.preventDefault()
    const index = items.value.indexOf(document.activeElement)
    items.value[(index + step + props.options.length) % props.options.length]?.focus()
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
        items.value[props.options.length - 1]?.focus()
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
    <div ref="root" class="relative">
        <button
            ref="button"
            type="button"
            class="inline-flex items-center justify-center gap-1 rounded-md border p-1.5"
            :class="
                variant === 'header'
                    ? 'border-white/20 text-header-fg hover:bg-white/10'
                    : 'border-default bg-canvas-subtle text-fg-muted hover:bg-neutral-muted'
            "
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="open ? menuId : undefined"
            :aria-label="buttonLabel"
            @click="open ? close() : show()"
            @keydown="onButtonKeydown"
        >
            <slot name="trigger" :option="current">
                <component :is="current.icon" v-if="current.icon" />
            </slot>
        </button>

        <div
            v-if="open"
            :id="menuId"
            role="menu"
            :aria-label="menuLabel"
            class="absolute right-0 z-50 mt-2 w-40 rounded-md border border-default bg-canvas py-1 text-sm text-fg shadow-overlay"
            @keydown="onMenuKeydown"
        >
            <button
                v-for="(option, index) in options"
                :key="option.value"
                :ref="(el) => (items[index] = el)"
                type="button"
                role="menuitemradio"
                :aria-checked="modelValue === option.value"
                class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-neutral-muted"
                @click="choose(option.value)"
            >
                <component :is="option.icon" v-if="option.icon" class="text-fg-muted" />
                <span class="flex-1">{{ option.label }}</span>
                <IconCheck v-if="modelValue === option.value" class="text-accent-fg" />
            </button>
        </div>
    </div>
</template>
