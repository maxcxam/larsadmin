<script setup>
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { registry } from '../extensions/registry'
import { bounded, placeholder } from '../extensions/components'

// Page host of an extension tool (design 1.5 §4.5, §5.4, Q-65): GET {path}/tools/{tool} renders this page with
// `tool` = { name, uriKey, component } and the tool's own `props`. The component comes from the registry
// (`tools` of defineExtension) and renders inside ExtensionBoundary; until its extension loads (or when it
// failed) the page shows the extension placeholder.
const page = defineProps({
    tool: { type: Object, required: true },
    props: { type: Object, default: () => ({}) },
})

const component = computed(() => {
    const resolved = registry.resolve('tools', page.tool.component)

    return resolved ? bounded('tools', page.tool.component, resolved, { mode: 'tool' }) : placeholder('tool')
})

// The component gets `tool` without its registry key: name and uriKey are the documented contract.
const toolInfo = computed(() => ({ name: page.tool.name, uriKey: page.tool.uriKey }))
</script>

<template>
    <Head :title="tool.name" />

    <div class="mb-4 border-b border-default pb-4">
        <h1 class="text-2xl font-semibold">{{ tool.name }}</h1>
    </div>

    <component :is="component" :tool="toolInfo" :props="$props.props" />
</template>
