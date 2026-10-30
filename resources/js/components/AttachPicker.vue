<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from '../composables/useI18n'
import Combobox from './Combobox.vue'
import IconPlus from './icons/IconPlus.vue'

const props = defineProps({
    // Parent record id (sent to the options endpoint as resourceId)
    resourceId: { type: [String, Number], required: true },
    optionsUrl: { type: String, required: true },
    attachUrl: { type: String, required: true },
    searchable: { type: Boolean, default: false },
})

const emit = defineEmits(['attached'])

const { t } = useI18n()

const selected = ref(null)
const processing = ref(false)
const error = ref(null)

function attach() {
    if (selected.value === null || processing.value) return

    router.post(props.attachUrl, { related: selected.value }, {
        preserveScroll: true,
        onStart: () => {
            processing.value = true
            error.value = null
        },
        onSuccess: () => {
            selected.value = null
            emit('attached')
        },
        onError: (errors) => {
            error.value = errors.related ?? Object.values(errors)[0] ?? t('options_error')
        },
        onFinish: () => {
            processing.value = false
        },
    })
}
</script>

<template>
    <div>
        <form class="flex items-start gap-2" @submit.prevent="attach">
            <div class="w-56 max-w-full">
                <Combobox
                    v-model="selected"
                    :url="optionsUrl"
                    :params="{ context: 'attach', resourceId }"
                    :searchable="searchable"
                    :invalid="!!error"
                    :disabled="processing"
                    :placeholder="t('attach_choose')"
                />
            </div>
            <button type="submit" class="larsadmin-btn-primary" :disabled="selected === null || processing">
                <IconPlus />{{ t('attach') }}
            </button>
        </form>
        <p v-if="error" class="mt-1 text-xs text-danger-fg" role="alert">{{ error }}</p>
    </div>
</template>
