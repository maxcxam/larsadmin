<script setup>
import { computed } from 'vue'
import { cardComponent } from './index'

const props = defineProps({ cards: { type: Array, default: () => [] } })

// Full class names so Tailwind can see them. 6-column grid on lg, 2 on md, 1 on mobile.
const SPANS = {
    '1/3': 'lg:col-span-2',
    '1/2': 'lg:col-span-3',
    '2/3': 'md:col-span-2 lg:col-span-4',
    full: 'md:col-span-2 lg:col-span-6',
}

const items = computed(() =>
    props.cards.map((card) => ({ card, component: cardComponent(card) })).filter((item) => item.component),
)
</script>

<template>
    <div v-if="items.length" class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-6">
        <div v-for="item in items" :key="item.card.endpoint ?? item.card.uriKey" class="min-w-0" :class="SPANS[item.card.width] ?? SPANS['1/3']">
            <component :is="item.component" :card="item.card" />
        </div>
    </div>
</template>
