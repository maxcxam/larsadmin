#!/usr/bin/env node
// "Public API only" check for larsadmin extensions (design 1.5 §13.4 item 3, §18.3 bolt X6; ADR-034, ADR-035).
//
// Two parts, both against the snapshot kit/public-api.json (paths below are relative to the package):
//
//  1. Surface (in a checkout of the package; its CI runs it as `npm run check:api`): the panel still provides
//     everything in the snapshot, and nothing unacknowledged was added.
//     - `larsadmin` SDK (§5.5): the exports of resources/js/sdk/index.js must equal the snapshot, and the built
//       dist/runtime/larsadmin.js and kit/sdk.js must export exactly the same names (else the build is stale).
//     - dist/runtime/{vue,inertia,larsadmin}.js exist (import map specifiers, §5.1).
//     - kit (§6.4, §18.1 A6g): every snapshotted export of kit/testing.js and kit/vite.js still exists.
//     - PHP (§4, §18.2): every snapshotted class and stable member still exists in src/; and on classes marked
//       `@api stable` every public member (protected too, unless the class is final) is either snapshotted or
//       marked `@internal` in its docblock (review CRX-03).
//     - Override contract (§5.6, ADR-032, Q-70): the props / events (defineProps, defineEmits, defineModel) of
//       the built-in components behind the overridable keys (registry.js OVERRIDABLE, builtins.js) equal the
//       snapshot, per key and part.
//     A removal is a SemVer break (frozen for 1.x, ADR-034) and fails. An addition fails until it is
//     acknowledged with --update (and listed in the design / CHANGELOG).
//     In an installed copy (vendor/maxcxam/larsadmin, no resources/js) this part is skipped: the snapshot that
//     ships with the kit is the contract of the installed version.
//
//  2. Extensions (the directories given as arguments):
//     - JS/Vue/CSS may import `vue`, `@inertiajs/vue3`, third-party packages, their own files, the names of the
//       `larsadmin` SDK in the snapshot, and the snapshotted kit files / exports. Anything from the panel's
//       sources (resources/js, vendor/maxcxam/larsadmin outside kit/), a `larsadmin/...` subpath or a file
//       outside the extension fails.
//     - PHP may reference only snapshotted Maxcxam\Larsadmin classes, must not call the internal members in
//       INTERNAL_PHP_MEMBERS (ADR-035: Entity::fieldPrototypes() / isShownOn() / isReadonlyFor() stay internal),
//       and a class extending Field, Filter, Card / DataCard or Tool (directly or through its own classes) may
//       override only the stable hooks of that base (review CRX-04).
//     - Exemption: the extension's top-level tests/ directory is not scanned. Tests may reference internal
//       classes (e.g. parity tests against a copied internal helper); src/, resources/ and the rest stay strict.
//
// Usage:
//   npm run check:api                                   (package checkout) check the surface
//   npm run check:api -- --update                       (package checkout) re-snapshot into kit/public-api.json
//   node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .            (extension / host app) check an extension
//   node packages/larsadmin/kit/check-public-api.mjs packages/larsadmin-medialibrary //        sandbox/extensions/larsadmin/demo-extension    (monorepo root) surface + those extensions
//   Extension paths are relative to the current directory.
//
// No dependencies: Node >= 20 built-ins only.

import { existsSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

const PKG = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const BASELINE = join(PKG, 'kit', 'public-api.json')
const HAS_SOURCES = existsSync(join(PKG, 'resources/js/sdk/index.js')) && existsSync(join(PKG, 'src'))
const PHP_NS = 'Maxcxam\\Larsadmin\\'

// The stable PHP surface an extension may use, mirroring design §4 / §18.2 and the documented 1.x API
// (docs/guide). `[]` = the class may be referenced; listed members are the stable ones whose removal fails
// the check (`$name` = property). --update snapshots the classes / members that exist in src/ today; the rest
// are reported as pending until they are implemented.
const PHP_API = {
    'Larsadmin': ['API_VERSION', 'extension', 'registeredExtensions', 'extensionFor', 'routes', 'findRecord', 'tools', 'registeredTools', 'toolForKey', 'audited', 'findField', 'assetsVersion'],
    'LarsadminServiceProvider': [],
    'LarsadminApplicationServiceProvider': [],
    'Entity': [],
    'Extensions\\Extension': ['$name', '$path', 'script', 'style', 'translations', 'version', 'url'],
    'Tools\\Tool': ['$component', 'ICONS', 'make', 'name', 'uriKey', 'icon', 'canSee', 'authorizedToSee', 'props'],
    'Testing\\InteractsWithLarsadmin': ['serializeField', 'fillField', 'cardData', 'assertExtensionRegistered', 'extensionAssetResponse'],
    'Fields\\Field': [
        '$component', '$multipart', 'jsonSerialize', 'withMeta', 'multipart', 'resolveValue', 'formatForDisplay', 'fillValue', 'fillString',
        'typeRules', 'implicitRules', 'acceptsNullInput', 'defaultAlign', 'fill', 'fillAttribute', 'resolve', 'eagerLoads', 'applySort',
        'bindTo', 'hasAuditValue', 'auditValue', 'fillInto',
        // Override hooks made stable after the 1.5 review (CRX-04).
        '__construct', 'resolveDefault', 'rulesFor', 'validationAttributeNames', 'isRequired', 'sortable',
        // 1.6 (ADR-041): per-field abilities and action context; actionValue() is an override hook.
        'authorizedTo', 'inAction', 'actionValue',
    ],
    'Fields\\PanelField': [],
    'Fields\\BelongsTo': [], 'Fields\\BelongsToMany': [], 'Fields\\Boolean': [], 'Fields\\Date': [], 'Fields\\DateTime': [],
    'Fields\\File': [], 'Fields\\HasMany': [], 'Fields\\ID': [], 'Fields\\Image': [], 'Fields\\Number': [], 'Fields\\Password': [],
    'Fields\\Select': [], 'Fields\\Text': [], 'Fields\\Textarea': [],
    'Files\\FileJournal': ['created', 'obsolete'],
    // 1.6 (ADR-041): the upload safety contract for extensions.
    'Files\\FileSafety': ['rule', 'blockedExtension', 'storedExtension', 'sanitizeName', 'asciiName', 'isRasterImage', 'response'],
    'Metrics\\Card': ['$component', '$width', '$meta', 'name', 'uriKey', 'width', 'help', 'canSee', 'jsonSerialize', 'withMeta'],
    'Metrics\\DataCard': ['rules', 'data', 'cacheFor', 'cacheKeyParts', 'entityQuery'],
    'Metrics\\CardRequest': ['$request', '$parameters', '$timezone', '$now', '$owner', 'user', 'get'],
    'Metrics\\Metric': [], 'Metrics\\MetricRequest': [], 'Metrics\\EntitySource': [],
    'Metrics\\Value': [], 'Metrics\\Trend': [], 'Metrics\\Partition': [], 'Metrics\\Progress': [],
    'Metrics\\Results\\ValueResult': [], 'Metrics\\Results\\TrendResult': [], 'Metrics\\Results\\PartitionResult': [], 'Metrics\\Results\\ProgressResult': [],
    // Documented filter API (guide 06-filters); serialize() is the stable hook for extra props.
    'Filters\\Filter': ['$component', 'make', 'apply', 'name', 'key', 'options', 'default', 'normalize', 'canSee', 'serialize'],
    'Filters\\BooleanFilter': [], 'Filters\\SelectFilter': [], 'Filters\\DateRangeFilter': [],
    'Actions\\Action': [], 'Actions\\ActionFields': [], 'Actions\\ActionResponse': [], 'Actions\\Queued\\ActionRun': [],
    'Dashboards\\Dashboard': [], 'Dashboards\\Main': [],
    'Layout\\Panel': [], 'Layout\\Tabs': [],
    'Events\\Attached': [], 'Events\\Detached': [], 'Events\\AuditRecorded': [], 'Events\\ActionRunFinished': [],
    'Audit\\AuditEntry': [],
    'Notifications\\LarsadminNotification': [],
}

// Members of allowed classes that are public in PHP but not part of the API (ADR-035, @internal markers).
const INTERNAL_PHP_MEMBERS = [
    'fieldPrototypes', 'isShownOn', 'isReadonlyFor', 'extensionRoutes', 'registerExtensionRoutes', 'cacheScopeAccepts',
    'cardCacheScope', 'auditActorLabel', 'withUrls', 'navigationIcon', 'forRun',
    // 1.6 (ADR-041): ability snapshot / action binding, called by the panel only.
    'withAbilities', 'forAction', 'fieldAbilities',
]

// Kit files and the exports authors may use (design §6.4, §18.1 A6g). Other exports of kit/vite.js exist for
// the panel's own tests and are not a contract.
const KIT_API = {
    'testing.js': ['withPanel', 'mountField', 'mountDisplay', 'mountCard'],
    'vite.js': ['default', 'larsadmin'],
    'theme.css': [],
    'sdk.js': null, // same names as the `larsadmin` SDK (it is the vitest alias target)
}

const RUNTIME_ENTRIES = ['vue.js', 'inertia.js', 'larsadmin.js']

// ---------------------------------------------------------------------------------------------------------------

const args = process.argv.slice(2)
const update = args.includes('--update')
const targets = args.filter((arg) => !arg.startsWith('--'))

const errors = []
const notes = []
const rel = (path) => {
    const fromPkg = relative(PKG, path)
    return (fromPkg.startsWith('..') ? relative(process.cwd(), path) : fromPkg).split(sep).join('/')
}
const fail = (where, message) => errors.push(`${where}: ${message}`)
const read = (path) => readFileSync(path, 'utf8')
const lineOf = (text, index) => text.slice(0, index).split('\n').length
const sorted = (list) => [...new Set(list)].sort()

// --- Export parsing ---------------------------------------------------------------------------------------------

function exportList(body) {
    return body
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\/\/.*$/gm, '')
        .split(',')
        .map((part) => part.trim())
        .filter(Boolean)
        .map((part) => part.split(/\s+as\s+/).pop().trim())
}

// Exports of a hand-written ESM module (statements start a line).
function sourceExports(text, where) {
    const names = []

    for (const m of text.matchAll(/^export\s+(?:async\s+)?(?:const|let|var|function\*?|class)\s+([A-Za-z_$][\w$]*)/gm)) names.push(m[1])
    for (const m of text.matchAll(/^export\s*\{([^}]*)\}/gm)) names.push(...exportList(m[1]))
    if (/^export\s+default\b/m.test(text)) names.push('default')
    if (/^export\s*\*/m.test(text)) fail(where, '`export *` hides the public surface; export names explicitly')

    return sorted(names)
}

// Exports of a built bundle: the final `export { a as b, ... }` statement.
function bundleExports(text) {
    const m = text.match(/export\s*\{([^}]*)\}\s*;?\s*(?:\/\/#\s*sourceMappingURL=\S+\s*)?$/)

    return m ? sorted(exportList(m[1])) : []
}

// --- PHP parsing ------------------------------------------------------------------------------------------------

// Comments replaced by spaces (newlines kept, so offsets and line numbers stay valid); strings kept.
function stripPhpComments(text) {
    let out = ''
    let i = 0

    while (i < text.length) {
        const c = text[i]
        const next = text[i + 1]

        if (c === "'" || c === '"') {
            let j = i + 1
            while (j < text.length && text[j] !== c) j += text[j] === '\\' ? 2 : 1
            out += text.slice(i, j + 1)
            i = j + 1
        } else if (c === '/' && next === '*') {
            const end = text.indexOf('*/', i + 2)
            const stop = end < 0 ? text.length : end + 2
            out += text.slice(i, stop).replace(/[^\n]/g, ' ')
            i = stop
        } else if ((c === '/' && next === '/') || (c === '#' && next !== '[')) {
            let j = i
            while (j < text.length && text[j] !== '\n') j++
            out += ' '.repeat(j - i)
            i = j
        } else {
            out += c
            i++
        }
    }

    return out
}

const memberPattern = (member) =>
    member.startsWith('$')
        ? new RegExp(`(?:public|protected)\\s+(?:readonly\\s+)?(?:static\\s+)?(?:[\\w\\\\?|]+\\s+)?\\${member}\\b`)
        : new RegExp(`(?:function\\s+&?|const\\s+(?:[\\w\\\\?|]+\\s+)?)${member}\\b`)

// The docblock right before `index` (attributes in between are skipped), or ''.
function docblockBefore(text, index) {
    let before = text.slice(0, index).trimEnd()
    while (/#\[[^\n]*\]$/.test(before)) before = before.replace(/#\[[^\n]*\]$/, '').trimEnd()

    return before.endsWith('*/') ? before.slice(before.lastIndexOf('/**')) : ''
}

const DECLARATION = /^[ \t]*((?:(?:final|abstract|public|protected|private|static|readonly|var)\s+)+)(?:function\s+&?([A-Za-z_]\w*)|const\s+(?:[\w\\?|]+\s+)?([A-Za-z_]\w*)\s*=|(?:[\w\\?|()]+\s+)?\$([A-Za-z_]\w*))/gm

// A PHP file's first class / trait: name, FQCN, parent FQCN, final, `@api stable`, and its declared members
// (methods by name, properties as `$name`, constants; promoted constructor properties included).
function phpClass(text) {
    const code = stripPhpComments(text)
    const namespace = (code.match(/^\s*namespace\s+([\w\\]+)\s*;/m) ?? [])[1] ?? ''
    const uses = {}
    for (const m of code.matchAll(/^\s*use\s+\\?([\w\\]+)(?:\s+as\s+(\w+))?\s*;/gm)) uses[(m[2] ?? m[1].split('\\').pop()).toLowerCase()] = m[1]
    const fqcn = (name) => {
        if (name.startsWith('\\')) return name.slice(1)
        const [first, ...rest] = name.split('\\')
        const used = uses[first.toLowerCase()]
        return used ? [used, ...rest].join('\\') : (namespace ? `${namespace}\\${name}` : name)
    }
    const head = code.match(/^[ \t]*((?:(?:final|abstract|readonly)\s+)*)(class|trait)\s+(\w+)(?:\s+extends\s+([\w\\]+))?/m)
    if (!head) return null

    const members = []
    for (const m of text.matchAll(DECLARATION)) {
        if (code.slice(m.index, m.index + m[0].length) !== m[0]) continue // inside a comment or string
        const modifiers = m[1]
        members.push({
            name: m[2] ?? m[3] ?? `$${m[4]}`,
            method: m[2] !== undefined,
            visibility: /\bprivate\b/.test(modifiers) ? 'private' : /\bprotected\b/.test(modifiers) ? 'protected' : 'public',
            internal: /@internal\b/.test(docblockBefore(text, m.index)),
            index: m.index,
        })
    }

    return {
        name: head[3],
        fqcn: namespace ? `${namespace}\\${head[3]}` : head[3],
        parent: head[4] ? fqcn(head[4]) : null,
        final: /\bfinal\b/.test(head[1] ?? ''),
        stable: /@api\s+stable\b/.test(docblockBefore(text, head.index + head[0].length - head[0].trimStart().length)),
        members,
    }
}

const larsadminClasses = new Map()

// A larsadmin class parsed from src/ (memoized), or null.
function larsadminClass(fqcn) {
    if (!larsadminClasses.has(fqcn)) {
        const file = join(PKG, 'src', ...fqcn.slice(PHP_NS.length).split('\\')) + '.php'
        larsadminClasses.set(fqcn, fqcn.startsWith(PHP_NS) && existsSync(file) ? phpClass(read(file)) : null)
    }

    return larsadminClasses.get(fqcn)
}

// CRX-03: public (and, on non-final classes, protected) members of `@api stable` classes that are neither
// snapshotted nor @internal.
function unlistedStableMembers(baseline) {
    const found = []
    for (const cls of Object.keys(baseline.php)) {
        const info = larsadminClass(cls)
        if (!info?.stable) continue
        for (const member of info.members) {
            if (member.internal || member.visibility === 'private' || (member.visibility === 'protected' && info.final)) continue
            if (!baseline.php[cls].includes(member.name)) found.push(`${cls}::${member.name}`)
        }
    }

    return sorted(found)
}

// Extendable bases whose overridable methods are limited to their stable members (CRX-04).
const OVERRIDE_ROOTS = ['Fields\\Field', 'Filters\\Filter', 'Metrics\\Card', 'Tools\\Tool'].map((c) => PHP_NS + c)

function phpSurface(pkg) {
    const surface = {}
    const pending = []

    for (const [cls, members] of Object.entries(PHP_API)) {
        const file = join(pkg, 'src', ...cls.split('\\')) + '.php'
        if (!existsSync(file)) {
            pending.push(PHP_NS + cls)
            continue
        }
        const code = stripPhpComments(read(file))
        surface[PHP_NS + cls] = members.filter((member) => {
            const found = memberPattern(member).test(code)
            if (!found) pending.push(`${PHP_NS}${cls}::${member}`)
            return found
        })
    }

    return { surface, pending }
}

// --- Override contract (§5.6) ------------------------------------------------------------------------------------

// The text of the balanced (...) / {...} / [...] / <...> group that starts at `start`.
function balanced(text, start) {
    const open = text[start]
    const close = { '(': ')', '{': '}', '[': ']', '<': '>' }[open]
    let depth = 0

    for (let i = start; i < text.length; i++) {
        const c = text[i]
        if (c === "'" || c === '"' || c === '`') {
            let j = i + 1
            while (j < text.length && text[j] !== c) j += text[j] === '\\' ? 2 : 1
            i = j
        } else if (c === open) depth++
        else if (c === close && --depth === 0) return text.slice(start, i + 1)
    }

    return text.slice(start)
}

// Top-level keys of an object literal / type literal `{ a: ..., 'b': ..., c }`.
function topLevelKeys(object) {
    const keys = []
    let depth = 0
    let expectKey = true

    for (let i = 0; i < object.length; i++) {
        const c = object[i]
        if (c === "'" || c === '"' || c === '`') {
            let j = i + 1
            while (j < object.length && object[j] !== c) j += object[j] === '\\' ? 2 : 1
            if (depth === 1 && expectKey) {
                keys.push(object.slice(i + 1, j))
                expectKey = false
            }
            i = j
        } else if ('{[(<'.includes(c)) depth++
        else if ('}])>'.includes(c) && !(c === '>' && object[i - 1] === '=')) depth--
        else if (depth === 1 && (c === ',' || c === ';')) expectKey = true
        else if (depth === 1 && expectKey && /[A-Za-z_$]/.test(c)) {
            const m = object.slice(i).match(/^[A-Za-z_$][\w$]*/)
            keys.push(m[0])
            i += m[0].length - 1
            expectKey = false
        }
    }

    return keys
}

const stringsIn = (text) => [...text.matchAll(/(['"`])([^'"`]+)\1/g)].map((m) => m[2])

// Props and events a single-file component declares (defineProps / defineEmits / defineModel, or the Options
// API `props` / `emits`).
function componentContract(file) {
    const source = read(file)
    const script = [...source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]).join('\n')
    const code = stripJsComments(script)
    const props = []
    const emits = []

    const declared = (macro, optionsKey) => {
        const m = code.match(new RegExp(`\\b${macro}\\s*(<|\\()`)) ?? code.match(new RegExp(`\\b${optionsKey}\\s*:\\s*([\\[{])`))
        if (!m) return null
        let group = balanced(code, m.index + m[0].length - 1)
        if (group.startsWith('(')) group = group.slice(1, -1).trim()
        return group
    }

    const propsDecl = declared('defineProps', 'props')
    if (propsDecl) props.push(...(propsDecl.startsWith('[') ? stringsIn(propsDecl) : topLevelKeys(propsDecl.startsWith('<') ? propsDecl.slice(1, -1).trim() : propsDecl)))

    const emitsDecl = declared('defineEmits', 'emits')
    if (emitsDecl) {
        if (emitsDecl.startsWith('[')) emits.push(...stringsIn(emitsDecl))
        else if (emitsDecl.startsWith('<')) emits.push(...(/\(\s*e\s*:/.test(emitsDecl) ? stringsIn(emitsDecl) : topLevelKeys(emitsDecl.slice(1, -1).trim())))
        else emits.push(...topLevelKeys(emitsDecl))
    }

    for (const m of code.matchAll(/\bdefineModel\s*(?:<[^>]*>)?\s*\(\s*(?:(['"])([^'"]+)\1)?/g)) {
        const name = m[2] ?? 'modelValue'
        props.push(name)
        emits.push(`update:${name}`)
    }

    return { props: sorted(props), emits: sorted(emits) }
}

function overrideSurface(pkg) {
    const registryFile = join(pkg, 'resources/js/extensions/registry.js')
    const builtinsFile = join(pkg, 'resources/js/extensions/builtins.js')
    const contract = {}

    if (!existsSync(registryFile) || !existsSync(builtinsFile)) {
        fail(rel(builtinsFile), 'registry.js / builtins.js not found: cannot read the override contract (§5.6)')
        return contract
    }

    const registry = read(registryFile)
    const builtins = stripJsComments(read(builtinsFile))
    const overridableAt = registry.search(/OVERRIDABLE\s*=\s*Object\.freeze\(\s*\{/)
    const parts = stringsIn((registry.match(/OVERRIDE_FIELD_PARTS\s*=\s*(\[[^\]]*\])/) ?? [, '[]'])[1])
    if (overridableAt < 0) {
        fail(rel(registryFile), 'OVERRIDABLE not found: cannot read the override contract (§5.6)')
        return contract
    }
    const overridable = balanced(registry, registry.indexOf('{', overridableAt))
    const imports = Object.fromEntries([...builtins.matchAll(/import\s+(\w+)\s+from\s+(['"])([^'"]+\.vue)\2/g)].map((m) => [m[1], resolve(dirname(builtinsFile), m[3])]))
    const block = (name) => {
        const at = builtins.search(new RegExp(`\\b${name}\\s*=\\s*\\{`))
        return at < 0 ? '' : balanced(builtins, builtins.indexOf('{', at))
    }
    const maps = { fields: block('BUILTIN_FIELDS'), filters: block('BUILTIN_FILTERS'), cards: block('BUILTIN_CARDS') }

    for (const kind of ['fields', 'filters', 'cards']) {
        const keys = stringsIn((overridable.match(new RegExp(`\\b${kind}\\s*:\\s*(\\[[^\\]]*\\])`)) ?? [, '[]'])[1])

        for (const key of keys) {
            const entry = maps[kind].match(new RegExp(`(['"])${key}\\1\\s*:\\s*(\\{[^}]*\\}|\\w+)`))
            if (!entry) {
                fail(rel(builtinsFile), `overridable ${kind} key "${key}" has no built-in component`)
                continue
            }
            const components = entry[2].startsWith('{')
                ? [...entry[2].matchAll(/(\w+)\s*:\s*(\w+)/g)].filter((m) => parts.includes(m[1])).map((m) => [m[1], m[2]])
                : [['component', entry[2]]]

            for (const [part, name] of components) {
                const file = imports[name]
                if (!file || !existsSync(file)) {
                    fail(rel(builtinsFile), `cannot find the component ${name} of ${kind} "${key}"`)
                    continue
                }
                contract[`${kind}/${key}/${part}`] = componentContract(file)
            }
        }
    }

    return contract
}

// --- Field form contract (design 1.5 §5.4, 1.6 §8.3) -------------------------------------------------------------

// Where an extension field's form component is mounted, and the functions whose returned object is v-bound onto it
// (the props only extension / override components receive). Each host contributes the props bound on its
// `<component :is="formComponent(field)">` plus the keys those functions return.
const FIELD_HOSTS = {
    'form/entity': { file: 'resources/js/components/EntityForm.vue', contract: [['resources/js/components/EntityForm.vue', 'contractProps']] },
    'form/action': { file: 'resources/js/components/actions/ActionModal.vue', contract: [['resources/js/components/actions/actionPayload.js', 'fieldContract']] },
}

const camel = (name) => name.replace(/-(\w)/g, (_, c) => c.toUpperCase())

// Keys of the object literal the named function returns (its last `return {...}`).
function returnedKeys(pkg, file, name) {
    const path = join(pkg, file)
    const code = existsSync(path) ? stripJsComments(read(path)) : ''
    const at = code.search(new RegExp(`\\bfunction\\s+${name}\\s*\\(|\\b(?:const|let)\\s+${name}\\s*=`))
    if (at < 0) {
        fail(file, `${name}() not found: cannot read the field form contract (design 1.5 §5.4)`)
        return []
    }
    const body = balanced(code, code.indexOf('{', code.indexOf(')', at)))
    const returns = [...body.matchAll(/\breturn\s*\{/g)]
    if (!returns.length) {
        fail(file, `${name}() returns no object literal: cannot read the field form contract (design 1.5 §5.4)`)
        return []
    }
    const last = returns[returns.length - 1]
    return topLevelKeys(balanced(body, last.index + last[0].length - 1))
}

// Props bound on the host's `<component :is="formComponent(field)" ...>` (v-model → modelValue, kebab → camel).
function hostBindings(pkg, file) {
    const path = join(pkg, file)
    const source = existsSync(path) ? read(path) : ''
    const tag = source.match(/<component\b(?:[^>"']|"[^"]*"|'[^']*')*?:is="formComponent\(field\)"(?:[^>"']|"[^"]*"|'[^']*')*\/?>/)
    if (!tag) {
        fail(file, '<component :is="formComponent(field)"> not found: cannot read the field form contract (design 1.5 §5.4)')
        return []
    }
    const props = []
    for (const m of tag[0].matchAll(/\s(v-model(?::([\w-]+))?|v-bind:([\w-]+)|:([\w-]+)|([A-Za-z][\w-]*))=/g)) {
        if (m[1].startsWith('v-model')) props.push(camel(m[2] ?? 'modelValue'))
        else if (m[3] ?? m[4]) props.push(camel(m[3] ?? m[4]))
        else if (!m[5].startsWith('v-')) props.push(camel(m[5]))
    }
    return props.filter((p) => !['is', 'key', 'class', 'style', 'ref'].includes(p))
}

// Keys of the serialized field (Field::jsonSerialize(), incl. the conditional "multipart" / "inAction").
function serializedFieldKeys(pkg) {
    const path = join(pkg, 'src/Fields/Field.php')
    const code = existsSync(path) ? read(path) : ''
    const at = code.search(/public\s+function\s+jsonSerialize\s*\(/)
    if (at < 0) {
        fail('src/Fields/Field.php', 'jsonSerialize() not found: cannot read the serialized field contract')
        return []
    }
    const body = balanced(code, code.indexOf('{', at))
    return [...body.matchAll(/'(\w+)'\s*=>/g)].map((m) => m[1])
}

function fieldContractSurface(pkg) {
    const contract = {}
    for (const [host, { file, contract: sources }] of Object.entries(FIELD_HOSTS)) {
        contract[host] = sorted([...hostBindings(pkg, file), ...sources.flatMap(([source, name]) => returnedKeys(pkg, source, name))])
    }
    contract.field = sorted(serializedFieldKeys(pkg))
    return contract
}

// --- Surface -----------------------------------------------------------------------------------------------------

function currentSurface(pkg) {
    const sdkSource = join(pkg, 'resources/js/sdk/index.js')
    const { surface: php, pending } = phpSurface(pkg)

    return {
        larsadmin: existsSync(sdkSource) ? sourceExports(read(sdkSource), rel(sdkSource)) : [],
        kit: Object.fromEntries(Object.entries(KIT_API).filter(([, names]) => names !== null)),
        php,
        overrides: overrideSurface(pkg),
        fieldContract: fieldContractSurface(pkg),
        pending,
    }
}

function compare(where, label, expected, actual) {
    const have = new Set(actual)
    const want = new Set(expected)

    for (const name of expected) if (!have.has(name)) fail(where, `${label} "${name}" was removed. It is public API, frozen for 1.x (ADR-034); restore it (deprecate instead of removing)`)
    for (const name of actual) if (!want.has(name)) fail(where, `${label} "${name}" is new and not in kit/public-api.json. If it is meant to be public API, list it in the design (§4 / §5.5) and run \`npm run check:api -- --update\`; otherwise do not export it`)
}

function checkSurface(pkg, baseline, current) {
    const sdkWhere = rel(join(pkg, 'resources/js/sdk/index.js'))
    compare(sdkWhere, 'SDK export', baseline.js.larsadmin, current.larsadmin)

    for (const [file, label] of [['dist/runtime/larsadmin.js', 'runtime export'], ['kit/sdk.js', 'kit SDK export']]) {
        const path = join(pkg, file)
        if (!existsSync(path)) {
            fail(rel(path), 'missing (run `npm run build` in the package)')
            continue
        }
        const built = bundleExports(read(path))
        const missing = baseline.js.larsadmin.filter((name) => !built.includes(name))
        const extra = built.filter((name) => !baseline.js.larsadmin.includes(name))
        if (missing.length) fail(rel(path), `${label}s missing: ${missing.join(', ')} (removed from the SDK, or the build is stale: run \`npm run build\`)`)
        if (extra.length) fail(rel(path), `${label}s not in kit/public-api.json: ${extra.join(', ')} (stale build or unacknowledged addition)`)
    }

    for (const entry of RUNTIME_ENTRIES) {
        const path = join(pkg, 'dist/runtime', entry)
        if (!existsSync(path)) fail(rel(path), 'runtime entry missing: the import map (§5.1) points at it')
        else if (!/\bexport\b/.test(read(path))) fail(rel(path), 'runtime entry exports nothing')
    }

    for (const [file, names] of Object.entries(baseline.js.kit)) {
        const path = join(pkg, 'kit', file)
        if (!existsSync(path)) {
            fail(rel(path), 'kit file was removed; extensions import it (design §6.4)')
            continue
        }
        if (!file.endsWith('.js')) continue
        const actual = sourceExports(read(path), rel(path))
        for (const name of names) if (!actual.includes(name)) fail(rel(path), `kit export "${name}" was removed. It is public API, frozen for 1.x (ADR-034)`)
    }

    for (const [cls, members] of Object.entries(baseline.php)) {
        const where = rel(join(pkg, 'src', ...cls.slice(PHP_NS.length).split('\\'))) + '.php'
        if (!(cls in current.php)) {
            fail(where, `public class ${cls} was removed or renamed. It is public API, frozen for 1.x (ADR-034)`)
            continue
        }
        for (const member of members) {
            if (!current.php[cls].includes(member)) fail(where, `stable member ${cls}::${member} was removed or renamed. It is frozen for 1.x (ADR-034)`)
        }
    }
    for (const [cls, members] of Object.entries(current.php)) {
        const known = baseline.php[cls]
        const added = known ? members.filter((member) => !known.includes(member)) : [cls]
        if (added.length) fail('kit/public-api.json', `stable PHP API implemented but not snapshotted: ${added.map((m) => (m === cls ? m : `${cls}::${m}`)).join(', ')}; run \`npm run check:api -- --update\``)
    }

    for (const member of unlistedStableMembers(baseline)) {
        fail('kit/public-api.json', `${member} is public (or protected on an extendable class) on an @api stable class but neither snapshotted nor marked @internal (review CRX-03). Mark it @internal, or make it stable (PHP_API in kit/check-public-api.mjs, design) and run \`npm run check:api -- --update\``)
    }

    // Override contract (§5.6): exact per key / part; removals break overrides, additions must be acknowledged.
    const overrides = baseline.overrides ?? {}
    for (const [target, { props, emits }] of Object.entries(overrides)) {
        const now = current.overrides[target]
        const where = `override contract ${target}`
        if (!now) {
            fail(where, 'the overridable built-in is gone. Its props / events are frozen for 1.x (design §5.6, ADR-034)')
            continue
        }
        for (const prop of props) if (!now.props.includes(prop)) fail(where, `prop "${prop}" was removed or renamed. Overrides receive exactly the built-in's props, frozen for 1.x (design §5.6, ADR-034)`)
        for (const event of emits) if (!now.emits.includes(event)) fail(where, `event "${event}" was removed or renamed. Overrides emit exactly the built-in's events, frozen for 1.x (design §5.6, ADR-034)`)
    }
    for (const [target, { props, emits }] of Object.entries(current.overrides)) {
        const known = overrides[target]
        const added = known
            ? [...props.filter((p) => !known.props.includes(p)).map((p) => `prop "${p}"`), ...emits.filter((e) => !known.emits.includes(e)).map((e) => `event "${e}"`)]
            : ['the whole key / part']
        if (added.length) fail(`override contract ${target}`, `${added.join(', ')} not in kit/public-api.json. It becomes part of the stable override contract: document it (design §5.6) and run \`npm run check:api -- --update\``)
    }

    // Field form contract (design 1.5 §5.4, 1.6 §8.3): what extension field components receive. Exact per host.
    const fieldContract = baseline.fieldContract ?? {}
    for (const [host, props] of Object.entries(fieldContract)) {
        const now = current.fieldContract[host] ?? []
        const where = host === 'field' ? 'field contract (Field::jsonSerialize())' : `field contract ${host} (${FIELD_HOSTS[host]?.file ?? 'host gone'})`
        for (const prop of props) if (!now.includes(prop)) fail(where, `${host === 'field' ? 'serialized field key' : 'prop'} "${prop}" was removed or renamed. Extension field components receive it, frozen for 1.x (design 1.5 §5.4, 1.6 §8.3, ADR-034)`)
    }
    for (const [host, props] of Object.entries(current.fieldContract)) {
        const added = props.filter((p) => !(fieldContract[host] ?? []).includes(p))
        if (added.length) fail(`field contract ${host}`, `${added.map((p) => `"${p}"`).join(', ')} not in kit/public-api.json. It becomes part of the stable field contract: document it (design 1.5 §5.4) and run \`npm run check:api -- --update\``)
    }
}

// --- Extensions --------------------------------------------------------------------------------------------------

const SKIP_DIRS = new Set(['node_modules', 'vendor', 'dist', '.git', 'build', 'coverage', 'storage'])

function* walk(dir) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (entry.isDirectory()) {
            if (!SKIP_DIRS.has(entry.name) && !entry.name.startsWith('.')) yield* walk(join(dir, entry.name))
        } else {
            yield join(dir, entry.name)
        }
    }
}

// Comments blanked out (offsets kept); a `//` only counts at a line start or after whitespace / `;`, so URLs in
// strings survive.
const stripJsComments = (text) =>
    text.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' ')).replace(/(^|[\s;])\/\/.*$/gm, (m, lead) => lead + ' '.repeat(m.length - lead.length))

function importedNames(clause) {
    if (!clause) return { names: [], namespace: false }
    const names = []
    let rest = clause.replace(/^type\s+/, '').trim()
    const braces = rest.match(/\{([^}]*)\}/)

    if (braces) {
        for (const part of braces[1].split(',').map((p) => p.trim()).filter(Boolean)) names.push(part.replace(/^type\s+/, '').split(/\s+as\s+/)[0].trim())
        rest = rest.replace(braces[0], '')
    }
    const namespace = /\*\s*as\s+\w+/.test(rest)
    rest = rest.replace(/\*\s*as\s+[\w$]+/, '')
    if (/[A-Za-z_$][\w$]*/.test(rest.replace(/,/g, '').trim())) names.unshift('default')

    return { names, namespace }
}

function* jsImports(text) {
    const code = stripJsComments(text)
    const patterns = [
        /\bimport\s+(?!\()([\w$*{}\s,]+?)\s+from\s*(['"])([^'"]+)\2/g, // import x, { a } from '...'
        /\bimport\s*(['"])([^'"]+)\1/g, // import '...'
        /\bexport\s+([\w$*{}\s,]+?)\s+from\s*(['"])([^'"]+)\2/g, // export { a } from '...'
        /\bimport\s*\(\s*(['"`])([^'"`]+)\1\s*\)/g, // import('...')
        /@import\s+(?:url\(\s*)?(['"])([^'"]+)\1/g, // CSS @import
    ]

    for (const [n, pattern] of patterns.entries()) {
        for (const m of code.matchAll(pattern)) {
            const reexport = n === 2
            const clause = n === 0 || reexport ? m[1] : null
            yield { specifier: n === 0 || reexport ? m[3] : m[2], clause, index: m.index, reexport }
        }
    }
}

function kitFile(specifier, from) {
    const alias = specifier.match(/^larsadmin-kit\/(.+)$/)
    if (alias) return alias[1]
    const resolved = /^\.{1,2}\//.test(specifier) ? resolve(dirname(from), specifier).split(sep).join('/') : specifier
    const vendor = resolved.match(/(?:^|\/)maxcxam\/larsadmin\/kit\/(.+)$/)

    return vendor ? vendor[1] : null
}

function checkJsFile(file, root, baseline) {
    const text = read(file)
    const where = (index) => `${rel(file)}:${lineOf(text, index)}`
    const sdk = new Set(baseline.js.larsadmin)

    for (const { specifier, clause, index, reexport } of jsImports(text)) {
        const { names, namespace } = importedNames(clause)
        const kit = kitFile(specifier, file)

        if (specifier === 'larsadmin') {
            const unknown = names.filter((name) => !sdk.has(name))
            if (unknown.length) fail(where(index), `imports ${unknown.map((n) => `"${n}"`).join(', ')} from "larsadmin", which is not public API (allowed: ${baseline.js.larsadmin.join(', ')})`)
            if (reexport && namespace) fail(where(index), 're-exports the whole "larsadmin" SDK; import the names you use')
        } else if (specifier.startsWith('larsadmin/')) {
            fail(where(index), `imports "${specifier}": only the bare "larsadmin" module is public (design §5.5)`)
        } else if (kit !== null) {
            const allowed = baseline.js.kit[kit] ?? (kit === 'sdk.js' ? baseline.js.larsadmin : undefined)
            if (allowed === undefined) {
                fail(where(index), `imports kit file "${kit}", which is not part of the author kit (public: ${Object.keys(baseline.js.kit).join(', ')})`)
            } else {
                const unknown = names.filter((name) => !allowed.includes(name))
                if (unknown.length) fail(where(index), `imports ${unknown.map((n) => `"${n}"`).join(', ')} from kit/${kit}, which is not public API (allowed: ${allowed.join(', ') || 'none'})`)
            }
        } else if (/(^|\/)(maxcxam\/larsadmin|packages\/larsadmin)(\/|$)|(^|\/)resources\/js\//.test(specifier) && !/^\.{1,2}\//.test(specifier)) {
            fail(where(index), `imports "${specifier}": panel internals are not public API; use the "larsadmin" SDK (design §5.5)`)
        } else if (/^(\.{1,2}\/|\/)/.test(specifier)) {
            const target = resolve(dirname(file), specifier)
            const inside = target === root || target.startsWith(root + sep)
            if (!inside) fail(where(index), `imports "${specifier}" from outside the extension (${rel(target)}); panel internals are not public API, use the "larsadmin" SDK`)
            else if (/[\\/]vendor[\\/]maxcxam[\\/]larsadmin[\\/]/.test(target)) fail(where(index), `imports "${specifier}": panel internals are not public API; use the "larsadmin" SDK`)
        }
    }
}

function checkPhpFile(file, baseline) {
    const text = read(file)
    const code = stripPhpComments(text)
    const where = (index) => `${rel(file)}:${lineOf(text, index)}`
    const allowed = new Set(Object.keys(baseline.php).map((c) => c.toLowerCase()))
    const namespaces = new Set(Object.keys(baseline.php).flatMap((c) => c.split('\\').slice(0, -1).map((_, i, parts) => parts.slice(0, i + 1).join('\\').toLowerCase())))

    const checkClass = (name, index) => {
        const lower = name.replace(/^\\/, '').toLowerCase()
        if (allowed.has(lower)) return
        if (namespaces.has(lower) && lower.length > PHP_NS.length - 1) {
            fail(where(index), `imports the namespace ${name}; import the classes themselves so the check can verify them`)
        } else {
            fail(where(index), `uses ${name}, which is not public larsadmin API (design §4, §18.2); see kit/public-api.json for the allowed classes`)
        }
    }

    // use Maxcxam\Larsadmin\Fields\{Text, ID as Key};
    for (const m of code.matchAll(/\buse\s+(?:function\s+|const\s+)?\\?(Maxcxam\\Larsadmin\\[\w\\]*)\{([^}]*)\}/g)) {
        for (const part of m[2].split(',').map((p) => p.trim()).filter(Boolean)) checkClass(m[1] + part.split(/\s+as\s+/i)[0].trim(), m.index)
    }
    // Any other fully qualified reference (use statements, inline \Maxcxam\Larsadmin\..., ::class).
    for (const m of code.matchAll(/\\?\bMaxcxam\\Larsadmin\\[\w\\]*\w(?![\w\\]*\{)/g)) {
        if (/^\\?Maxcxam\\Larsadmin\\[\w\\]*$/.test(m[0])) checkClass(m[0], m.index)
    }
    for (const m of code.matchAll(new RegExp(`(?:->|::)\\s*(${INTERNAL_PHP_MEMBERS.join('|')})\\s*\\(`, 'g'))) {
        fail(where(m.index), `calls ${m[1]}(), an internal larsadmin member (not public API, ADR-035)`)
    }
}

function checkExtension(dir, baseline) {
    const root = resolve(process.cwd(), dir)
    if (!existsSync(root) || !statSync(root).isDirectory()) {
        fail(dir, 'extension directory not found')
        return 0
    }
    // Exemption (documented in the header): the top-level tests/ directory may use internal classes.
    const tests = join(root, 'tests') + sep
    let files = 0
    const classes = new Map()
    for (const file of walk(root)) {
        if (file.startsWith(tests)) continue
        if (/\.(m?js|ts|vue|css)$/.test(file)) checkJsFile(file, root, baseline)
        else if (file.endsWith('.php')) {
            checkPhpFile(file, baseline)
            const text = read(file)
            const info = phpClass(text)
            if (info) classes.set(info.fqcn, { ...info, file, text })
        } else continue
        files++
    }
    checkOverrides(classes, baseline)
    return files
}

// CRX-04: a class that extends Field, Filter, Card / DataCard or Tool (directly or through the extension's own
// classes) may override only the stable members of the larsadmin classes it inherits from.
function checkOverrides(classes, baseline) {
    for (const info of classes.values()) {
        const own = new Set()
        let parent = info.parent
        while (parent && classes.has(parent) && !own.has(parent)) {
            own.add(parent)
            parent = classes.get(parent).parent
        }
        const chain = []
        for (let cls = parent; cls && larsadminClass(cls); cls = larsadminClass(cls).parent) chain.push(cls)
        if (!chain.some((cls) => OVERRIDE_ROOTS.includes(cls))) continue

        const inherited = new Map()
        for (const cls of chain) {
            for (const member of larsadminClass(cls).members) {
                if (member.method && member.visibility !== 'private' && !inherited.has(member.name.toLowerCase())) inherited.set(member.name.toLowerCase(), cls)
            }
        }
        const hooks = new Set(chain.flatMap((cls) => baseline.php[cls] ?? []).map((name) => name.toLowerCase()))

        for (const member of info.members) {
            const key = member.name.toLowerCase()
            if (!member.method || !inherited.has(key) || hooks.has(key)) continue
            fail(`${rel(info.file)}:${lineOf(info.text, member.index)}`, `${info.name} overrides ${inherited.get(key)}::${member.name}(), which is not a stable override hook (review CRX-04). Stable hooks of this base: ${sorted(chain.flatMap((cls) => (baseline.php[cls] ?? []).filter((n) => !n.startsWith('$')))).join(', ')}`)
        }
    }
}

// --- Main ---------------------------------------------------------------------------------------------------------

if (!HAS_SOURCES && (update || !targets.length)) {
    console.error(update
        ? 'The surface can only be re-snapshotted in a checkout of the larsadmin package (resources/js and src/ are needed).'
        : 'Nothing to check: give the extension directories to scan, e.g. `node vendor/maxcxam/larsadmin/kit/check-public-api.mjs .`')
    process.exit(1)
}

const current = HAS_SOURCES ? currentSurface(PKG) : null

if (update) {
    const previous = existsSync(BASELINE) ? JSON.parse(read(BASELINE)) : null
    const snapshot = {
        $comment: 'Public extension API of larsadmin (design 1.5 §4, §5.5, §5.6, §6.4, §18.2; ADR-034): frozen for 1.x, minors only add. Generated by `npm run check:api -- --update`; review the diff before committing.',
        apiVersion: (read(join(PKG, 'src/Larsadmin.php')).match(/API_VERSION\s*=\s*'([^']+)'/) ?? [])[1] ?? null,
        js: { larsadmin: current.larsadmin, kit: current.kit },
        php: current.php,
        overrides: current.overrides,
        fieldContract: current.fieldContract,
    }
    writeFileSync(BASELINE, JSON.stringify(snapshot, null, 4) + '\n')

    if (previous) {
        const flat = (s) => [
            ...s.js.larsadmin.map((n) => `larsadmin: ${n}`),
            ...Object.entries(s.js.kit).flatMap(([f, ns]) => [`kit/${f}`, ...ns.map((n) => `kit/${f}: ${n}`)]),
            ...Object.entries(s.php).flatMap(([c, ms]) => [c, ...ms.map((m) => `${c}::${m}`)]),
            ...Object.entries(s.overrides ?? {}).flatMap(([t, c]) => [t, ...c.props.map((p) => `${t} prop ${p}`), ...c.emits.map((e) => `${t} event ${e}`)]),
            ...Object.entries(s.fieldContract ?? {}).flatMap(([h, ps]) => ps.map((p) => `field contract ${h}: ${p}`)),
        ]
        const before = new Set(flat(previous))
        const after = new Set(flat(snapshot))
        for (const item of after) if (!before.has(item)) console.log(`+ ${item}`)
        for (const item of before) if (!after.has(item)) console.log(`- ${item}   <-- REMOVAL: a SemVer break in 1.x`)
    }
    for (const item of current.pending) console.log(`pending (in the design, not implemented yet): ${item}`)
    for (const item of unlistedStableMembers(snapshot)) console.log(`not snapshotted and not @internal (CRX-03, the check fails): ${item}`)
    console.log(`Wrote ${rel(BASELINE)}.`)
    process.exit(0)
}

if (!existsSync(BASELINE)) {
    console.error(`${rel(BASELINE)} is missing; create it with \`npm run check:api -- --update\`.`)
    process.exit(1)
}

const baseline = JSON.parse(read(BASELINE))
if (current) checkSurface(PKG, baseline, current)
else notes.push('Installed copy (no package sources): checking extensions against the shipped kit/public-api.json only.')

const scanned = targets.map((dir) => `${dir} (${checkExtension(dir, baseline)} files)`)

const snapshotted = (item) => {
    const [cls, member] = item.split('::')
    return cls in baseline.php && (member === undefined || baseline.php[cls].includes(member))
}
for (const item of (current?.pending ?? []).filter((item) => !snapshotted(item))) notes.push(`pending (in the design, not implemented yet): ${item}`)
for (const note of notes) console.log(note)

if (errors.length) {
    console.error(`\nPublic API check failed (${errors.length}):\n`)
    for (const error of errors) console.error(`  ✗ ${error}`)
    console.error('\nThe public extension API is frozen for 1.x (ADR-034; design 1.5 §4, §5.5, §5.6, §18).')
    process.exit(1)
}

console.log(`Public API check passed: ${current ? `panel surface matches ${rel(BASELINE)}` : `against ${rel(BASELINE)}`}${scanned.length ? `; extensions use only public API: ${scanned.join(', ')}` : ''}.`)
