<?php

namespace Maxcxam\Larsadmin\Files;

use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The streamed response of a private file (ADR-018): inline only for raster images, every
 * other type as an octet-stream attachment, always with nosniff and a sandbox CSP. Extensions use
 * FileSafety::response().
 *
 * @internal
 */
class FileResponse
{
    public const string CONTENT_SECURITY_POLICY = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";

    /**
     * Inline images may be cached privately for the URL lifetime (thumbnails); attachments are
     * never stored, so no copy outlives a logout or revoked access.
     */
    public static function cacheControl(bool $inline, ?int $ttlMinutes = null): string
    {
        $ttlMinutes ??= max(1, (int) config('larsadmin.files.url_ttl', 30));

        return $inline ? 'private, max-age='.($ttlMinutes * 60) : 'private, no-store';
    }

    public static function make(FilesystemAdapter $disk, StoredFile $file, bool $download, int $ttlMinutes): StreamedResponse
    {
        return self::stream($disk, $file->path, $file->name, $download, $ttlMinutes);
    }

    /**
     * The response for a path on the disk, behind FileSafety::response(). Inline only when not a
     * download and the path's extension is a raster image type; the name is sanitized and falls back
     * to the path's base name. A TTL below 1 minute is an InvalidArgumentException.
     */
    public static function stream(FilesystemAdapter $disk, string $path, ?string $name = null, bool $download = false, ?int $ttlMinutes = null): StreamedResponse
    {
        if ($ttlMinutes !== null && $ttlMinutes < 1) {
            throw new InvalidArgumentException("The cache lifetime must be at least 1 minute, [{$ttlMinutes}] given.");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $inline = ! $download && FileTypes::rasterType($extension) !== null;
        $name = FileTypes::sanitizeName($name ?? basename($path));
        $name = $name !== '' ? $name : basename($path);

        return $disk->response($path, $name, [
            'Content-Type' => $inline ? FileTypes::safeContentType($extension) : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                FileTypes::asciiName($name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => self::cacheControl($inline, $ttlMinutes),
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
