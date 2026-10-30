import TextDisplay from './display/TextDisplay.vue'
import TextareaDisplay from './display/TextareaDisplay.vue'
import BooleanDisplay from './display/BooleanDisplay.vue'
import TextInput from './form/TextInput.vue'
import TextareaInput from './form/TextareaInput.vue'
import NumberInput from './form/NumberInput.vue'
import BooleanInput from './form/BooleanInput.vue'
import SelectInput from './form/SelectInput.vue'
import DateInput from './form/DateInput.vue'
import BelongsToDisplay from './display/BelongsToDisplay.vue'
import BelongsToInput from './form/BelongsToInput.vue'
import RelatedPanel from '../components/RelatedPanel.vue'

// Maps a field's server-side `component` key to the Vue components that render it.
// Fields without a display component fall back to plain text.
const displayComponents = {
    'textarea-field': TextareaDisplay,
    'boolean-field': BooleanDisplay,
    'belongs-to-field': BelongsToDisplay,
}

const formComponents = {
    'text-field': TextInput,
    'password-field': TextInput,
    'textarea-field': TextareaInput,
    'number-field': NumberInput,
    'boolean-field': BooleanInput,
    'select-field': SelectInput,
    'date-field': DateInput,
    'datetime-field': DateInput,
    'belongs-to-field': BelongsToInput,
}

// Detail-page panels (fields with `panel: true`), rendered under the field list.
const panelComponents = {
    'has-many-field': RelatedPanel,
    'belongs-to-many-field': RelatedPanel,
}

export function displayComponent(field) {
    return displayComponents[field.component] ?? TextDisplay
}

export function formComponent(field) {
    return formComponents[field.component] ?? TextInput
}

export function panelComponent(field) {
    return panelComponents[field.component] ?? null
}

export function registerField(component, { display, form, panel } = {}) {
    if (display) displayComponents[component] = display
    if (form) formComponents[component] = form
    if (panel) panelComponents[component] = panel
}
