import { isFieldUnavailable } from '../../fields'
import { buildSubmission, fieldError, fieldErrors, fieldErrorsByKey } from '../../fields/files'

// Request body of an action run (ActionModal). Same fail-closed rule as EntityForm (ADR-032): fields whose
// `vendor/name` component is missing or crashed ("Unavailable") are left out. Fields with file uploads (File /
// Image or `multipart: true`) switch the request to multipart; actions are always POST, so nothing is spoofed.
// Forms without either get the 1.4 data object unchanged.
export function actionPayload(fields, data) {
    const exclude = (fields ?? []).filter(isFieldUnavailable).map((field) => field.attribute)
    const { data: payload, multipart } = buildSubmission(fields ?? [], 'post', data, { exclude })

    return { data: payload, multipart, exclude }
}

// The message shown under a field of the modal: the field's own error, else the first keyed one (`attribute.*`,
// e.g. an extension field holding a list).
export function fieldMessage(errors, attribute) {
    return fieldError(errors, attribute) ?? fieldErrors(errors, attribute)[0] ?? null
}

/**
 * Form contract (design 1.5 §5.4) for an extension or override component in the action modal (design 1.6 §8.3):
 * the field keeps context "create" (fields written for 1.5 check CREATE), resourceId is the key of the one record
 * the action runs on (else null) and `action` is { uriKey, entity }.
 */
export function fieldContract(errors, field, { describedBy = null, resourceId = null, action = null } = {}) {
    return {
        errors: fieldErrors(errors, field.attribute),
        errorsByKey: fieldErrorsByKey(errors, field.attribute),
        describedBy,
        context: 'create',
        resourceId,
        action,
    }
}
