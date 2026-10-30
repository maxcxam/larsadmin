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
    | cacheFor(); null uses the default store. Results are cached per user
    | (plus Metric::cacheKeyParts() and Larsadmin::metricCacheScopeUsing()).
    | "throttle" limits the metric endpoints to that many requests per minute
    | per user (per IP for guests); null disables the limit.
    |
    */

    'metrics' => [
        'cache_store' => null,
        'throttle' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Search
    |--------------------------------------------------------------------------
    |
    | Entities opt in with `public static bool $globallySearchable = true`.
    | "limit" is the number of results per entity (Entity::$globalSearchLimit
    | overrides it, both clamped to 1..20), "min_length" the shortest term that
    | is searched (digits-only terms are always searched, for ID lookups),
    | "throttle" the requests per minute per user (null disables it). Models
    | using Laravel Scout are searched through Scout: "scout_candidates" keys
    | are fetched from the engine before the entity's indexQuery() is applied.
    | After "time_budget_ms" the remaining entities are skipped (partial result).
    |
    */

    'search' => [
        'limit' => 5,
        'min_length' => 2,
        'throttle' => 60,
        'scout_candidates' => 50,
        'time_budget_ms' => 3000,
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

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    |
    | Defaults of the File and Image fields. "disk" is private unless its
    | filesystem config says "visibility" => "public"; private files get signed
    | URLs that expire after "url_ttl" minutes. Uploads are stored under
    | "{directory}/{entity}/{attribute}" with random names. "max_size" is in
    | kilobytes per file, "max_files" caps multiple() fields. With "prune" the
    | files replaced, removed or belonging to deleted records are deleted after
    | the database commit. "throttle" limits downloads per minute per user
    | (null disables it). Extensions in "blocked_extensions" are rejected in
    | every part of the client file name. The list is a backstop: prefer an
    | allow-list with ->types([...]) on every field. Markup, script and PHP types
    | (svg, html, xml, js, php, ...) stay blocked on public disks even when
    | removed from this list.
    |
    */

    'files' => [
        'disk' => env('LARSADMIN_FILES_DISK', 'local'),
        'directory' => 'larsadmin',
        'max_size' => 10240,
        'max_files' => 10,
        'prune' => true,
        'url_ttl' => 30,
        'throttle' => 600,
        'blocked_extensions' => [
            // server-side scripts
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phtm', 'phps', 'phar', 'pgif', 'inc',
            'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'asp', 'aspx', 'asa', 'asmx', 'ashx', 'cer', 'config',
            'jsp', 'jspx', 'cfm', 'cfc', 'shtml', 'shtm', 'stm',
            // Windows executables and script hosts
            'exe', 'dll', 'com', 'scr', 'pif', 'msi', 'msp', 'mst', 'msc', 'cpl', 'bat', 'cmd', 'hta', 'chm',
            'vbs', 'vbe', 'jse', 'wsf', 'wsh', 'ws', 'wsc', 'ps1', 'psm1', 'psd1', 'ps1xml', 'reg', 'inf', 'scf',
            'lnk', 'url', 'application', 'appref-ms', 'gadget', 'settingcontent-ms', 'jar', 'jnlp',
            // markup and scripts executed by browsers
            'html', 'htm', 'xhtml', 'xht', 'xhtm', 'mht', 'mhtml', 'svg', 'svgz', 'xml', 'xsl', 'xslt',
            'js', 'mjs', 'cjs', 'swf',
            // server configuration
            'htaccess', 'htpasswd', 'ini',
        ],
    ],

];
