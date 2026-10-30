<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useLarsadmin } from '../composables/useLarsadmin'
import { useI18n } from '../composables/useI18n'
import { useDownload } from '../composables/useDownload'
import { isDashboardActive } from './navigation'
import IconAlert from '../components/icons/IconAlert.vue'
import IconCheck from '../components/icons/IconCheck.vue'
import IconDatabase from '../components/icons/IconDatabase.vue'
import IconHome from '../components/icons/IconHome.vue'
import IconMenu from '../components/icons/IconMenu.vue'
import IconSignOut from '../components/icons/IconSignOut.vue'
import IconX from '../components/icons/IconX.vue'
import LocaleSwitcher from '../components/LocaleSwitcher.vue'
import ThemeSwitcher from '../components/ThemeSwitcher.vue'

const page = usePage()
const { url, entityUrl } = useLarsadmin()
const { t } = useI18n()

const larsadmin = computed(() => page.props.larsadmin)
const user = computed(() => page.props.user)

const groups = computed(() => {
    const groups = new Map()

    for (const entity of page.props.navigation ?? []) {
        const items = groups.get(entity.group) ?? []
        items.push({ ...entity, href: entityUrl(entity.uriKey) })
        groups.set(entity.group, items)
    }

    return [...groups].map(([label, items]) => ({ label, items }))
})

const dashboards = computed(() => page.props.dashboards ?? [])
const dashboardActive = (dashboard) => isDashboardActive(dashboard, page)
// Single entry (or none): the plain home link; with an entry, highlight it only when that dashboard is shown.
const homeActive = computed(() => (dashboards.value.length ? dashboardActive(dashboards.value[0]) : page.component === 'Dashboard'))

const isActive = (href) => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`)

const drawerOpen = ref(false)

router.on('navigate', () => (drawerOpen.value = false))

useDownload()

const toast = ref(null)
let toastTimeout

watch(
    () => page.props.toast,
    (value) => {
        if (!value) return

        // Accepts a plain string or { message, type }
        toast.value = typeof value === 'string' ? { message: value, type: 'success' } : { type: 'success', ...value }
        clearTimeout(toastTimeout)
        toastTimeout = setTimeout(() => (toast.value = null), 4000)
    },
    { immediate: true },
)

function logout() {
    router.post(url('logout'))
}

const linkBase = 'relative flex items-center gap-2 rounded-md px-3 py-1.5 text-fg hover:bg-neutral-muted'
const linkActive =
    'bg-neutral-muted font-semibold before:absolute before:top-1.5 before:bottom-1.5 before:-left-2 before:w-1 before:rounded-full before:bg-accent-emphasis'
</script>

<template>
    <div class="flex min-h-dvh flex-col overflow-x-clip" @keydown.esc="drawerOpen = false">
        <header class="flex h-14 shrink-0 items-center gap-3 bg-header px-4 text-header-fg">
            <button
                type="button"
                class="-ml-1 rounded-md border border-white/20 p-1.5 hover:bg-white/10 md:hidden"
                :aria-expanded="drawerOpen"
                aria-controls="la-sidebar"
                :aria-label="t('toggle_navigation')"
                @click="drawerOpen = !drawerOpen"
            >
                <IconMenu />
            </button>

            <Link :href="url()" class="flex items-center gap-2 text-sm font-semibold hover:opacity-80">
                <IconDatabase />
                {{ larsadmin.name }}
            </Link>

            <div class="ml-auto flex items-center gap-3 text-xs">
                <span v-if="user" class="hidden sm:inline text-header-fg/70">{{ user.name ?? user.email }}</span>
                <LocaleSwitcher />
                <ThemeSwitcher />
                <button
                    v-if="user"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border border-white/20 px-2 py-1 hover:bg-white/10"
                    @click="logout"
                >
                    <IconSignOut />
                    {{ t('log_out') }}
                </button>
            </div>
        </header>

        <div class="relative flex min-w-0 flex-1 items-stretch">
            <div v-if="drawerOpen" class="fixed inset-0 top-14 z-20 bg-overlay md:hidden" @click="drawerOpen = false" />

            <aside
                id="la-sidebar"
                class="fixed inset-y-0 top-14 left-0 z-30 w-64 shrink-0 overflow-y-auto border-r border-default bg-canvas-subtle px-4 py-4 text-sm transition-transform md:sticky md:z-auto md:h-[calc(100dvh-3.5rem)] md:w-60 md:translate-x-0 md:self-start"
                :class="drawerOpen ? 'translate-x-0' : '-translate-x-full'"
            >
                <nav class="space-y-5" :aria-label="t('main_navigation')">
                    <div v-if="dashboards.length > 1" class="pl-2">
                        <h2 class="mb-1 px-3 text-xs font-semibold text-fg-muted">{{ t('dashboards') }}</h2>
                        <Link
                            v-for="dashboard in dashboards"
                            :key="dashboard.uriKey"
                            :href="dashboard.url"
                            :class="[linkBase, dashboardActive(dashboard) ? linkActive : '']"
                            :aria-current="dashboardActive(dashboard) ? 'page' : undefined"
                        >
                            <IconHome class="text-fg-muted" />
                            {{ dashboard.name }}
                        </Link>
                    </div>

                    <div v-else class="pl-2">
                        <Link
                            :href="dashboards[0]?.url ?? url()"
                            :class="[linkBase, homeActive ? linkActive : '']"
                            :aria-current="homeActive ? 'page' : undefined"
                        >
                            <IconHome class="text-fg-muted" />
                            {{ dashboards[0]?.name ?? t('dashboard') }}
                        </Link>
                    </div>

                    <div v-for="group in groups" :key="group.label ?? ''" class="pl-2">
                        <h2 class="mb-1 px-3 text-xs font-semibold text-fg-muted">
                            {{ group.label ?? t('entities') }}
                        </h2>
                        <Link
                            v-for="item in group.items"
                            :key="item.uriKey"
                            :href="item.href"
                            :class="[linkBase, isActive(item.href) ? linkActive : '']"
                        >
                            <IconDatabase class="text-fg-muted" />
                            {{ item.label }}
                        </Link>
                    </div>
                </nav>
            </aside>

            <main class="min-w-0 flex-1 px-4 py-6 md:px-6 lg:px-8">
                <div class="mx-auto w-full max-w-[1800px]">
                    <slot />
                </div>
            </main>
        </div>

        <Transition
            enter-from-class="translate-y-2 opacity-0"
            enter-active-class="transition"
            leave-to-class="opacity-0"
            leave-active-class="transition"
        >
            <div
                v-if="toast"
                class="fixed bottom-4 left-4 z-40 flex max-w-sm items-center gap-2 rounded-md border border-default bg-canvas py-2 pr-2 pl-3 text-sm shadow-overlay"
                :role="toast.type === 'error' ? 'alert' : 'status'"
            >
                <IconAlert v-if="toast.type === 'error'" class="text-danger-fg" />
                <IconCheck v-else class="text-success-fg" />
                <span>{{ toast.message }}</span>
                <button type="button" class="rounded p-1 text-fg-muted hover:bg-neutral-muted" :aria-label="t('dismiss')" @click="toast = null">
                    <IconX />
                </button>
            </div>
        </Transition>
    </div>
</template>
