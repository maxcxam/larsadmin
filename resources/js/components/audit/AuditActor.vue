<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { actorInfo } from './audit'
import IconDeviceDesktop from '../icons/IconDeviceDesktop.vue'

// Who made the change (SECA-05). The system actor is a badge with an icon; a user is their label snapshot plus
// "#id", so a user named "System" (or after another user) cannot pass for the system or for that user.
const props = defineProps({
    entry: { type: Object, required: true },
})

const { t } = useI18n()

const actor = computed(() => actorInfo(props.entry))
const showId = computed(() => actor.value.id !== null && actor.value.label !== `#${actor.value.id}`)
</script>

<template>
    <span v-if="actor.system" class="larsadmin-label larsadmin-label-accent gap-1 align-middle">
        <IconDeviceDesktop class="size-3" />{{ t('audit_system') }}
    </span>
    <span v-else class="break-words">
        <span class="font-semibold">{{ actor.label }}</span>
        <span v-if="showId" class="ml-1 text-xs font-normal text-fg-muted">#{{ actor.id }}</span>
    </span>
</template>
