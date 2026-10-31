import { isRef, onMounted, ref, toValue, watch } from 'vue'
import { useJsonResource } from './useJsonResource'
import { withParams } from './useLarsadmin'

// Data of a DataCard (design 1.5 §4.4, §5.5, ADR-033): GET card.endpoint (+ params as query) -> { data }.
// `params` may be a ref, a getter or a plain object; a ref / getter is watched and the data reloaded on change.
// Returns { status, data, reload }: status = idle | loading | error | forbidden (useJsonResource); `data` keeps
// the last good value while a reload is in flight and becomes null after a failure.
export function useCardData(card, params = {}) {
    const { status, load } = useJsonResource()
    const data = ref(null)

    const endpoint = () => toValue(card)?.endpoint ?? null
    const query = () => {
        const value = toValue(params)

        return value && typeof value === 'object' ? value : {}
    }

    async function reload({ background = false } = {}) {
        const url = endpoint()

        if (!url) {
            status.value = 'error'
            data.value = null

            return
        }

        const body = await load(withParams(url, query()), { background })

        if (body === undefined) return

        data.value = body === null ? null : (body?.data ?? null)
    }

    onMounted(() => reload())

    if (isRef(params) || typeof params === 'function') {
        watch(() => JSON.stringify(query()), () => reload())
    }

    return { status, data, reload }
}
