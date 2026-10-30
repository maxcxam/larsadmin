<script setup>
import { computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useLarsadmin } from '../composables/useLarsadmin'
import { useI18n } from '../composables/useI18n'
import MenuSwitcher from './MenuSwitcher.vue'
import IconGlobe from './icons/IconGlobe.vue'

defineProps({
    // 'header' = on the dark header, 'page' = on the regular canvas
    variant: { type: String, default: 'header' },
})

const page = usePage()
const { url } = useLarsadmin()
const { t, locale } = useI18n()

const locales = computed(() => page.props.larsadmin?.locales ?? [])
const options = computed(() => locales.value.map((item) => ({ value: item.code, label: item.label })))
const currentLabel = computed(() => locales.value.find((item) => item.code === locale.value)?.label ?? locale.value)

function select(code) {
    if (code === locale.value) return

    router.post(url('locale'), { locale: code }, { preserveScroll: true })
}
</script>

<template>
    <MenuSwitcher
        v-if="locales.length >= 2"
        :options="options"
        :model-value="locale"
        :variant="variant"
        :button-label="t('language_current', { language: currentLabel })"
        :menu-label="t('language')"
        @select="select"
    >
        <template #trigger>
            <IconGlobe />
            <span class="px-0.5 text-xs font-semibold uppercase">{{ locale }}</span>
        </template>
    </MenuSwitcher>
</template>
