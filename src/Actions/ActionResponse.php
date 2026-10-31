<?php

namespace Maxcxam\Larsadmin\Actions;

use InvalidArgumentException;
use Maxcxam\Larsadmin\Files\FileTypes;
use Maxcxam\Larsadmin\Files\StoredFile;
use Maxcxam\Larsadmin\Support\PanelUrl;

/**
 * What an action asks the panel to do once it finished.
 */
final class ActionResponse
{
    public const string MESSAGE = 'message';

    public const string DANGER = 'danger';

    public const string REDIRECT = 'redirect';

    public const string DOWNLOAD = 'download';

    public const string FILE = 'file';

    private function __construct(
        public readonly string $type,
        public readonly ?string $message = null,
        public readonly ?string $url = null,
        public readonly ?string $filename = null,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
    ) {}

    /**
     * Redirect back with a success toast.
     */
    public static function message(string $message): self
    {
        return new self(self::MESSAGE, $message);
    }

    /**
     * Redirect back with an error toast.
     */
    public static function danger(string $message): self
    {
        return new self(self::DANGER, $message);
    }

    /**
     * Redirect to a panel-internal URL (relative path, or an absolute URL on the current host).
     */
    public static function redirect(string $url): self
    {
        return new self(self::REDIRECT, url: self::guard($url));
    }

    /**
     * Ask the browser to download a file (same restrictions as redirect()).
     */
    public static function download(string $url, string $filename): self
    {
        return new self(self::DOWNLOAD, url: self::guard($url), filename: self::filename($filename, 'download'));
    }

    /**
     * Let the panel serve a file from a disk as a download (attachment, safe content type). The path must be
     * relative, without "..". In a queued run the panel copies the file to "larsadmin/runs/{run}/{filename}" on
     * "larsadmin.files.disk" and serves that copy, which is deleted together with the run
     * (larsadmin:prune-notifications); the original is left alone, so delete a temporary export yourself and use
     * unique names (a fixed path is shared by every user's run). In a synchronous run the browser gets a signed, user-bound URL
     * valid for "larsadmin.files.url_ttl" minutes; the file is left in place.
     *
     * @throws InvalidArgumentException when the disk is not configured or the path is not a safe relative path
     */
    public static function file(string $disk, string $path, ?string $filename = null): self
    {
        if (! is_array(config("filesystems.disks.{$disk}"))) {
            throw new InvalidArgumentException("The disk [{$disk}] is not configured.");
        }

        if (! StoredFile::isSafePath($path)) {
            throw new InvalidArgumentException('File responses need a relative path without "..".');
        }

        return new self(self::FILE, filename: self::filename($filename ?? basename($path), basename($path)), disk: $disk, path: $path);
    }

    /**
     * The file name without control characters and path separators (the fallback when nothing is left).
     */
    private static function filename(string $filename, string $fallback): string
    {
        $filename = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '', $filename) ?? '');

        if ($filename === '') {
            $filename = trim(FileTypes::sanitizeName($fallback));
        }

        return $filename !== '' ? $filename : 'download';
    }

    /**
     * @throws InvalidArgumentException when the URL is not local to the application
     */
    private static function guard(string $url): string
    {
        return PanelUrl::guard($url, app('request')->getHost());
    }
}
