<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useLarsadmin } from '../composables/useLarsadmin'
import { useI18n } from '../composables/useI18n'
import CardGrid from '../components/cards/CardGrid.vue'
import IconChevronRight from '../components/icons/IconChevronRight.vue'
import IconDatabase from '../components/icons/IconDatabase.vue'

const page = usePage()
const { entityUrl } = useLarsadmin()
const { t } = useI18n()

const entities = computed(() => page.props.navigation ?? [])
const cards = computed(() => page.props.cards ?? [])
const title = computed(() => page.props.dashboard?.name ?? t('dashboard'))
</script>

<template>
    <Head :title="title" />

    <div class="mb-4 border-b border-default pb-4">
        <h1 class="text-2xl font-semibold">{{ title }}</h1>
        <p class="mt-1 text-fg-muted">{{ t('welcome', { name: page.props.larsadmin.name }) }}</p>
    </div>

    <CardGrid v-if="cards.length" :cards="cards" />

    <div v-else class="larsadmin-box">
        <div class="larsadmin-box-header text-sm font-semibold">{{ t('resources') }}</div>

        <ul v-if="entities.length" class="divide-y divide-muted">
            <li v-for="entity in entities" :key="entity.uriKey">
                <Link :href="entityUrl(entity.uriKey)" class="flex items-center gap-2 px-4 py-3 hover:bg-canvas-subtle">
                    <IconDatabase class="text-fg-muted" />
                    <span class="font-semibold">{{ entity.label }}</span>
                    <IconChevronRight class="ml-auto text-fg-muted" />
                </Link>
            </li>
        </ul>

        <div v-else class="px-4 py-12 text-center">
            <IconDatabase class="mx-auto mb-2 size-6 text-fg-muted" />
            <h2 class="text-base font-semibold">{{ t('no_resources') }}</h2>
            <p class="mt-1 text-fg-muted">{{ t('no_resources_hint') }}</p>
        </div>
    </div>
</template>
