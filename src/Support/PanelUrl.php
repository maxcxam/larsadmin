<?php

namespace Maxcxam\Larsadmin\Support;

use InvalidArgumentException;

/**
 * The rule for URLs the panel follows on behalf of an action response or a notification: a relative path
 * ("/admin/...", never "//host" or "/\host") or an absolute http(s) URL on one of the allowed hosts,
 * without credentials, whitespace, control characters or backslashes.
 *
 * @internal Not part of the public API.
 */
final class PanelUrl
{
    /**
     * @param  string|list<string>  $hosts  the allowed hosts
     *
     * @throws InvalidArgumentException when the URL is not local to the application
     */
    public static function guard(string $url, string|array $hosts): string
    {
        if (self::isSafe($url, $hosts)) {
            return $url;
        }

        throw new InvalidArgumentException('Action responses may only point at panel-internal URLs.');
    }

    /**
     * @param  string|list<string>  $hosts  the allowed hosts
     */
    public static function isSafe(?string $url, string|array $hosts): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        if (preg_match('#^/(?![/\\\\])[^\\\\\x00-\x20\x7F]*\z#', $url) === 1) {
            return true;
        }

        $parts = parse_url($url);

        if (
            preg_match('/[\\\\\x00-\x20\x7F]/', $url) === 1
            || ! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || isset($parts['user'])
            || ! isset($parts['host'])
        ) {
            return false;
        }

        foreach ((array) $hosts as $host) {
            if (is_string($host) && $host !== '' && strcasecmp($parts['host'], $host) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hosts a notification URL may point at: the current request's host, the APP_URL host and the
     * panel domain.
     *
     * @return list<string>
     */
    public static function applicationHosts(): array
    {
        $hosts = [];

        if (app()->bound('request')) {
            $hosts[] = app('request')->getHost();
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($appHost)) {
            $hosts[] = $appHost;
        }

        $domain = config('larsadmin.domain');

        if (is_string($domain) && $domain !== '') {
            $hosts[] = $domain;
        }

        return array_values(array_unique(array_filter($hosts, fn ($host) => is_string($host) && $host !== '')));
    }
}
