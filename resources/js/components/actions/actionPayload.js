import { isFieldUnavailable } from '../../fields'
import { buildSubmission } from '../../fields/files'

// Request body of an action run (ActionModal). Same fail-closed rule as EntityForm (ADR-032): fields whose
// `vendor/name` component is missing or crashed ("Unavailable") are left out. Fields with file uploads (File /
// Image or `multipart: true`) switch the request to multipart; actions are always POST, so nothing is spoofed.
// Forms without either get the 1.4 data object unchanged.
export function actionPayload(fields, data) {
    const exclude = (fields ?? []).filter(isFieldUnavailable).map((field) => field.attribute)
    const { data: payload, multipart } = buildSubmission(fields ?? [], 'post', data, { exclude })

    return { data: payload, multipart, exclude }
}
