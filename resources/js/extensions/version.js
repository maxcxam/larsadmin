// Extension API version (design 1.5 §9, ADR-034). The value is injected at build time from
// Larsadmin::API_VERSION in src/Larsadmin.php (vite.config.js), so PHP and JS share one source.
/* global __LARSADMIN_API_VERSION__ */
// No fallback (CRX-16): every build (panel, kit, vitest) defines it; a build that does not must fail loudly.
export const API_VERSION = __LARSADMIN_API_VERSION__

const VERSION = /^(\d+)\.(\d+)$/
const RANGE = /^\^(\d+)\.(\d+)$/

/**
 * Whether `requires` (only `^MAJOR.MINOR` is supported) accepts `version` (`MAJOR.MINOR`): the majors match and
 * the version's minor is greater or equal. Anything else (other range syntax, garbage) is not satisfied.
 */
export function satisfies(requires, version = API_VERSION) {
    const range = RANGE.exec(String(requires ?? '').trim())
    const current = VERSION.exec(String(version ?? '').trim())

    if (!range || !current) return false

    return Number(range[1]) === Number(current[1]) && Number(current[2]) >= Number(range[2])
}
