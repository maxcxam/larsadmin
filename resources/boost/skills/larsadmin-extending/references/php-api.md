# Larsadmin extension API: PHP reference

Everything here is stable 1.x API (`Larsadmin::API_VERSION` is `'1.6'`). Source of truth:
`vendor/maxcxam/larsadmin/docs/guide/en/19-extensions.md` and `kit/public-api.json`. Members not listed are
internal even when PHP makes them public: the field prototype, visibility, readonly, ability snapshot and
action binding helpers the panel calls on `Entity` and `Field` (never call them from an extension; read
abilities through `authorizedTo()`), the `Extension` and `CardRequest` constructors,
`DataCard::cardRequest()`/`resolve()`/`cacheKey()`, and anything marked `@internal`.

## `Maxcxam\Larsadmin\Larsadmin` (facade, static)

| Member | Purpose |
|---|---|
| `Larsadmin::API_VERSION` | `'1.6'`: the `MAJOR.MINOR` of the last public API extension |
| `Larsadmin::extension(string $name, string $path): Extension` | register an extension: Composer name and **absolute** path of the built `dist/`. Throws `InvalidArgumentException` for a bad name, a relative, missing or symlinked path, or the same name with another path. Idempotent for the same pair |
| `Larsadmin::registeredExtensions(): array` | all extensions in registration order |
| `Larsadmin::extensionFor(string $name): ?Extension` | one registered extension |
| `Larsadmin::routes(Closure $routes): void` | register JSON routes under `{path}/ext`, named `larsadmin.ext.*`, behind the panel middleware. Guarantees only the panel gate |
| `Larsadmin::findRecord(Request $request, string $entity, int\|string $id, string $ability = 'view'): Model` | `viewAny` (403), `detailQuery()` scope (404), then the policy ability `view`/`update`/`delete` (403). `$entity` is the uriKey |
| `Larsadmin::findField(Request $request, string $entity, Model $record, string $attribute, string $context = Field::UPDATE): Field` | the entity's field as the user sees it for the record: bound, visible in `$context` (404), not readonly for `create`/`update` (403), with record abilities; returns a clone |
| `Larsadmin::audited(Request $request, string $entity, Model $record, Closure $change): mixed` | run a change and record it in the audit log as `updated`, in one transaction |
| `Larsadmin::tools(array $tools): void` | register sidebar tools (classes or instances); throws for a non-`Tool`, a key without `/` or a duplicate `uriKey` |
| `Larsadmin::registeredTools(): array`, `Larsadmin::toolForKey(string $key): ?Tool` | registered tools (visibility not checked) |
| `Larsadmin::assetsVersion(): ?string` | the Inertia asset version; changes when the panel assets or an extension's files change |

## `Maxcxam\Larsadmin\Extensions\Extension`

| Member | Purpose |
|---|---|
| `script(string $file = 'extension.js')` | entry module relative to the path |
| `style(?string $file = 'extension.css')` | stylesheet; `null` disables, a missing file is skipped |
| `translations(string $namespace, string $group = 'ui')` | expose `trans("{namespace}::{group}")` to `useI18n()` |
| `version(): string` | 12 characters of the md5 of `.vite/manifest.json`; part of the file URLs |
| `url(string $file): string` | versioned URL of a file in the path |
| `$name`, `$path` | readonly name and `realpath` |

## `Maxcxam\Larsadmin\Fields\Field` (stable for subclasses)

| Member | Purpose |
|---|---|
| `public string $component` | Vue component key; `vendor/name` for extensions |
| `jsonSerialize(): array` | `[...parent::jsonSerialize(), 'ratingMax' => ...]`; subclass keys win |
| `withMeta(array $meta)` | extra props without overriding `jsonSerialize()` |
| `$multipart`, `multipart(bool $multipart = true)` | the field uploads files; the form is sent as `multipart/form-data` |
| `resolveValue()`, `formatForDisplay()`, `fillValue()`, `fillString()`, `typeRules()`, `implicitRules()`, `acceptsNullInput()`, `defaultAlign()` | protected hooks |
| `fill(Request, Model): ?Closure` | fill; a returned closure runs after `save()` |
| `fillInto(Request, Model, FileJournal): ?Closure` | fill with the save's file journal |
| `fillAttribute()`, `resolve()`, `eagerLoads()`, `applySort()` | public hooks |
| `bindTo(Request $request, string $entity)` | called once with the entity class |
| `hasAuditValue()`, `auditValue(Model $record)` | audit a field without a column |
| `__construct()`, `resolveDefault()`, `rulesFor()`, `validationAttributeNames()`, `isRequired()`, `sortable()` | overridable |
| `authorizedTo(string $ability): bool` (1.6) | whether the user may `view`/`update`/`delete` the record the field is resolved for; reuses the panel's per-row answers (no extra policy call). `false` for other abilities, the create context, action fields and hand-built fields |
| `inAction(): bool` (1.6) | the field comes from an action's `fields()`; its `context` stays `create` |
| `actionValue(Request $request): mixed` (1.6) | the value for `ActionFields`; default fills a throw-away model and reads `fillAttribute()`. Must be JSON-safe for queued actions |

Constants for contexts: `Field::INDEX`, `Field::DETAIL`, `Field::CREATE`, `Field::UPDATE`. Only the members in
this table may be overridden; `check-public-api.mjs` fails on others.

## `Maxcxam\Larsadmin\Files\FileJournal`

`created(string $disk, string $path)`: deleted when the save fails. `obsolete(string $disk, string $path)`:
deleted after the outermost commit. Exists in form saves only.

## `Maxcxam\Larsadmin\Files\FileSafety` (1.6, final, static)

The checks and headers of the built-in `File`/`Image` fields, for extensions that store or serve files.

| Method | Purpose |
|---|---|
| `rule(bool $publicDisk = false): Closure` | validation rule: blocked extensions by every name segment and by detected content |
| `blockedExtension(UploadedFile $file, bool $publicDisk = false): ?string` | the first blocked extension or `null` |
| `storedExtension(UploadedFile $file, bool $publicDisk = false): string` | detected, allow-listed extension, else `bin`; never the client's |
| `sanitizeName(string $name): string` | clean client name for metadata and `Content-Disposition`; may be `''` |
| `asciiName(string $name): string` | ASCII fallback name |
| `isRasterImage(string $path, ?string $mime = null): bool` | jpg, jpeg, png, gif, webp, avif; never SVG |
| `response(FilesystemAdapter $disk, string $path, ?string $name = null, bool $download = false, ?int $ttlMinutes = null): StreamedResponse` | streamed response: inline only for raster images, else an attachment; `nosniff`, sandbox CSP, CORP same-origin, no-referrer, private cache. Authorize first |

## Cards

`Maxcxam\Larsadmin\Metrics\Card` (static card): `$component`, `$width`, `$meta`, `name()`, `uriKey()`, `width()`,
`help()`, `canSee()`, `withMeta()` (a base key such as `component`, `uriKey`, `name`, `width`, `helpText`,
`endpoint` throws), `jsonSerialize()`.

`Maxcxam\Larsadmin\Metrics\DataCard`:

| Method | Purpose |
|---|---|
| `rules(): array` | query parameter rules; invalid = 422, unlisted parameters dropped |
| `data(CardRequest $request): mixed` | the JSON data (`200 {"data": ...}`) |
| `cacheFor()` | seconds, `DateInterval` or `DateTimeInterface`; `null` = no cache |
| `cacheKeyParts(CardRequest $request): array` | extra cache key parts (tenant, team, role) |
| `entityQuery(CardRequest $request, string $entity, ?Closure $constraint = null): Builder` | protected: `viewAny` check + `indexQuery()` scope of a registered entity |

`Maxcxam\Larsadmin\Metrics\CardRequest` (readonly): `$request`, `$parameters` (validated only), `$timezone`,
`$now` (`CarbonImmutable`), `$owner` (`dashboard:{key}` or `entity:{key}`), `user()`, `get($key, $default)`.

## `Maxcxam\Larsadmin\Tools\Tool`

`$component`, `Tool::ICONS`, `make(...$args)`, `name()`, `uriKey()`, `icon()`, `canSee(Closure)`,
`authorizedToSee(Request)`, `props(Request $request): array`. Page: `GET {path}/tools/{uriKey}`; unknown or
invisible = 404.

## `Maxcxam\Larsadmin\Filters\Filter`

`$component`, `make()`, `apply()`, `name()`, `key()`, `options()`, `default()`, `normalize()`, `canSee()`,
`serialize(Request $request, mixed $value): array` (keep every key of `parent::serialize()`).

## `Maxcxam\Larsadmin\Testing\InteractsWithLarsadmin`

`serializeField()`, `fillField()`, `cardData()`, `assertExtensionRegistered()`, `extensionAssetResponse()`; see
the `larsadmin-testing` skill.

## Config

| Key | Env | Default |
|---|---|---|
| `larsadmin.extensions.source_maps` | `LARSADMIN_EXTENSION_SOURCE_MAPS` | `false` (serve `*.map`, debugging only) |
| `larsadmin.extensions.max_age` | | `31536000` (browser cache of versioned files) |
