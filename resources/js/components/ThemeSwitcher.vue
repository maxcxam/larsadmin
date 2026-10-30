<script setup>
import { computed } from 'vue'
import { useI18n } from '../composables/useI18n'
import { useTheme } from '../composables/useTheme'
import MenuSwitcher from './MenuSwitcher.vue'
import IconDeviceDesktop from './icons/IconDeviceDesktop.vue'
import IconMoon from './icons/IconMoon.vue'
import IconSun from './icons/IconSun.vue'

const { t } = useI18n()

defineProps({
    // 'header' = on the dark header, 'page' = on the regular canvas
    variant: { type: String, default: 'header' },
})

const { theme, setTheme } = useTheme()

const options = computed(() => [
    { value: 'light', label: t('theme_light'), icon: IconSun },
    { value: 'dark', label: t('theme_dark'), icon: IconMoon },
    { value: 'system', label: t('theme_system'), icon: IconDeviceDesktop },
])

const currentLabel = computed(() => options.value.find((option) => option.value === theme.value)?.label ?? t('theme_system'))
</script>

<template>
    <MenuSwitcher
        :options="options"
        :model-value="theme"
        :variant="variant"
        :button-label="t('theme_current', { theme: currentLabel })"
        :menu-label="t('theme')"
        @select="setTheme"
    />
</template>
