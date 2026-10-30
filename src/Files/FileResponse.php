<?php

namespace Maxcxam\Larsadmin\Files;

use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The streamed response of a private file (ADR-018): inline only for raster images, every
 * other type as an octet-stream attachment, always with nosniff and a sandbox CSP.
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
        $inline = ! $download && $file->isRasterImage();
        $name = FileTypes::sanitizeName($file->name);
        $name = $name !== '' ? $name : basename($file->path);

        return $disk->response($file->path, $name, [
            'Content-Type' => $inline ? FileTypes::safeContentType($file->extension()) : 'application/octet-stream',
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
