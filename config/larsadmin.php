<?php

use Maxcxam\Larsadmin\Http\Middleware\Authenticate;
use Maxcxam\Larsadmin\Http\Middleware\Authorize;
use Maxcxam\Larsadmin\Http\Middleware\HandleInertiaRequests;

return [

    /*
    |--------------------------------------------------------------------------
    | Panel Name
    |--------------------------------------------------------------------------
    |
    | The name displayed in the admin panel header and browser title.
    |
    */

    'name' => env('LARSADMIN_NAME', env('APP_NAME', 'Laravel').' Admin'),

    /*
    |--------------------------------------------------------------------------
    | Domain & Path
    |--------------------------------------------------------------------------
    |
    | The URI path (and optionally the domain) the admin panel is served from.
    |
    */

    'domain' => env('LARSADMIN_DOMAIN'),

    'path' => env('LARSADMIN_PATH', 'admin'),

    /*
    |--------------------------------------------------------------------------
    | Authentication Guard
    |--------------------------------------------------------------------------
    |
    | The guard used to authenticate admin users. Null uses the default guard.
    |
    */

    'guard' => env('LARSADMIN_GUARD'),

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | The default locale of the panel (applied to panel routes only). Null keeps
    | the application locale. "locales" lists the languages users can switch to
    | (code => label); the choice is remembered in the session.
    |
    */

    'locale' => env('LARSADMIN_LOCALE', 'uk'),

    'locales' => [
        'uk' => 'Українська',
        'en' => 'English',
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | The timezone metrics (today, month to date, trend buckets) are aligned to.
    | Null uses the "app.timezone" setting; Larsadmin::timezoneUsing() can override
    | it per request, e.g. with a per-user preference.
    |
    */

    'timezone' => env('LARSADMIN_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | "cache_store" is the cache store used by metrics that opt in with
    | cacheFor(); null uses the default store. Results are cached per user.
    |
    */

    'metrics' => [
        'cache_store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | "middleware" is applied to every panel route, including the login page.
    | "auth_middleware" is applied to every route behind the login screen.
    |
    */

    'middleware' => [
        'web',
        HandleInertiaRequests::class,
    ],

    'auth_middleware' => [
        Authenticate::class,
        Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allow Without Policy
    |--------------------------------------------------------------------------
    |
    | Models that have a registered policy are always authorized through the
    | gate (a missing policy method means denied). This option decides what
    | happens for models WITHOUT a policy: true allows, false denies.
    |
    */

    'allow_without_policy' => true,

    /*
    |--------------------------------------------------------------------------
    | Allow Local Environment
    |--------------------------------------------------------------------------
    |
    | WARNING: when true and APP_ENV=local, every authenticated user can access
    | the panel without passing the "viewLarsadmin" gate. Keep this off (or never
    | use APP_ENV=local) on any shared or internet-reachable instance.
    |
    */

    'allow_local' => env('LARSADMIN_ALLOW_LOCAL', true),

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    |
    | "max_all" caps how many records an action may run on when the user selects
    | "all matching" records (synchronous, chunked); above it the request fails
    | validation and the user must narrow the search or filters.
    |
    */

    'actions' => [
        'max_all' => 1000,
    ],

];
