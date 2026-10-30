<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

use Illuminate\Http\Request;

/**
 * Opted in, but the user may not list it (viewAny false); its index query would crash.
 */
class DeniedProbe extends NonOptInProbe
{
    public static bool $globallySearchable = true;

    public static function uriKey(): string
    {
        return 'classified';
    }

    public static function label(): string
    {
        return 'Classified';
    }

    public static function authorizedToViewAny(Request $request): bool
    {
        return false;
    }
}
