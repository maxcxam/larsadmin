<script setup>
import { computed, useId } from 'vue'
import { useI18n } from '../../composables/useI18n'
import { actionFieldRows, changeRows, recordDeleted } from './audit'
import AuditValue from './AuditValue.vue'
import IconLock from '../icons/IconLock.vue'

// Expanded audit entry: the "Field · Before · After" diff (a table; stacked on small screens), the action
// parameters and, on the global page, the IP address and user agent. Read-only.
const props = defineProps({
    entry: { type: Object, required: true },
    timezone: { type: String, default: null },
    // Show IP / user agent (global page only, §8.9)
    showMeta: { type: Boolean, default: false },
})

const { t } = useI18n()
const actionFieldsId = `${useId()}-action-fields`

const rows = computed(() => changeRows(props.entry))
const actionFields = computed(() => actionFieldRows(props.entry))
const deleted = computed(() => recordDeleted(props.entry))
const meta = computed(() => (props.showMeta
    ? [
        { key: 'ip', label: t('audit_ip'), value: props.entry.ip },
        { key: 'ua', label: t('audit_user_agent'), value: props.entry.userAgent },
    ].filter((item) => item.value)
    : []))

// "Before" / "After" backgrounds are decoration only: the column headers (and the stacked labels) carry the meaning.
const oldClass = 'bg-danger-subtle'
const newClass = 'bg-success-subtle'
</script>

<template>
    <div class="space-y-3 text-sm">
        <p v-if="deleted" class="font-semibold">{{ t('audit_record_deleted') }}</p>

        <template v-if="rows.length">
            <div class="hidden overflow-x-auto rounded-md border border-default sm:block">
                <table class="w-full table-fixed text-left">
                    <caption class="sr-only">{{ t('audit_changes_caption') }}</caption>
                    <thead>
                        <tr class="border-b border-default bg-canvas-subtle text-xs text-fg-muted">
                            <th scope="col" class="w-1/4 px-3 py-1.5 font-semibold">{{ t('audit_field') }}</th>
                            <th scope="col" class="px-3 py-1.5 font-semibold">{{ t('audit_old') }}</th>
                            <th scope="col" class="px-3 py-1.5 font-semibold">{{ t('audit_new') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-muted">
                        <tr v-for="row in rows" :key="row.key" class="align-top">
                            <th scope="row" class="px-3 py-1.5 font-semibold break-words">{{ row.label }}</th>
                            <td v-if="row.masked" colspan="2" class="px-3 py-1.5">
                                <span class="inline-flex items-center gap-1 text-fg-muted"><IconLock />{{ t('audit_masked') }}</span>
                            </td>
                            <template v-else>
                                <td :class="['px-3 py-1.5', oldClass]"><AuditValue :value="row.old" :timezone="timezone" /></td>
                                <td :class="['px-3 py-1.5', newClass]"><AuditValue :value="row.new" :timezone="timezone" /></td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>

            <dl class="divide-y divide-muted rounded-md border border-default sm:hidden" :aria-label="t('audit_changes_caption')">
                <div v-for="row in rows" :key="row.key" class="space-y-1 px-3 py-2">
                    <dt class="font-semibold break-words">{{ row.label }}</dt>
                    <dd v-if="row.masked">
                        <span class="inline-flex items-center gap-1 text-fg-muted"><IconLock />{{ t('audit_masked') }}</span>
                    </dd>
                    <template v-else>
                        <dd :class="['rounded px-2 py-1', oldClass]">
                            <span class="block text-xs font-semibold text-fg-muted">{{ t('audit_old') }}</span>
                            <AuditValue :value="row.old" :timezone="timezone" />
                        </dd>
                        <dd :class="['rounded px-2 py-1', newClass]">
                            <span class="block text-xs font-semibold text-fg-muted">{{ t('audit_new') }}</span>
                            <AuditValue :value="row.new" :timezone="timezone" />
                        </dd>
                    </template>
                </div>
            </dl>
        </template>

        <div v-if="actionFields.length">
            <p :id="actionFieldsId" class="mb-1 text-xs font-semibold text-fg-muted">{{ t('audit_action_fields') }}</p>
            <dl :aria-labelledby="actionFieldsId" class="grid gap-x-4 gap-y-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,3fr)]">
                <template v-for="field in actionFields" :key="field.key">
                    <dt class="font-semibold break-words">{{ field.label }}</dt>
                    <dd class="min-w-0"><AuditValue :value="field.value" :timezone="timezone" /></dd>
                </template>
            </dl>
        </div>

        <dl v-if="meta.length" class="grid gap-x-4 gap-y-1 text-fg-muted sm:grid-cols-[minmax(0,1fr)_minmax(0,3fr)]">
            <template v-for="item in meta" :key="item.key">
                <dt class="font-semibold">{{ item.label }}</dt>
                <dd class="min-w-0 break-all">{{ item.value }}</dd>
            </template>
        </dl>
    </div>
</template>
