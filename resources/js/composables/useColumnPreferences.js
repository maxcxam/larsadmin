import { computed, ref, watch } from 'vue'
import { isDefault, loadPreferences, moveColumn, resolveColumns, savePreferences, serialize, toggleColumn } from './columnPreferences'

// Reactive column picker state. `key` / `columns` are getters so it follows the entity / loaded payload.
export function useColumnPreferences(key, columns) {
    const saved = ref(loadPreferences(key()))
    const override = ref(null) // items edited in this session

    const items = computed(() => override.value ?? resolveColumns(columns(), saved.value))
    const visibleColumns = computed(() => items.value.filter((item) => item.visible).map((item) => item.column))
    const customised = computed(() => !isDefault(columns(), items.value))

    watch(key, (value) => {
        saved.value = loadPreferences(value)
        override.value = null
    })

    // A new set of columns (e.g. different panel payload) re-merges the saved prefs.
    watch(columns, () => (override.value = null))

    function commit(next) {
        override.value = next
        saved.value = serialize(next)
        savePreferences(key(), isDefault(columns(), next) ? null : saved.value)
    }

    return {
        items,
        visibleColumns,
        customised,
        toggle: (attribute) => commit(toggleColumn(items.value, attribute)),
        move: (attribute, delta) => commit(moveColumn(items.value, attribute, delta)),
        reset() {
            override.value = null
            saved.value = null
            savePreferences(key(), null)
        },
    }
}
