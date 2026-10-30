<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from '../../composables/useI18n'
import { useLarsadmin } from '../../composables/useLarsadmin'
import { currentPlatform, hotkeyLabel, isSearchHotkey } from './palette'
import IconSearch from '../icons/IconSearch.vue'
import SearchPalette from './SearchPalette.vue'

// Header entry point of the global search: a field-like button (an icon on small screens) and the
// Ctrl/⌘+K hotkey. Renders nothing when the shared prop `larsadmin.search` is null (no searchable entity).
const page = usePage()
const { url } = useLarsadmin()
const { t } = useI18n()

const config = computed(() => {
    const value = page.props.larsadmin?.search

    if (!value) return null

    const settings = typeof value === 'object' ? value : {}

    return {
        endpoint: url('api', 'search'),
        minLength: Math.max(1, Number(settings.minLength) || 2),
        maxLength: Math.max(1, Number(settings.maxLength) || 100),
    }
})

const hotkey = hotkeyLabel(currentPlatform())
const open = ref(false)
const palette = ref(null)
// The trigger that opened the palette gets focus back (Safari does not focus clicked buttons).
const opener = ref(null)

function show(trigger = null) {
    if (open.value) {
        palette.value?.focusInput()

        return
    }

    opener.value = trigger
    open.value = true
}

function onKeydown(event) {
    // defaultPrevented: a field (e.g. a rich-text editor) already handled its own Ctrl/⌘+K.
    if (!config.value || event.defaultPrevented || !isSearchHotkey(event)) return

    // Another modal (ConfirmDialog, ActionModal, a native <dialog>) owns the keyboard.
    if (!open.value && document.querySelector('[aria-modal="true"], dialog[open]')) return

    event.preventDefault()
    show()
}

let removeNavigateListener = null

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    removeNavigateListener = router.on('navigate', () => (open.value = false))
})

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown)
    removeNavigateListener?.()
})

function close() {
    open.value = false
}
</script>

<template>
    <div v-if="config" class="flex items-center">
        <button
            type="button"
            class="hidden h-7 w-56 items-center gap-2 rounded-md border border-white/20 bg-white/5 px-2 text-left text-xs text-header-fg/80 hover:bg-white/10 md:inline-flex lg:w-72"
            :aria-label="t('global_search_open_label', { hotkey })"
            aria-keyshortcuts="Control+K Meta+K"
            aria-haspopup="dialog"
            @click="show($event.currentTarget)"
        >
            <IconSearch />
            <span class="flex-1 truncate">{{ t('global_search_open') }}</span>
            <kbd class="rounded border border-white/25 px-1.5 font-sans text-[11px] leading-4 text-header-fg/80">{{ hotkey }}</kbd>
        </button>
        <button
            type="button"
            class="rounded-md border border-white/20 p-1.5 hover:bg-white/10 md:hidden"
            :aria-label="t('global_search_open_label', { hotkey })"
            aria-keyshortcuts="Control+K Meta+K"
            aria-haspopup="dialog"
            @click="show($event.currentTarget)"
        >
            <IconSearch />
        </button>

        <SearchPalette
            v-if="open"
            ref="palette"
            :endpoint="config.endpoint"
            :min-length="config.minLength"
            :max-length="config.maxLength"
            :return-to="opener"
            @close="close"
        />
    </div>
</template>
