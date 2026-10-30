<?php

namespace Maxcxam\Larsadmin\Files;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

/**
 * Type safety of uploads and served files (ADR-018).
 *
 * @internal
 */
class FileTypes
{
    /**
     * The raster image types served inline, by extension.
     */
    public const array RASTER = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    /**
     * Blocked on public disks even when removed from "blocked_extensions": the web server
     * serves them inline from the application's origin (stored XSS) or may execute them.
     */
    public const array PUBLIC_BLOCKED = [
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xht', 'xhtm', 'shtml', 'mht', 'mhtml',
        'xml', 'xsl', 'xslt', 'js', 'mjs', 'php', 'phtml', 'phar', 'htaccess',
    ];

    /**
     * Detected types the mime map has no extension for, but which must still be caught.
     */
    public const array SCRIPT_TYPES = [
        'text/x-php' => 'php',
        'application/x-php' => 'php',
        'application/x-httpd-php' => 'php',
        'text/x-shellscript' => 'sh',
        'application/x-shellscript' => 'sh',
    ];

    /**
     * The extension implied by the content of the upload (lower-case), or ''.
     */
    public static function detectedExtension(UploadedFile $file): string
    {
        return strtolower((string) ($file->guessExtension() ?? self::SCRIPT_TYPES[$file->getMimeType()] ?? ''));
    }

    /**
     * The blocked extensions (lower-case), for a field on a public or private disk.
     *
     * @return list<string>
     */
    public static function blocked(bool $public): array
    {
        $blocked = array_map(
            fn ($extension) => strtolower(ltrim((string) $extension, '.')),
            (array) config('larsadmin.files.blocked_extensions', []),
        );

        return array_values(array_unique($public ? [...$blocked, ...self::PUBLIC_BLOCKED] : $blocked));
    }

    /**
     * The first blocked extension of the upload, or null. Every dot segment after the first is
     * checked (Apache multi-extensions such as "x.php.jpg", ".htaccess"), both of the raw client
     * name and of the sanitized name that is stored and offered for download ("run.bat\"" is
     * stored as "run.bat"), with the trailing dots and spaces Windows drops removed; then the
     * extension guessed from the content.
     */
    public static function blockedExtension(UploadedFile $file, bool $public): ?string
    {
        $blocked = self::blocked($public);
        $raw = $file->getClientOriginalName();
        $segments = [];

        foreach (array_unique([mb_strtolower($raw), mb_strtolower(self::sanitizeName($raw))]) as $name) {
            array_push($segments, ...array_slice(explode('.', $name), 1));
        }

        foreach ([...$segments, self::detectedExtension($file)] as $extension) {
            $extension = rtrim(trim($extension), '. ');

            if ($extension !== '' && in_array($extension, $blocked, true)) {
                return $extension;
            }
        }

        return null;
    }

    /**
     * A validation rule rejecting uploads with a blocked extension.
     */
    public static function notBlocked(bool $public): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($public) {
            if ($value instanceof UploadedFile && ($extension = self::blockedExtension($value, $public)) !== null) {
                $fail('larsadmin::messages.file_blocked')->translate(['extension' => $extension]);
            }
        };
    }

    /**
     * The extension used for the stored name: guessed from the content, "bin" when unknown or blocked.
     */
    public static function storedExtension(UploadedFile $file, bool $public): string
    {
        $extension = self::detectedExtension($file);

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 && ! in_array($extension, self::blocked($public), true)
            ? $extension
            : 'bin';
    }

    /**
     * The inline Content-Type of a raster image extension, or null for every other type.
     */
    public static function rasterType(string $extension): ?string
    {
        return self::RASTER[strtolower($extension)] ?? null;
    }

    /**
     * The Content-Type a file may be served with: the raster type or "application/octet-stream".
     */
    public static function safeContentType(string $extension): string
    {
        return self::rasterType($extension) ?? 'application/octet-stream';
    }

    /**
     * The mime type commonly used for an extension (display only), or null.
     */
    public static function mimeFor(string $extension): ?string
    {
        return $extension === '' ? null : (MimeTypes::getDefault()->getMimeTypes(strtolower($extension))[0] ?? null);
    }

    /**
     * Clean a client file name for storage as metadata and use in Content-Disposition: the last
     * path segment, without control and format characters (bidi overrides, zero-width), quotes
     * or characters invalid in file names, at most 200 characters (the extension is kept).
     */
    public static function sanitizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = Str::afterLast($name, '/');

        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $name = preg_replace('/[\p{Cc}\p{Cf}"<>:*?|]+/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if (mb_strlen($name) > 200) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $extension = $extension !== '' && mb_strlen($extension) <= 10 ? '.'.$extension : '';
            $name = rtrim(mb_substr($name, 0, 200 - mb_strlen($extension))).$extension;
        }

        return $name;
    }

    /**
     * The ASCII fallback of a file name for Content-Disposition "filename".
     */
    public static function asciiName(string $name): string
    {
        $fallback = trim(preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '', Str::ascii($name)) ?? '');

        if ($fallback === '' || $fallback[0] === '.') {
            $extension = pathinfo($fallback, PATHINFO_EXTENSION);

            return 'download'.($extension !== '' ? '.'.$extension : '');
        }

        return $fallback;
    }
}
