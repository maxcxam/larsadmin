<?php

namespace Maxcxam\Larsadmin\Fields;

use ArrayObject;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Files\FileJournal;
use Maxcxam\Larsadmin\Files\FileTypes;
use Maxcxam\Larsadmin\Files\FileUrls;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Rules\FileFieldState;
use RuntimeException;
use Throwable;

/**
 * An uploaded file (or several with multiple()) stored on a filesystem disk.
 *
 * Single: the column holds the path relative to the disk root (storeOriginalName() adds the client
 * name). Multiple: the column holds a JSON array of {path, name, size, mime}. The form sends
 * {attr}[present]=1, {attr}[known][]=token (the files it showed), {attr}[keep][]=token (the files
 * it keeps) and {attr}[upload][]=file. Tokens are matched only against the record's current files,
 * so a client can never introduce a path, and only files the form knew about can be removed
 * (ADR-014, ADR-015, ADR-017).
 */
class File extends Field
{
    public string $component = 'file-field';

    /**
     * Rule tokens interpreted for the whole field rather than per uploaded file.
     */
    protected const array FIELD_LEVEL_RULES = ['required', 'nullable', 'sometimes', 'array'];

    /**
     * Casts under which the multiple() column is assigned a PHP array instead of a JSON string.
     */
    protected const array ARRAY_CASTS = [
        'array', 'json', 'object', 'collection',
        'encrypted:array', 'encrypted:json', 'encrypted:object', 'encrypted:collection',
    ];

    protected bool $multiple = false;

    protected ?int $maxFiles = null;

    protected ?string $disk = null;

    protected ?bool $public = null;

    protected string|Closure|null $directory = null;

    /**
     * @var list<string>|null
     */
    protected ?array $types = null;

    protected ?int $maxSize = null;

    protected ?string $originalNameColumn = null;

    protected ?bool $prunable = null;

    public bool $removable = true;

    protected ?Closure $thumbnailCallback = null;

    /**
     * @var class-string<Entity>|null
     */
    protected ?string $parentEntity = null;

    /**
     * The serialized files of the resolved record (state of resolved clones only).
     *
     * @var list<array<string, mixed>>
     */
    protected array $resolvedFiles = [];

    protected int $resolvedCount = 0;

    protected ?string $urlsExpireAt = null;

    public function bindTo(Request $request, string $entity): void
    {
        $this->parentEntity = $entity;

        $config = config('filesystems.disks.'.$this->diskName());

        if ($config === null) {
            throw new InvalidArgumentException("The disk [{$this->diskName()}] of the file field [{$this->attribute}] is not configured in filesystems.disks.");
        }

        // A public local disk without a base URL would emit "/storage/..." links that do not exist.
        if ($this->isPublic() && ($config['driver'] ?? null) === 'local' && empty($config['url'])) {
            throw new InvalidArgumentException("The file field [{$this->attribute}] is public, but the local disk [{$this->diskName()}] has no \"url\"; use a disk such as \"public\" or call private().");
        }
    }

    /**
     * Store several files in the column as a JSON array of {path, name, size, mime}.
     */
    public function multiple(bool $multiple = true): static
    {
        if ($multiple && $this->originalNameColumn !== null) {
            throw new LogicException("The file field [{$this->attribute}] cannot combine multiple() with storeOriginalName().");
        }

        $this->multiple = $multiple;

        return $this;
    }

    /**
     * The maximum number of files of a multiple() field (default: config "larsadmin.files.max_files").
     */
    public function maxFiles(int $max): static
    {
        if ($max < 1) {
            throw new InvalidArgumentException("The maxFiles of the file field [{$this->attribute}] must be at least 1.");
        }

        $this->maxFiles = $max;

        return $this;
    }

    /**
     * The filesystem disk (default: config "larsadmin.files.disk").
     */
    public function disk(string $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * Serve the files from the disk URL instead of signed URLs.
     */
    public function public(): static
    {
        $this->public = true;

        return $this;
    }

    /**
     * Serve the files only through signed, re-authorized URLs.
     */
    public function private(): static
    {
        $this->public = false;

        return $this;
    }

    /**
     * The directory on the disk, a relative path or fn (Request $request, Model $record): string.
     * Default: "{files.directory}/{entity uriKey}/{attribute}".
     */
    public function path(string|Closure $directory): static
    {
        if (is_string($directory)) {
            $this->assertSafeDirectory($directory);
        }

        $this->directory = $directory;

        return $this;
    }

    /**
     * Allow only these extensions (checked against the content and the client name).
     *
     * @param  array<int, string>  $types
     */
    public function types(array $types): static
    {
        $normalized = [];

        foreach ($types as $type) {
            $type = strtolower(ltrim(trim((string) $type), '.'));

            if (preg_match('/^[a-z0-9]{1,10}$/', $type) !== 1) {
                throw new InvalidArgumentException("Invalid file type [{$type}] for the field [{$this->attribute}]; use extensions such as 'pdf'.");
            }

            $normalized[] = $type;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException("The types() of the file field [{$this->attribute}] need at least one extension; omit types() to allow any type that is not blocked.");
        }

        $this->types = array_values(array_unique($normalized));

        return $this;
    }

    /**
     * The maximum size of one file in kilobytes (default: config "larsadmin.files.max_size").
     */
    public function maxSize(int $kilobytes): static
    {
        if ($kilobytes < 1) {
            throw new InvalidArgumentException("The maxSize of the file field [{$this->attribute}] must be at least 1 kilobyte.");
        }

        $this->maxSize = $kilobytes;

        return $this;
    }

    /**
     * Store the sanitized client file name in this column (single files only).
     */
    public function storeOriginalName(string $column): static
    {
        if ($this->multiple) {
            throw new LogicException("The file field [{$this->attribute}] cannot combine multiple() with storeOriginalName().");
        }

        $this->originalNameColumn = $column;

        return $this;
    }

    /**
     * Delete replaced and removed files, and the files of deleted records (default: config "larsadmin.files.prune").
     */
    public function prunable(bool $prunable = true): static
    {
        $this->prunable = $prunable;

        return $this;
    }

    /**
     * Allow removing files (default). When false, the form offers no "remove" and the server keeps
     * every current file; replacing a single file with an upload stays possible.
     */
    public function removable(bool $removable = true): static
    {
        $this->removable = $removable;

        return $this;
    }

    /**
     * File fields cannot be sorted.
     */
    public function sortable(bool $sortable = true): static
    {
        if ($sortable) {
            throw new LogicException("The file field [{$this->attribute}] cannot be sortable.");
        }

        return parent::sortable(false);
    }

    public function diskName(): string
    {
        return $this->disk ?? (string) config('larsadmin.files.disk', 'local');
    }

    /**
     * Whether the files are public: public()/private(), else the disk's "visibility" config.
     */
    public function isPublic(): bool
    {
        return $this->public ?? config('filesystems.disks.'.$this->diskName().'.visibility') === 'public';
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function isPrunable(): bool
    {
        return $this->prunable ?? (bool) config('larsadmin.files.prune', true);
    }

    /**
     * The maximum size of one file in kilobytes.
     */
    public function maxSizeKilobytes(): int
    {
        return $this->maxSize ?? max(1, (int) config('larsadmin.files.max_size', 10240));
    }

    /**
     * The maximum number of files: 1 for a single field.
     */
    public function maxFileCount(): int
    {
        return $this->multiple ? ($this->maxFiles ?? max(1, (int) config('larsadmin.files.max_files', 10))) : 1;
    }

    /**
     * The allowed extensions, or null for any type that is not blocked.
     *
     * @return list<string>|null
     */
    public function allowedTypes(): ?array
    {
        return $this->types;
    }

    /**
     * The files currently referenced by the record, in stored order. Invalid entries are skipped.
     *
     * @return list<StoredFile>
     */
    public function filesOf(Model $record): array
    {
        $raw = $record->getAttribute($this->attribute);

        if (! $this->multiple) {
            if (! is_string($raw)) {
                return [];
            }

            $name = $this->originalNameColumn !== null ? $record->getAttribute($this->originalNameColumn) : null;
            $file = StoredFile::fromStored(['path' => $raw, 'name' => $name], $this->name);

            return $file === null ? [] : [new StoredFile($file->path, $file->name, null, FileTypes::mimeFor($file->extension()))];
        }

        $files = [];

        // Entries without a name are labelled "{field label} ({position}).{extension}".
        foreach ($this->storedEntries($raw) as $entry) {
            $file = StoredFile::fromStored($entry, $this->name.' ('.(count($files) + 1).')');

            if ($file !== null && ! isset($files[$file->path])) {
                $files[$file->path] = $file;
            }
        }

        return array_values($files);
    }

    /**
     * The entries of a multiple() column, whatever its cast: null, '', a JSON string, an array,
     * an ArrayObject or a Collection.
     *
     * @return array<int|string, mixed>
     */
    protected function storedEntries(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : json_decode($raw, true);
        }

        return match (true) {
            $raw instanceof Collection => $raw->all(),
            $raw instanceof ArrayObject => $raw->getArrayCopy(),
            is_object($raw) => get_object_vars($raw),
            is_array($raw) => $raw,
            default => [],
        };
    }

    /**
     * Mark every file of the record obsolete (the record is deleted).
     */
    public function pruneFor(Model $record, FileJournal $journal): void
    {
        if (! $this->isPrunable()) {
            return;
        }

        foreach ($this->filesOf($record) as $file) {
            $journal->obsolete($this->diskName(), $file->path);
        }
    }

    /**
     * Emit URLs for the resolved record (the user may view it): sets the "view" ability that
     * authorizedTo('view') answers, replacing the other abilities.
     *
     * @internal
     */
    public function withUrls(bool $urls = true): static
    {
        return $this->withAbilities(['view' => $urls]);
    }

    public function resolve(Model $record): static
    {
        $files = $this->filesOf($record);
        $index = $this->context === self::INDEX;

        $this->resolvedCount = count($files);
        $tokens = array_map(fn (StoredFile $file) => $file->token($this->diskName()), $files);
        $this->value = in_array($this->context, [self::CREATE, self::UPDATE], true)
            ? ['present' => 1, 'known' => $tokens, 'keep' => $tokens, 'upload' => []]
            : null;

        $names = array_map(fn (StoredFile $file) => $file->name, $files);

        $this->displayedAs = $this->displayCallback
            ? call_user_func($this->displayCallback, $names, $record, $this->attribute)
            : implode(', ', $names);

        // The index cell shows the first file and the count.
        if ($index) {
            $files = array_slice($files, 0, 1);
        }

        $urls = $this->urlsFor($record);

        $this->resolvedFiles = array_map(function (StoredFile $file) use ($urls, $record, $index) {
            $links = $urls?->for($file) ?? ['url' => null, 'downloadUrl' => null];
            $thumbnail = null;

            if ($file->isRasterImage()) {
                $custom = $this->thumbnailCallback ? call_user_func($this->thumbnailCallback, $file, $record) : null;
                $thumbnail = is_string($custom) ? $custom : $links['url'];
            }

            return $index
                ? ['name' => $file->name, 'isImage' => $file->isRasterImage(), 'url' => $links['url'], 'thumbnailUrl' => $thumbnail]
                : [
                    'token' => $file->token($this->diskName()),
                    'name' => $file->name,
                    'size' => $file->size,
                    'mime' => $file->mime,
                    'isImage' => $file->isRasterImage(),
                    'url' => $links['url'],
                    'downloadUrl' => $links['downloadUrl'],
                    'thumbnailUrl' => $thumbnail,
                ];
        }, $files);

        $this->urlsExpireAt = $urls?->expiresAt()?->format(DATE_ATOM);

        return $this;
    }

    /**
     * The URL builder for the record, or null when no URL may be emitted: the user may not view
     * the record, or its key is not a safe path segment.
     */
    protected function urlsFor(Model $record): ?FileUrls
    {
        $key = $this->parentEntity !== null ? $this->parentEntity::pathSegment($record->getKey()) : null;

        if (! $this->authorizedTo('view') || $key === null) {
            return null;
        }

        return new FileUrls(
            $this->diskName(),
            $this->isPublic(),
            $this->parentEntity::uriKey(),
            $key,
            $this->attribute,
            static::urlExpiry(),
        );
    }

    /**
     * The expiry of signed and temporary URLs: at least "url_ttl" minutes from now, rounded up to a
     * quarter of the TTL (at least a minute), so URLs stay identical for a while and browsers can
     * cache private thumbnails.
     */
    public static function urlExpiry(): CarbonInterface
    {
        $ttl = max(1, (int) config('larsadmin.files.url_ttl', 30)) * 60;
        $bucket = max(60, intdiv($ttl, 4));

        return now()->setTimestamp((int) (ceil((now()->getTimestamp() + $ttl) / $bucket) * $bucket));
    }

    public function resolveDefault(Request $request): static
    {
        $this->value = ['present' => 1, 'known' => [], 'keep' => [], 'upload' => []];
        $this->displayedAs = '';
        $this->resolvedFiles = [];
        $this->resolvedCount = 0;
        $this->urlsExpireAt = null;

        return $this;
    }

    public function fill(Request $request, Model $record): ?Closure
    {
        $journal = new FileJournal;

        try {
            return $this->fillInto($request, $record, $journal);
        } catch (Throwable $e) {
            $journal->discardCreated();

            throw $e;
        }
    }

    /**
     * Store the uploads, record them and the files that became obsolete in the journal, then assign
     * the column. A request without the field (or with a non-array value) changes nothing.
     *
     * Only files the form knew about ({attr}[known]) and did not keep are removed: files added
     * meanwhile by someone else, or tokens the form cannot know (rotated APP_KEY), stay. Without
     * "known" nothing is removed; an upload to a single field always replaces its file.
     *
     * @internal Called by Entity::save(), which discards and purges the journal.
     */
    public function fillInto(Request $request, Model $record, FileJournal $journal): ?Closure
    {
        if ($this->fillCallback) {
            return parent::fill($request, $record);
        }

        $value = $request->all()[$this->attribute] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $current = $this->filesOf($record);
        [$staying] = $this->survivingFiles($current, $value);
        $uploads = $this->uploadsOf($value);

        $stored = [];

        if ($uploads !== []) {
            $directory = $this->directoryFor($request, $record);

            foreach ($uploads as $upload) {
                $stored[] = $this->store($upload, $directory, $journal);
            }
        }

        $final = $this->multiple ? [...$staying, ...$stored] : array_slice([...$stored, ...$staying], 0, 1);

        $paths = array_map(fn (StoredFile $file) => $file->path, $final);
        $removed = array_filter($current, fn (StoredFile $file) => ! in_array($file->path, $paths, true));

        if ($stored === [] && $removed === [] && $paths === array_map(fn (StoredFile $file) => $file->path, $current)) {
            return null;
        }

        if ($this->isPrunable()) {
            foreach ($removed as $file) {
                $journal->obsolete($this->diskName(), $file->path);
            }
        }

        $this->assign($record, $final);

        return null;
    }

    /**
     * The number of files the field ends up with for the request value (null: field not sent),
     * computed like fillInto().
     */
    public function finalCount(?Model $record, mixed $value): int
    {
        $current = $record !== null ? $this->filesOf($record) : [];

        if (! is_array($value)) {
            return count($current);
        }

        [$staying] = $this->survivingFiles($current, $value);
        $uploads = count($this->uploadsOf($value));

        return $this->multiple ? count($staying) + $uploads : min(1, count($staying) + $uploads);
    }

    /**
     * The current files that stay (the kept ones in the client's order, then the others in stored
     * order) and the ones removed: known to the form, not kept, and the field is removable.
     *
     * @param  list<StoredFile>  $current
     * @param  array<array-key, mixed>  $value
     * @return array{0: list<StoredFile>, 1: list<StoredFile>}
     */
    protected function survivingFiles(array $current, array $value): array
    {
        $kept = $this->filesWithTokens($current, $value['keep'] ?? []);
        $known = $this->filesWithTokens($current, $value['known'] ?? []);

        $removed = $this->removable
            ? array_values(array_filter($known, fn (StoredFile $file) => ! in_array($file, $kept, true)))
            : [];

        $others = array_filter($current, fn (StoredFile $file) => ! in_array($file, $kept, true) && ! in_array($file, $removed, true));

        return [[...$kept, ...array_values($others)], $removed];
    }

    /**
     * The uploaded files of the request value (at most one for a single field).
     *
     * @param  array<array-key, mixed>  $value
     * @return list<UploadedFile>
     */
    protected function uploadsOf(array $value): array
    {
        $uploads = array_values(array_filter(
            is_array($value['upload'] ?? null) ? $value['upload'] : [],
            fn ($upload) => $upload instanceof UploadedFile,
        ));

        return $this->multiple ? $uploads : array_slice($uploads, 0, 1);
    }

    /**
     * The current files whose tokens the client sent, in the client's order. Unknown tokens are ignored.
     *
     * @param  list<StoredFile>  $current
     * @return list<StoredFile>
     */
    protected function filesWithTokens(array $current, mixed $tokens): array
    {
        $kept = [];

        foreach (is_array($tokens) ? $tokens : [] as $token) {
            if (! is_string($token)) {
                continue;
            }

            foreach ($current as $file) {
                if (hash_equals($file->token($this->diskName()), $token) && ! in_array($file, $kept, true)) {
                    $kept[] = $file;
                }
            }
        }

        return $kept;
    }

    /**
     * Write one upload under a random name with the extension guessed from its content.
     */
    protected function store(UploadedFile $upload, string $directory, FileJournal $journal): StoredFile
    {
        $name = Str::random(40).'.'.FileTypes::storedExtension($upload, $this->isPublic());

        $path = $upload->storeAs($directory, $name, [
            'disk' => $this->diskName(),
            'visibility' => $this->isPublic() ? 'public' : 'private',
        ]);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException("Could not store an upload of the file field [{$this->attribute}] on the disk [{$this->diskName()}].");
        }

        $journal->created($this->diskName(), $path);

        $clientName = FileTypes::sanitizeName($upload->getClientOriginalName());

        if ($clientName === '') {
            $clientName = FileTypes::sanitizeName($this->name.'.'.pathinfo($name, PATHINFO_EXTENSION));
        }

        $size = $upload->getSize();

        return new StoredFile($path, $clientName !== '' ? $clientName : $name, is_int($size) ? $size : null, $upload->getMimeType());
    }

    /**
     * @param  list<StoredFile>  $files
     */
    protected function assign(Model $record, array $files): void
    {
        if (! $this->multiple) {
            $record->{$this->attribute} = $files[0]->path ?? null;

            if ($this->originalNameColumn !== null) {
                $record->{$this->originalNameColumn} = $files[0]->name ?? null;
            }

            return;
        }

        $entries = array_map(fn (StoredFile $file) => $file->toArray(), $files);

        $record->{$this->attribute} = $this->castsToArray($record)
            ? $entries
            : json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Whether the model casts the column so that it accepts a PHP array.
     */
    protected function castsToArray(Model $record): bool
    {
        $cast = $record->getCasts()[$this->attribute] ?? null;

        if (! is_string($cast)) {
            return false;
        }

        $class = explode(':', $cast, 2)[0];

        return in_array($cast, self::ARRAY_CASTS, true)
            || in_array($class, [AsArrayObject::class, AsCollection::class, AsEncryptedArrayObject::class, AsEncryptedCollection::class], true);
    }

    /**
     * The directory uploads of the record are stored in.
     */
    protected function directoryFor(Request $request, Model $record): string
    {
        $directory = match (true) {
            $this->directory instanceof Closure => call_user_func($this->directory, $request, $record),
            is_string($this->directory) => $this->directory,
            default => implode('/', [
                trim((string) config('larsadmin.files.directory', 'larsadmin'), '/'),
                $this->parentEntity !== null ? $this->parentEntity::uriKey() : 'files',
                $this->attribute,
            ]),
        };

        if (! is_string($directory)) {
            throw new InvalidArgumentException("The path() callback of the file field [{$this->attribute}] must return a string.");
        }

        $this->assertSafeDirectory($directory);

        return $directory;
    }

    /**
     * A directory must be relative, made of segments of [A-Za-z0-9._-] other than "." and "..".
     */
    protected function assertSafeDirectory(string $directory): void
    {
        $segments = explode('/', $directory);

        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1) {
                throw new InvalidArgumentException("Invalid path [{$directory}] for the file field [{$this->attribute}]; use a relative directory of letters, digits, '.', '_' and '-' segments.");
            }
        }
    }

    /**
     * The user rules without the field-level tokens, applied to each uploaded file.
     *
     * @return array<int, mixed>
     */
    protected function userRules(string $context): array
    {
        return array_values(array_filter(
            [...$this->rules, ...($context === self::CREATE ? $this->creationRules : $this->updateRules)],
            fn ($rule) => ! (is_string($rule) && in_array($rule, self::FIELD_LEVEL_RULES, true)),
        ));
    }

    /**
     * Whether "required" is among the user rules of the context.
     */
    public function isRequiredOn(string $context): bool
    {
        return in_array('required', [...$this->rules, ...($context === self::CREATE ? $this->creationRules : $this->updateRules)], true);
    }

    public function isRequired(): bool
    {
        return $this->isRequiredOn($this->context === self::UPDATE ? self::UPDATE : self::CREATE);
    }

    /**
     * Rules implied by the type restriction, for each uploaded file.
     *
     * @return array<int, mixed>
     */
    protected function typeRules(): array
    {
        return $this->types === null ? [] : [
            'mimes:'.implode(',', $this->types),
            'extensions:'.implode(',', $this->types),
        ];
    }

    public function rulesFor(string $context, ?Model $record = null): array
    {
        $attribute = $this->attribute;
        $max = $this->maxFileCount();
        $key = (string) ($record?->getKey() ?? 'NULL');

        $userRules = array_map(
            fn ($rule) => is_string($rule) ? str_replace('{{id}}', $key, $rule) : $rule,
            $this->userRules($context),
        );

        return [
            $attribute => ['array:present,known,keep,upload', new FileFieldState($this, $record, $this->isRequiredOn($context))],
            "{$attribute}.present" => ['sometimes', 'in:1'],
            "{$attribute}.known" => ['sometimes', 'array', "max:{$max}"],
            "{$attribute}.known.*" => ['string', 'regex:/^[a-f0-9]{32}$/'],
            "{$attribute}.keep" => ['sometimes', 'array', "max:{$max}"],
            "{$attribute}.keep.*" => ['string', 'regex:/^[a-f0-9]{32}$/'],
            "{$attribute}.upload" => ['sometimes', 'array', "max:{$max}"],
            "{$attribute}.upload.*" => [
                'file',
                'max:'.$this->maxSizeKilobytes(),
                FileTypes::notBlocked($this->isPublic()),
                ...$this->typeRules(),
                ...$userRules,
            ],
        ];
    }

    public function validationAttributeNames(): array
    {
        return collect(['', '.present', '.known', '.known.*', '.keep', '.keep.*', '.upload', '.upload.*'])
            ->mapWithKeys(fn (string $suffix) => [$this->attribute.$suffix => $this->name])
            ->all();
    }

    /**
     * The "accept" attribute of the file input: the extensions and their mime types.
     */
    protected function accept(): ?string
    {
        if ($this->types === null) {
            return null;
        }

        $mimes = array_filter(array_map(fn (string $type) => FileTypes::mimeFor($type), $this->types));

        return implode(',', array_unique([...array_map(fn (string $type) => '.'.$type, $this->types), ...$mimes]));
    }

    /**
     * The PHP upload limits in bytes (null when unlimited) and the file count limit.
     *
     * @return array{file: int|null, request: int|null, count: int|null}
     */
    protected function uploadLimit(): array
    {
        $count = (int) ini_get('max_file_uploads');

        return [
            'file' => static::iniBytes((string) ini_get('upload_max_filesize')),
            'request' => static::iniBytes((string) ini_get('post_max_size')),
            'count' => $count > 0 ? $count : null,
        ];
    }

    /**
     * Convert a php.ini size ("2M", "512K", "1G", "0") to bytes; null when unlimited.
     */
    public static function iniBytes(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric(rtrim($value, 'kKmMgG'))) {
            return null;
        }

        $bytes = (int) rtrim($value, 'kKmMgG');

        $bytes *= match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $bytes > 0 ? $bytes : null;
    }

    public function jsonSerialize(): array
    {
        $limit = $this->uploadLimit();
        $maxSize = $this->maxSizeKilobytes() * 1024;

        return array_merge(parent::jsonSerialize(), [
            'context' => $this->context,
            'sortable' => false,
            'multiple' => $this->multiple,
            'removable' => $this->removable,
            'accept' => $this->accept(),
            'types' => $this->types,
            'maxSize' => $limit['file'] !== null ? min($maxSize, $limit['file']) : $maxSize,
            'maxFiles' => $this->maxFileCount(),
            'uploadLimit' => $limit,
            'files' => $this->resolvedFiles,
            'urlsExpireAt' => $this->urlsExpireAt,
            // Tells the form to send this field as files, also for subclasses with their own component key.
            'fileField' => true,
        ], $this->context === self::INDEX ? ['count' => $this->resolvedCount] : [], $this->meta);
    }
}
