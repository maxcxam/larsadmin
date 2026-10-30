<script setup>
import { computed } from 'vue'
import { useI18n } from '../../composables/useI18n'
import IconAlert from '../icons/IconAlert.vue'
import { cardComponent } from './index'

const props = defineProps({ cards: { type: Array, default: () => [] } })

// Full class names so Tailwind can see them. 6-column grid on lg, 2 on md, 1 on mobile.
const SPANS = {
    '1/3': 'lg:col-span-2',
    '1/2': 'lg:col-span-3',
    '2/3': 'md:col-span-2 lg:col-span-4',
    full: 'md:col-span-2 lg:col-span-6',
}

const { t } = useI18n()

// A card whose `component` is not registered (typo, missing registerCard()) shows a visible placeholder
// instead of disappearing (CR11-12).
const items = computed(() =>
    props.cards.map((card) => {
        const component = cardComponent(card)

        if (!component && import.meta.env?.DEV) {
            console.warn(`[larsadmin] Unknown card component "${card?.component}". Register it with registerCard().`, card)
        }

        return { card, component }
    }),
)
</script>

<template>
    <div v-if="items.length" class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-6">
        <div v-for="item in items" :key="item.card.endpoint ?? item.card.uriKey" class="min-w-0" :class="SPANS[item.card.width] ?? SPANS['1/3']">
            <component :is="item.component" v-if="item.component" :card="item.card" />
            <section v-else class="larsadmin-box flex h-full items-center gap-2 px-4 py-3 text-sm text-fg-muted">
                <IconAlert class="shrink-0 text-danger-fg" />
                <span class="min-w-0 break-words">{{ t('unknown_card', { component: item.card?.component ?? '?' }) }}</span>
            </section>
        </div>
    </div>
</template>
