<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { relatableParams } from './relatableParams'
import Combobox from '../../components/Combobox.vue'
import IconLock from '../../components/icons/IconLock.vue'

const props = defineProps({
    field: { type: Object, required: true },
    invalid: { type: Boolean, default: false },
    // 'create' | 'update' (passed down by EntityForm) | 'action' (ActionModal)
    context: { type: String, default: 'create' },
    resourceId: { type: [String, Number], default: null },
    // action uriKey, used when context is 'action'
    action: { type: String, default: null },
})

const model = defineModel()

const { t } = useI18n()

const params = computed(() => relatableParams(props))
const fixed = computed(() => props.field.locked || props.field.readonly)
</script>

<template>
    <div v-if="fixed" class="flex max-w-md items-center gap-2">
        <input
            :id="field.attribute"
            type="text"
            readonly
            :value="field.selected?.hidden ? t('hidden_record') : (field.selected?.title ?? field.displayedAs ?? '')"
            :aria-describedby="field.locked ? `${field.attribute}-locked` : undefined"
            class="larsadmin-input"
        >
        <span v-if="field.locked" :id="`${field.attribute}-locked`" class="inline-flex shrink-0 items-center gap-1 text-xs text-fg-muted">
            <IconLock />{{ t('locked_hint') }}
        </span>
    </div>
    <Combobox
        v-else
        :id="field.attribute"
        v-model="model"
        class="max-w-md"
        :selected="field.selected"
        :url="field.optionsUrl"
        :params="params"
        :searchable="field.searchable"
        :nullable="field.nullable"
        :invalid="invalid"
        :placeholder="field.placeholder"
    />
</template>
