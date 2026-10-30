<?php

namespace Maxcxam\Larsadmin\Files;

/**
 * One file referenced by a File field: the path relative to the disk root plus display metadata.
 * The size and mime type are known for multiple() entries only (detected on the server at upload).
 */
class StoredFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly ?int $size = null,
        public readonly ?string $mime = null,
    ) {}

    /**
     * Read one stored entry tolerantly: a path string (legacy lists) or an array/object with a "path".
     * Entries without a safe path are dropped (null). Without a stored name, the name is
     * "{$fallbackStem}.{extension}" (e.g. the field label), else the base name of the path.
     */
    public static function fromStored(mixed $entry, ?string $fallbackStem = null): ?self
    {
        if (is_object($entry)) {
            $entry = get_object_vars($entry);
        }

        if (is_string($entry)) {
            $entry = ['path' => $entry];
        }

        if (! is_array($entry) || ! is_string($entry['path'] ?? null) || ! static::isSafePath($entry['path'])) {
            return null;
        }

        $name = is_string($entry['name'] ?? null) ? FileTypes::sanitizeName($entry['name']) : '';

        if ($name === '' && $fallbackStem !== null) {
            $extension = strtolower(pathinfo($entry['path'], PATHINFO_EXTENSION));
            $name = FileTypes::sanitizeName($fallbackStem.($extension !== '' ? '.'.$extension : ''));
        }

        return new static(
            $entry['path'],
            $name !== '' ? $name : basename($entry['path']),
            is_int($entry['size'] ?? null) ? $entry['size'] : null,
            is_string($entry['mime'] ?? null) ? $entry['mime'] : null,
        );
    }

    /**
     * A stored path is used only when it is relative and cannot leave the disk root:
     * non-empty, no leading slash or drive, no "..", no backslash, no control characters.
     */
    public static function isSafePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, ':')) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * The token identifying the file to the client: the first 32 hex characters of
     * HMAC-SHA256(app key, "larsadmin.file-token" NUL disk NUL path), domain-separated from the
     * other uses of the key. It never reveals the path and cannot be forged.
     */
    public function token(string $disk): string
    {
        return substr(hash_hmac('sha256', "larsadmin.file-token\0".$disk."\0".$this->path, (string) config('app.key')), 0, 32);
    }

    /**
     * The lower-case extension of the stored path.
     */
    public function extension(): string
    {
        return strtolower(pathinfo($this->path, PATHINFO_EXTENSION));
    }

    public function isRasterImage(): bool
    {
        return FileTypes::rasterType($this->extension()) !== null;
    }

    /**
     * The entry stored in a multiple() column.
     *
     * @return array{path: string, name: string, size: int|null, mime: string|null}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'size' => $this->size,
            'mime' => $this->mime,
        ];
    }
}
