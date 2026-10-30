<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { displayComponent, panelComponent } from '../../fields'
import { useLarsadmin } from '../../composables/useLarsadmin'
import { useI18n } from '../../composables/useI18n'
import ActionMenu from '../../components/actions/ActionMenu.vue'
import ActionModal from '../../components/actions/ActionModal.vue'
import FieldBlocks from '../../components/layout/FieldBlocks.vue'
import ConfirmDialog from '../../components/ConfirmDialog.vue'
import Breadcrumbs from '../../components/Breadcrumbs.vue'
import IconPencil from '../../components/icons/IconPencil.vue'
import IconTrash from '../../components/icons/IconTrash.vue'

const props = defineProps({
    entity: { type: Object, required: true },
    record: { type: Object, required: true },
    actions: { type: Array, default: () => [] },
})

const pending = ref(null)

const { entityUrl, recordUrl } = useLarsadmin()
const { t } = useI18n()

const detailFields = computed(() => props.record.fields.filter((field) => !field.panel))
const panels = computed(() => props.record.fields.filter((field) => field.panel))

const editUrl = computed(() => recordUrl(props.entity.uriKey, props.record, 'edit'))
const destroyUrl = computed(() => recordUrl(props.entity.uriKey, props.record, 'destroy'))
const confirming = ref(false)

function destroy() {
    confirming.value = false

    if (destroyUrl.value) router.delete(destroyUrl.value)
}
</script>

<template>
    <Head :title="`${entity.singularLabel}: ${record.title}`" />

    <Breadcrumbs :items="[{ label: entity.label, href: entityUrl(entity.uriKey) }, { label: record.title }]" />

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-default pb-4">
        <h1 class="text-2xl font-semibold">{{ entity.singularLabel }}: {{ record.title }}</h1>

        <div class="flex gap-2">
            <ActionMenu v-if="actions.length" :actions="actions" context="detail" :runnable="record.actions ?? null" @run="pending = $event" />
            <Link v-if="editUrl" :href="editUrl" class="larsadmin-btn">
                <IconPencil />{{ t('edit') }}
            </Link>
            <button v-if="destroyUrl" type="button" class="larsadmin-btn-danger" @click="confirming = true">
                <IconTrash />{{ t('delete') }}
            </button>
        </div>
    </div>

    <FieldBlocks v-if="detailFields.length" :layout="record.layout ?? null" :fields="detailFields">
        <template #field="{ field, stacked }">
            <div :class="stacked ? 'grid gap-1 px-4 py-3' : 'grid gap-1 px-4 py-3 md:grid-cols-4 md:gap-4'">
                <div class="font-semibold text-fg-muted">{{ field.name }}</div>
                <div :class="['min-w-0 break-words', stacked ? '' : 'md:col-span-3']">
                    <component :is="displayComponent(field)" :field="field" />
                </div>
            </div>
        </template>
    </FieldBlocks>

    <component
        :is="panelComponent(field)"
        v-for="field in panels"
        :key="field.attribute"
        :field="field"
        :entity="entity"
        :record="record"
        class="mt-6"
    />

    <ActionModal
        v-if="pending"
        :action="pending"
        :entity="entity.uriKey"
        :resources="[record.id]"
        @close="pending = null"
    />

    <ConfirmDialog
        v-if="confirming"
        :message="t('delete_confirm')"
        :confirm-label="t('delete')"
        @confirm="destroy"
        @cancel="confirming = false"
    />
</template>
