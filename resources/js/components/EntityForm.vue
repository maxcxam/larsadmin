<script setup>
import { computed } from 'vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { formComponent } from '../fields'
import { useI18n } from '../composables/useI18n'
import IconAlert from './icons/IconAlert.vue'

const props = defineProps({
    fields: { type: Array, required: true },
    action: { type: String, required: true },
    method: { type: String, default: 'post' },
    submitLabel: { type: String, required: true },
    cancelHref: { type: String, required: true },
    // 'create' | 'update' — forwarded to inputs that talk to JSON endpoints (BelongsTo options)
    context: { type: String, default: 'create' },
    resourceId: { type: [String, Number], default: null },
})

const { t } = useI18n()
const page = usePage()

// ADR-008: keep via* params on store/update so the server can redirect back to the parent.
const submitUrl = computed(() => {
    const source = new URLSearchParams(page.url.split('?')[1] ?? '')
    const via = new URLSearchParams()

    for (const key of ['viaEntity', 'viaId', 'viaField']) {
        if (source.has(key)) via.set(key, source.get(key))
    }

    const query = via.toString()

    return query ? `${props.action}${props.action.includes('?') ? '&' : '?'}${query}` : props.action
})

const form = useForm(Object.fromEntries(props.fields.map((field) => [field.attribute, field.value])))

function submit() {
    form.submit(props.method, submitUrl.value, {
        preserveScroll: true,
    })
}
</script>

<template>
    <form class="max-w-4xl" @submit.prevent="submit">
        <div class="larsadmin-box divide-y divide-muted">
            <div v-for="field in fields" :key="field.attribute" class="grid gap-2 px-4 py-4 md:grid-cols-4 md:gap-4">
                <label :for="field.attribute" class="pt-1 font-semibold">
                    {{ field.name }}
                    <span v-if="field.required" class="text-danger-fg" aria-hidden="true">*</span>
                    <span v-if="field.required" class="sr-only">({{ t('required') }})</span>
                </label>

                <div class="min-w-0 md:col-span-3">
                    <component
                        :is="formComponent(field)"
                        v-model="form[field.attribute]"
                        :field="field"
                        :invalid="!!form.errors[field.attribute]"
                        :context="context"
                        :resource-id="resourceId"
                    />
                    <p v-if="form.errors[field.attribute]" class="mt-1.5 flex items-center gap-1 text-xs text-danger-fg" role="alert">
                        <IconAlert />{{ form.errors[field.attribute] }}
                    </p>
                    <p v-else-if="field.help" class="mt-1.5 text-xs text-fg-muted">{{ field.help }}</p>
                </div>
            </div>
        </div>

        <div class="mt-4 flex items-center justify-end gap-2">
            <Link :href="cancelHref" class="larsadmin-btn">{{ t('cancel') }}</Link>
            <button type="submit" class="larsadmin-btn-primary" :disabled="form.processing">
                {{ submitLabel }}
            </button>
        </div>
    </form>
</template>
