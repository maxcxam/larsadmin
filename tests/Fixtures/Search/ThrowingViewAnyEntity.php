<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An opted-in entity whose viewAny check throws (a generic error, or an authorization error).
 */
class ThrowingViewAnyEntity extends KeyOnlyPost
{
    public static bool $authorizationError = false;

    public static function uriKey(): string
    {
        return 'throwing-view-any';
    }

    public static function authorizedToViewAny(Request $request): bool
    {
        throw static::$authorizationError ? new AuthorizationException : new RuntimeException('The gate failed.');
    }
}
