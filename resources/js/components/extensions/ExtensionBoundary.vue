<script setup>
import { computed, onErrorCaptured } from 'vue'
import { registry } from '../../extensions/registry'
import { isFatalComponentError } from '../../extensions/errors'
import { useToasts } from '../../composables/useToasts'
import { usePanelPage } from '../../sdk/page'
import UnavailableField from './UnavailableField.vue'

// Isolates an extension component (design 1.5 §5.4, ADR-032): an error thrown while it renders (setup, render,
// mount / update hooks) is logged with the extension's name and the component is switched off for the rest of the
// page session. Errors in event handlers, watchers and other hooks (CRX-01) are logged (and toasted in debug mode)
// but leave the component, and the user's input, in place. An override falls back to the built-in component; an extension key shows the
// "unavailable" placeholder, and the form leaves the field out of the submission (registry.isUnavailable).
const props = defineProps({
    kind: { type: String, required: true },
    componentKey: { type: String, required: true },
    part: { type: String, required: true },
    extension: { type: String, default: null },
    component: { type: [Object, Function], required: true },
    // Built-in component of an override (null for extension keys)
    fallback: { type: [Object, Function], default: null },
    mode: { type: String, default: 'form' },
    // Props and listeners for the component
    bind: { type: Object, default: () => ({}) },
})

const crashed = computed(() => registry.isCrashed(props.kind, props.componentKey, props.part))

// The built-in component gets exactly what it got in 1.4: no contract-only props that would fall through as
// HTML attributes on its root element. In an action modal (design 1.6 §8.3: `action` is { uriKey, entity }) the 1.4
// built-ins got neither `context` nor `resourceId`, so those are dropped with `action` itself.
const fallbackBind = computed(() => {
    const { errors, errorsByKey, describedBy, action, ...rest } = props.bind

    if (action !== null && typeof action === 'object') {
        delete rest.context
        delete rest.resourceId
    }

    if (props.mode !== 'form') delete rest.context

    return rest
})

const page = usePanelPage()

onErrorCaptured((error, _instance, info) => {
    if (crashed.value) return undefined

    const where = `Extension "${props.extension}": component "${props.componentKey}" (${props.part})`

    if (isFatalComponentError(info)) {
        console.error(`[larsadmin] ${where} failed to render and was switched off.`, error)
        registry.markCrashed(props.kind, props.componentKey, props.part)

        return false
    }

    console.error(`[larsadmin] ${where} threw in ${info}.`, error)

    if (page?.props?.larsadmin?.debug === true) {
        useToasts().push({ type: 'error', title: props.extension, message: `${props.componentKey}: ${error?.message ?? String(error)}`, timeout: 8000 })
    }

    return false
})
</script>

<template>
    <component :is="component" v-if="!crashed" v-bind="bind" />
    <component :is="fallback" v-else-if="fallback" v-bind="fallbackBind" />
    <UnavailableField v-else :mode="mode" :field="bind.field" :card="bind.card" :filter="bind.filter" :tool="bind.tool" />
</template>
