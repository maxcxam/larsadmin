// A hand-written extension module (no build): render functions only, the runtime Vue has no compiler.
import { h } from 'vue'
import { defineExtension } from 'larsadmin'

const RatingInput = {
    props: ['field', 'modelValue'],
    emits: ['update:modelValue'],
    setup(props, { emit }) {
        return () => h('input', {
            id: props.field.attribute,
            type: 'number',
            min: 0,
            max: 5,
            value: props.modelValue,
            onInput: (event) => emit('update:modelValue', Number(event.target.value)),
        })
    },
}

const RatingDisplay = {
    props: ['field'],
    setup(props) {
        return () => h('span', '★'.repeat(Number(props.field.value) || 0))
    },
}

export default defineExtension({
    name: 'acme/demo',
    requires: '^1.5',
    fields: {
        'acme/rating': { form: RatingInput, display: RatingDisplay },
    },
    cards: {
        'acme/top-rated': () => import('./chunks/lazy.js'),
    },
})
