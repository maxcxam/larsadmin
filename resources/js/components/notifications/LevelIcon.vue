<script setup>
import { computed } from 'vue'
import { levelIcon, normalizeLevel } from './notifications'
import IconAlert from '../icons/IconAlert.vue'
import IconBell from '../icons/IconBell.vue'
import IconCheck from '../icons/IconCheck.vue'
import IconDownload from '../icons/IconDownload.vue'
import IconFile from '../icons/IconFile.vue'
import IconHistory from '../icons/IconHistory.vue'

// Decorative icon of a notification / toast: the whitelisted `icon`, else the level's one. Colour is never
// the only signal - callers render the level as text (sr-only) too.
const props = defineProps({
    level: { type: String, default: 'info' },
    icon: { type: String, default: null },
})

const components = { bell: IconBell, info: IconBell, check: IconCheck, alert: IconAlert, download: IconDownload, file: IconFile, history: IconHistory }

const component = computed(() => components[levelIcon(props.level, props.icon)] ?? IconBell)

const color = computed(
    () =>
        ({
            success: 'text-success-fg',
            warning: 'text-[color:var(--la-chart-4)]',
            danger: 'text-danger-fg',
            info: 'text-accent-fg',
        })[normalizeLevel(props.level)],
)
</script>

<template>
    <component :is="component" :class="color" />
</template>
