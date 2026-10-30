<?php

namespace Maxcxam\Larsadmin\Actions;

use InvalidArgumentException;

/**
 * What an action asks the panel to do once it finished.
 */
final class ActionResponse
{
    public const string MESSAGE = 'message';

    public const string DANGER = 'danger';

    public const string REDIRECT = 'redirect';

    public const string DOWNLOAD = 'download';

    private function __construct(
        public readonly string $type,
        public readonly ?string $message = null,
        public readonly ?string $url = null,
        public readonly ?string $filename = null,
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
        $filename = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '', $filename) ?? '');

        return new self(self::DOWNLOAD, url: self::guard($url), filename: $filename !== '' ? $filename : 'download');
    }

    /**
     * @throws InvalidArgumentException when the URL is not local to the application
     */
    private static function guard(string $url): string
    {
        if (preg_match('#^/(?![/\\\\])[^\\\\\x00-\x20\x7F]*$#', $url) === 1) {
            return $url;
        }

        $parts = parse_url($url);

        if (
            preg_match('/[\\\\\x00-\x20\x7F]/', $url) !== 1
            && is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && ! isset($parts['user'])
            && isset($parts['host'])
            && strcasecmp($parts['host'], app('request')->getHost()) === 0
        ) {
            return $url;
        }

        throw new InvalidArgumentException('Action responses may only point at panel-internal URLs.');
    }
}
