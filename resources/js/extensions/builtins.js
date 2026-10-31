import { registry } from './registry.js'
import TextDisplay from '../fields/display/TextDisplay.vue'
import TextareaDisplay from '../fields/display/TextareaDisplay.vue'
import BooleanDisplay from '../fields/display/BooleanDisplay.vue'
import BelongsToDisplay from '../fields/display/BelongsToDisplay.vue'
import FileDisplay from '../fields/display/FileDisplay.vue'
import ImageDisplay from '../fields/display/ImageDisplay.vue'
import TextInput from '../fields/form/TextInput.vue'
import TextareaInput from '../fields/form/TextareaInput.vue'
import NumberInput from '../fields/form/NumberInput.vue'
import BooleanInput from '../fields/form/BooleanInput.vue'
import SelectInput from '../fields/form/SelectInput.vue'
import DateInput from '../fields/form/DateInput.vue'
import BelongsToInput from '../fields/form/BelongsToInput.vue'
import FileInput from '../fields/form/FileInput.vue'
import RelatedPanel from '../components/RelatedPanel.vue'
import SelectFilter from '../filters/SelectFilter.vue'
import BooleanFilter from '../filters/BooleanFilter.vue'
import DateRangeFilter from '../filters/DateRangeFilter.vue'
import ValueMetric from '../components/cards/ValueMetric.vue'
import TrendMetric from '../components/cards/TrendMetric.vue'
import PartitionMetric from '../components/cards/PartitionMetric.vue'
import ProgressMetric from '../components/cards/ProgressMetric.vue'

// Every built-in key goes through the same register() call that extensions use (design 1.5 §10, dogfooding),
// which also reserves these keys. Same components as the 1.4 maps; keys that had no entry in a 1.4 map keep
// the 1.4 fallback (TextDisplay / TextInput / SelectFilter) in the fields/filters wrappers.
export const BUILTIN_FIELDS = {
    'id-field': { display: TextDisplay },
    'text-field': { form: TextInput, display: TextDisplay },
    'password-field': { form: TextInput, display: TextDisplay },
    'textarea-field': { form: TextareaInput, display: TextareaDisplay },
    'number-field': { form: NumberInput, display: TextDisplay },
    'boolean-field': { form: BooleanInput, display: BooleanDisplay },
    'select-field': { form: SelectInput, display: TextDisplay },
    'date-field': { form: DateInput, display: TextDisplay },
    'datetime-field': { form: DateInput, display: TextDisplay },
    'belongs-to-field': { form: BelongsToInput, display: BelongsToDisplay },
    'file-field': { form: FileInput, display: FileDisplay },
    'image-field': { form: FileInput, display: ImageDisplay },
    'has-many-field': { panel: RelatedPanel },
    'belongs-to-many-field': { panel: RelatedPanel },
}

export const BUILTIN_FILTERS = {
    'select-filter': SelectFilter,
    'boolean-filter': BooleanFilter,
    'date-range-filter': DateRangeFilter,
}

export const BUILTIN_CARDS = {
    'value-metric': ValueMetric,
    'trend-metric': TrendMetric,
    'partition-metric': PartitionMetric,
    'progress-metric': ProgressMetric,
}

export function registerBuiltins(target = registry) {
    for (const [key, parts] of Object.entries(BUILTIN_FIELDS)) target.register('fields', key, parts, { builtin: true })
    for (const [key, component] of Object.entries(BUILTIN_FILTERS)) target.register('filters', key, component, { builtin: true })
    for (const [key, component] of Object.entries(BUILTIN_CARDS)) target.register('cards', key, component, { builtin: true })
}

let registered = false

// Registered lazily on first use, not at import: several built-in components import the field wrappers
// themselves (RelatedPanel, EntityTable), and an import cycle must not see them uninitialized.
export function ensureBuiltins() {
    if (registered) return

    registered = true
    registerBuiltins()
}

export { TextDisplay, TextInput, SelectFilter }
