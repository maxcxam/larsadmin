<script setup>
import { computed, reactive, ref, useId, watch } from 'vue'
import { useI18n } from '../../composables/useI18n'
import IconChevronDown from '../icons/IconChevronDown.vue'
import IconChevronRight from '../icons/IconChevronRight.vue'
import { errorCount, firstTabWithErrors, normalizeLayout } from './layout'

// Renders the server `layout` tree (panels, tabs) around fields. The caller draws each field row through
// the #field slot, so forms and detail pages share the same blocks. No `layout` => one implicit panel.
const props = defineProps({
    layout: { type: Array, default: null },
    fields: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
})

const { t, tc } = useI18n()
const uid = useId()

const nodes = computed(() => normalizeLayout(props.layout, props.fields))
const byAttribute = computed(() => new Map(props.fields.map((field) => [field.attribute, field])))
const fieldsOf = (attributes) => attributes.map((attribute) => byAttribute.value.get(attribute)).filter(Boolean)

// Collapsed state per panel key; seeded from the server default, then owned by the user. A panel without a
// name has no header to toggle it from, so it is never collapsed.
const collapsedState = reactive({})
const isCollapsed = (panel) => !!(panel.collapsible && panel.name) && (collapsedState[panel.key] ?? panel.collapsed)
const toggle = (panel) => (collapsedState[panel.key] = !isCollapsed(panel))

const activeTab = ref({})
const tabIndex = (tabs) => Math.min(activeTab.value[tabs.key] ?? 0, tabs.tabs.length - 1)
const tabButtons = {}

function selectTab(tabs, index, focus = false) {
    activeTab.value = { ...activeTab.value, [tabs.key]: index }
    if (focus) tabButtons[`${tabs.key}-${index}`]?.focus()
}

function onTabKeydown(event, tabs, index) {
    const last = tabs.tabs.length - 1
    const next = { ArrowRight: index === last ? 0 : index + 1, ArrowLeft: index === 0 ? last : index - 1, Home: 0, End: last }[event.key]

    if (next === undefined) return

    event.preventDefault()
    selectTab(tabs, next, true)
}

// A failed submit reveals the first problem: expand panels with errors, switch tabs to the first erroneous one.
// Keyed on the errors object itself (a new one per submit) as well as its keys, so a resubmit that returns the
// same errors expands a panel the user collapsed in between.
watch(
    [() => props.errors, () => Object.keys(props.errors ?? {}).join('|')],
    () => {
        for (const node of nodes.value) {
            if (node.type === 'tabs') {
                const index = firstTabWithErrors(node.tabs, props.errors)

                if (index >= 0) selectTab(node, index)
                for (const tab of node.tabs) if (errorCount(tab.fields, props.errors) > 0) collapsedState[tab.key] = false
            } else if (errorCount(node.fields, props.errors) > 0) {
                collapsedState[node.key] = false
            }
        }
    },
)

const bodyClass = (panel) => (panel.columns === 2 ? 'grid md:grid-cols-2 [&>*:last-child]:border-b-0' : 'divide-y divide-muted')
const cellClass = (panel, field) => [
    panel.columns === 2 ? 'border-b border-muted' : '',
    panel.columns === 2 && field.fullWidth ? 'md:col-span-2' : '',
]

const errorLabel = (count) => tc('tab_errors', count)
const dom = (key) => `${uid}-${key}`
</script>

<template>
    <div class="space-y-4">
        <template v-for="node in nodes" :key="node.key">
            <section v-if="node.type === 'panel'" class="larsadmin-box" :aria-labelledby="node.name ? dom(`${node.key}-title`) : undefined">
                <header v-if="node.name" class="larsadmin-box-header">
                    <h2 :id="dom(`${node.key}-title`)" class="min-w-0 flex-1 text-base font-semibold">
                        <button
                            v-if="node.collapsible"
                            type="button"
                            class="flex w-full items-center gap-1.5 text-left"
                            :aria-expanded="!isCollapsed(node)"
                            :aria-controls="dom(`${node.key}-body`)"
                            :title="isCollapsed(node) ? t('expand') : t('collapse')"
                            @click="toggle(node)"
                        >
                            <IconChevronRight v-if="isCollapsed(node)" class="text-fg-muted" />
                            <IconChevronDown v-else class="text-fg-muted" />
                            {{ node.name }}
                            <span v-if="errorCount(node.fields, errors)" class="larsadmin-counter text-danger-fg">
                                {{ errorCount(node.fields, errors) }}
                                <span class="sr-only">{{ errorLabel(errorCount(node.fields, errors)) }}</span>
                            </span>
                        </button>
                        <template v-else>{{ node.name }}</template>
                    </h2>
                    <p v-if="node.description" class="w-full text-xs font-normal text-fg-muted">{{ node.description }}</p>
                </header>
                <p v-else-if="node.description" class="border-b border-muted px-4 py-3 text-xs text-fg-muted">{{ node.description }}</p>

                <div v-show="!isCollapsed(node)" :id="dom(`${node.key}-body`)" :class="bodyClass(node)">
                    <div v-for="field in fieldsOf(node.fields)" :key="field.attribute" :class="cellClass(node, field)">
                        <slot name="field" :field="field" :stacked="node.columns === 2" />
                    </div>
                </div>
            </section>

            <section v-else class="larsadmin-box">
                <div
                    role="tablist"
                    :aria-label="node.name ?? undefined"
                    class="flex gap-1 overflow-x-auto border-b border-default bg-canvas-subtle px-2 pt-2"
                >
                    <button
                        v-for="(tab, index) in node.tabs"
                        :id="dom(`${tab.key}-tab`)"
                        :key="tab.key"
                        :ref="(el) => (tabButtons[`${node.key}-${index}`] = el)"
                        type="button"
                        role="tab"
                        :aria-selected="tabIndex(node) === index"
                        :aria-controls="dom(`${tab.key}-panel`)"
                        :tabindex="tabIndex(node) === index ? 0 : -1"
                        class="-mb-px flex items-center gap-1.5 rounded-t-md border border-b-0 px-3 py-1.5 text-sm font-semibold whitespace-nowrap"
                        :class="tabIndex(node) === index ? 'border-default bg-canvas text-fg' : 'border-transparent text-fg-muted hover:text-fg'"
                        @click="selectTab(node, index)"
                        @keydown="onTabKeydown($event, node, index)"
                    >
                        {{ tab.name }}
                        <span v-if="errorCount(tab.fields, errors)" class="larsadmin-counter text-danger-fg">
                            {{ errorCount(tab.fields, errors) }}
                            <span class="sr-only">{{ errorLabel(errorCount(tab.fields, errors)) }}</span>
                        </span>
                    </button>
                </div>

                <div
                    v-for="(tab, index) in node.tabs"
                    v-show="tabIndex(node) === index"
                    :id="dom(`${tab.key}-panel`)"
                    :key="tab.key"
                    role="tabpanel"
                    :aria-labelledby="dom(`${tab.key}-tab`)"
                    tabindex="0"
                >
                    <p v-if="tab.description" class="border-b border-muted px-4 py-3 text-xs text-fg-muted">{{ tab.description }}</p>
                    <div :class="bodyClass(tab)">
                        <div v-for="field in fieldsOf(tab.fields)" :key="field.attribute" :class="cellClass(tab, field)">
                            <slot name="field" :field="field" :stacked="tab.columns === 2" />
                        </div>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
