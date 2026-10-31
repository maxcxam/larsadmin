import { h } from 'vue'

export default {
    props: ['card'],
    setup(props) {
        return () => h('div', props.card.name)
    },
}
