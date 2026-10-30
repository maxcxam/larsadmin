<script setup>
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { useLarsadmin } from '../../composables/useLarsadmin'
import { useI18n } from '../../composables/useI18n'
import Breadcrumbs from '../../components/Breadcrumbs.vue'
import EntityForm from '../../components/EntityForm.vue'

const props = defineProps({
    entity: { type: Object, required: true },
    record: { type: Object, required: true },
})

const { entityUrl, recordUrl } = useLarsadmin()
const { t } = useI18n()

// Server-built URLs first (a null `show` means the record is not viewable); the resource URL is shared by PUT/DELETE.
const showUrl = computed(() => recordUrl(props.entity.uriKey, props.record, 'show'))
const submitUrl = computed(() => showUrl.value ?? props.record.urls?.destroy ?? entityUrl(props.entity.uriKey, props.record.id))
</script>

<template>
    <Head :title="t('edit_record', { title: record.title })" />

    <Breadcrumbs
        :items="[
            { label: entity.label, href: entityUrl(entity.uriKey) },
            { label: record.title, href: showUrl ?? undefined },
            { label: t('edit') },
        ]"
    />
    <h1 class="mb-4 border-b border-default pb-4 text-2xl font-semibold">{{ t('edit_record', { title: record.title }) }}</h1>

    <EntityForm
        :fields="record.fields"
        :layout="record.layout ?? null"
        :action="submitUrl"
        method="put"
        context="update"
        :resource-id="record.id"
        :submit-label="t('save_changes')"
        :cancel-href="showUrl ?? entityUrl(entity.uriKey)"
    />
</template>
