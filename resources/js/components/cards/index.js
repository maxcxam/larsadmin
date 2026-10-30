import PartitionMetric from './PartitionMetric.vue'
import ProgressMetric from './ProgressMetric.vue'
import TrendMetric from './TrendMetric.vue'
import ValueMetric from './ValueMetric.vue'

// Card registry: `card.component` (server) -> Vue component. Host apps may add their own via registerCard().
const registry = new Map([
    ['value-metric', ValueMetric],
    ['trend-metric', TrendMetric],
    ['partition-metric', PartitionMetric],
    ['progress-metric', ProgressMetric],
])

export function registerCard(component, vueComponent) {
    registry.set(component, vueComponent)
}

export function cardComponent(card) {
    return registry.get(card?.component) ?? null
}
