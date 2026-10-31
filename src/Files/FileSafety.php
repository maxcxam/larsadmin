<?php

namespace Maxcxam\Larsadmin\Files;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The panel's file safety rules for extensions that store or serve files (ADR-018, ADR-041): the
 * same checks and headers the built-in File and Image fields use, so an extension does not keep a copy.
 *
 * Uploads: blocked extensions are checked by every dot segment of the client name and by the type
 * detected from the content; the stored name uses the detected extension. Serving: inline only for
 * raster images, everything else as an octet-stream attachment, always with nosniff and a sandbox CSP.
 *
 * @api stable since 1.6
 */
final class FileSafety
{
    private function __construct() {}

    /**
     * A validation rule: fails for blocked extensions, by name and by detected content (ADR-018).
     * Public disks also block markup and script types whatever "larsadmin.files.blocked_extensions" says.
     *
     * @return Closure(string, mixed, Closure): void
     */
    public static function rule(bool $publicDisk = false): Closure
    {
        return FileTypes::notBlocked($publicDisk);
    }

    /**
     * The first blocked extension of the upload (lower-case), or null when it may be stored.
     */
    public static function blockedExtension(UploadedFile $file, bool $publicDisk = false): ?string
    {
        return FileTypes::blockedExtension($file, $publicDisk);
    }

    /**
     * The extension to store the upload with: detected from the content, allow-listed, else "bin".
     * Never the client's extension.
     */
    public static function storedExtension(UploadedFile $file, bool $publicDisk = false): string
    {
        return FileTypes::storedExtension($file, $publicDisk);
    }

    /**
     * A client file name cleaned for storage as metadata and for Content-Disposition: the last path
     * segment, without control, format (bidi, zero-width) and reserved characters, at most 200 characters.
     * May return "" (use a fallback name then).
     */
    public static function sanitizeName(string $name): string
    {
        return FileTypes::sanitizeName($name);
    }

    /**
     * The ASCII fallback of a file name for the Content-Disposition "filename" parameter.
     */
    public static function asciiName(string $name): string
    {
        return FileTypes::asciiName($name);
    }

    /**
     * Whether a file may be shown inline as an image: its extension is a raster type (jpg, jpeg, png,
     * gif, webp, avif; never SVG) and, when a mime type is given, that type is a raster type too.
     */
    public static function isRasterImage(string $path, ?string $mime = null): bool
    {
        if (FileTypes::rasterType(pathinfo($path, PATHINFO_EXTENSION)) === null) {
            return false;
        }

        return $mime === null || in_array($mime, FileTypes::RASTER, true);
    }

    /**
     * A streamed response with the panel's headers: inline only for raster images (by the path's
     * extension) unless $download, else an octet-stream attachment; nosniff, sandbox CSP, CORP
     * same-origin, no-referrer and a private cache (inline images for $ttlMinutes, default
     * "larsadmin.files.url_ttl"; attachments are never stored). The name defaults to the path's base name.
     * Authorize the request before calling this.
     *
     * @throws InvalidArgumentException when $ttlMinutes is below 1
     */
    public static function response(
        FilesystemAdapter $disk,
        string $path,
        ?string $name = null,
        bool $download = false,
        ?int $ttlMinutes = null,
    ): StreamedResponse {
        return FileResponse::stream($disk, $path, $name, $download, $ttlMinutes);
    }
}
