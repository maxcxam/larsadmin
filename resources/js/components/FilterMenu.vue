<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from '../composables/useI18n'
import { cleanFilters, filterComponent, isFilterActive } from '../filters'
import IconChevronDown from './icons/IconChevronDown.vue'

// "Filters" popover button. Edits apply immediately: every change emits the complete active map.
const props = defineProps({
    // [{ key, label, component, options?, value, default }]
    filters: { type: Array, required: true },
    // Active values keyed by filter key
    modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const panelId = useId()
const open = ref(false)
const root = ref(null)
const button = ref(null)
const panel = ref(null)

const activeCount = computed(() => props.filters.filter((filter) => isFilterActive(props.modelValue[filter.key])).length)

function onOutside(event) {
    if (root.value && !root.value.contains(event.target)) close()
}

async function show() {
    open.value = true
    document.addEventListener('pointerdown', onOutside)
    await nextTick()
    panel.value?.querySelector('select, input, button')?.focus()
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

function update(key, value) {
    emit('update:modelValue', cleanFilters({ ...props.modelValue, [key]: value }, props.filters))
}

function reset() {
    // Defaulted filters are sent as explicit empties so the server does not re-apply the default.
    emit('update:modelValue', cleanFilters({}, props.filters))
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside))
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
            {{ t('filters') }}
            <span v-if="activeCount > 0" class="larsadmin-counter" :title="t('filters_active')">{{ activeCount }}</span>
            <IconChevronDown />
        </button>

        <div
            v-if="open"
            :id="panelId"
            ref="panel"
            role="dialog"
            :aria-label="t('filters')"
            class="fixed inset-x-4 z-50 mt-2 sm:absolute sm:inset-x-auto sm:left-0 sm:w-80 rounded-md border border-default bg-canvas text-sm text-fg shadow-overlay"
        >
            <div class="max-h-96 space-y-3 overflow-y-auto p-3">
                <component
                    :is="filterComponent(filter)"
                    v-for="filter in filters"
                    :key="filter.key"
                    :filter="filter"
                    :model-value="modelValue[filter.key] ?? null"
                    @update:model-value="update(filter.key, $event)"
                />
            </div>
            <div class="flex justify-end border-t border-default px-3 py-2">
                <button type="button" class="larsadmin-btn" :disabled="activeCount === 0" @click="reset">{{ t('filters_reset') }}</button>
            </div>
        </div>
    </div>
</template>
