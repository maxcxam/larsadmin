<script setup>
import { computed, getCurrentInstance } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from '../composables/useI18n'
import IconChevronLeft from './icons/IconChevronLeft.vue'
import IconChevronRight from './icons/IconChevronRight.vue'

const props = defineProps({
    paginator: { type: Object, required: true },
})

const emit = defineEmits(['navigate'])

const { t } = useI18n()

// Emit mode: with an @navigate listener, buttons emit the page number instead of rendering <Link>s.
const emitMode = !!getCurrentInstance()?.vnode.props?.onNavigate

// Page numbers with ellipses: 1 … 4 5 [6] 7 8 … 20
const pages = computed(() => {
    const { current_page: current, last_page: last } = props.paginator
    const set = new Set([1, last, current - 1, current, current + 1])
    const numbers = [...set].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b)
    const result = []

    for (const n of numbers) {
        if (result.length && n - result[result.length - 1] > 1) result.push('…')
        result.push(n)
    }

    return result
})

function pageUrl(page) {
    const base = props.paginator.next_page_url ?? props.paginator.prev_page_url
    if (!base) return null

    const url = new URL(base, window.location.origin)
    url.searchParams.set('page', page)

    return url.pathname + url.search
}

const btn = 'inline-flex min-w-8 items-center justify-center gap-1 rounded-md border border-transparent px-2 py-1'
</script>

<template>
    <nav :aria-label="t('pagination')" class="flex flex-wrap items-center justify-between gap-3 text-fg-muted">
        <span v-if="paginator.total">{{ t('showing_range', { from: paginator.from, to: paginator.to, total: paginator.total }) }}</span>
        <span v-else />

        <div v-if="paginator.last_page > 1" class="flex items-center gap-1">
            <template v-if="emitMode">
                <button
                    type="button"
                    :disabled="paginator.current_page <= 1"
                    :class="[btn, paginator.current_page > 1 ? 'text-accent-fg hover:border-default' : 'text-fg-subtle']"
                    @click="emit('navigate', paginator.current_page - 1)"
                >
                    <IconChevronLeft />{{ t('previous') }}
                </button>

                <template v-for="(item, index) in pages" :key="index">
                    <span v-if="item === '…'" class="px-1">…</span>
                    <span
                        v-else-if="item === paginator.current_page"
                        :class="[btn, 'bg-accent-emphasis font-semibold text-fg-on-emphasis']"
                        aria-current="page"
                    >{{ item }}</span>
                    <button v-else type="button" :class="[btn, 'text-fg hover:border-default']" @click="emit('navigate', item)">{{ item }}</button>
                </template>

                <button
                    type="button"
                    :disabled="paginator.current_page >= paginator.last_page"
                    :class="[btn, paginator.current_page < paginator.last_page ? 'text-accent-fg hover:border-default' : 'text-fg-subtle']"
                    @click="emit('navigate', paginator.current_page + 1)"
                >
                    {{ t('next') }}<IconChevronRight />
                </button>
            </template>

            <template v-else>
                <component
                    :is="paginator.prev_page_url ? Link : 'span'"
                    :href="paginator.prev_page_url"
                    preserve-scroll
                    :class="[btn, paginator.prev_page_url ? 'text-accent-fg hover:border-default' : 'text-fg-subtle']"
                >
                    <IconChevronLeft />{{ t('previous') }}
                </component>

                <template v-for="(item, index) in pages" :key="index">
                    <span v-if="item === '…'" class="px-1">…</span>
                    <span
                        v-else-if="item === paginator.current_page"
                        :class="[btn, 'bg-accent-emphasis font-semibold text-fg-on-emphasis']"
                        aria-current="page"
                    >{{ item }}</span>
                    <Link v-else :href="pageUrl(item)" preserve-scroll :class="[btn, 'text-fg hover:border-default']">{{ item }}</Link>
                </template>

                <component
                    :is="paginator.next_page_url ? Link : 'span'"
                    :href="paginator.next_page_url"
                    preserve-scroll
                    :class="[btn, paginator.next_page_url ? 'text-accent-fg hover:border-default' : 'text-fg-subtle']"
                >
                    {{ t('next') }}<IconChevronRight />
                </component>
            </template>
        </div>
    </nav>
</template>
