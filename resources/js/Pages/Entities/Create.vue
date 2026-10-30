<script setup>
import { Head } from '@inertiajs/vue3'
import { useLarsadmin } from '../../composables/useLarsadmin'
import { useI18n } from '../../composables/useI18n'
import Breadcrumbs from '../../components/Breadcrumbs.vue'
import EntityForm from '../../components/EntityForm.vue'

defineProps({
    entity: { type: Object, required: true },
    fields: { type: Array, required: true },
})

const { entityUrl } = useLarsadmin()
const { t } = useI18n()
</script>

<template>
    <Head :title="t('new_record', { entity: entity.singularLabel })" />

    <Breadcrumbs :items="[{ label: entity.label, href: entityUrl(entity.uriKey) }, { label: t('create') }]" />
    <h1 class="mb-4 border-b border-default pb-4 text-2xl font-semibold">{{ t('new_record', { entity: entity.singularLabel }) }}</h1>

    <EntityForm
        :fields="fields"
        :action="entityUrl(entity.uriKey)"
        method="post"
        context="create"
        :submit-label="t('create')"
        :cancel-href="entityUrl(entity.uriKey)"
    />
</template>
