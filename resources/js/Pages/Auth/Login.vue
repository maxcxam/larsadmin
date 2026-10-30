<script>
export default {
    layout: null,
}
</script>

<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3'
import IconAlert from '../../components/icons/IconAlert.vue'
import IconDatabase from '../../components/icons/IconDatabase.vue'
import { useI18n } from '../../composables/useI18n'
import LocaleSwitcher from '../../components/LocaleSwitcher.vue'
import ThemeSwitcher from '../../components/ThemeSwitcher.vue'

const page = usePage()
const { t } = useI18n()

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

function submit() {
    form.post(`${page.props.larsadmin.path}/login`, {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head :title="t('login_title')" />

    <div class="relative flex min-h-dvh items-center justify-center bg-canvas px-4 py-12">
        <div class="absolute top-4 right-4 flex items-center gap-2">
            <LocaleSwitcher variant="page" />
            <ThemeSwitcher variant="page" />
        </div>

        <div class="w-full max-w-[20rem]">
            <div class="mb-4 flex flex-col items-center gap-3 text-center">
                <IconDatabase class="size-10 text-fg" />
                <h1 class="text-2xl font-light">{{ t('sign_in_to', { name: page.props.larsadmin.name }) }}</h1>
            </div>

            <form class="space-y-4 rounded-md border border-default bg-canvas-subtle p-4" @submit.prevent="submit">
                <div>
                    <label for="email" class="mb-1 block">{{ t('email') }}</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        autofocus
                        required
                        class="larsadmin-input"
                        :class="{ 'larsadmin-input-invalid': form.errors.email }"
                    >
                    <p v-if="form.errors.email" class="mt-1 flex items-center gap-1 text-xs text-danger-fg" role="alert">
                        <IconAlert />{{ form.errors.email }}
                    </p>
                </div>

                <div>
                    <label for="password" class="mb-1 block">{{ t('password') }}</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="larsadmin-input"
                        :class="{ 'larsadmin-input-invalid': form.errors.password }"
                    >
                    <p v-if="form.errors.password" class="mt-1 flex items-center gap-1 text-xs text-danger-fg" role="alert">
                        <IconAlert />{{ form.errors.password }}
                    </p>
                </div>

                <label class="flex items-center gap-2 text-fg-muted">
                    <input v-model="form.remember" type="checkbox" class="larsadmin-checkbox">
                    {{ t('remember_me') }}
                </label>

                <button type="submit" :disabled="form.processing" class="larsadmin-btn-primary larsadmin-btn-block">
                    {{ t('sign_in') }}
                </button>
            </form>
        </div>
    </div>
</template>
