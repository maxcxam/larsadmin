<?php

namespace Maxcxam\Larsadmin\Mcp;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Maxcxam\Larsadmin\Larsadmin;
use Throwable;

/**
 * When the local MCP server may run (ADR-037, Q-74): never in production; "larsadmin.mcp.enabled" false turns it
 * off, true allows any other environment, null (the default) allows only APP_ENV=local. The server registration,
 * the larsadmin:mcp command and every tool check it, so a direct "mcp:start larsadmin" or a config cached in
 * another environment cannot bypass it.
 *
 * @internal
 */
final class McpGate
{
    /**
     * The handle the server is registered under (Mcp::local) and started with (mcp:start).
     */
    public const string HANDLE = 'larsadmin';

    /**
     * The server class of laravel/mcp: the package is optional (suggest / require-dev).
     */
    public const string MCP_SERVER_CLASS = 'Laravel\\Mcp\\Server';

    /**
     * Test switch: pretend laravel/mcp is (true) or is not (false) installed; null detects it.
     */
    public static ?bool $mcpInstalled = null;

    /**
     * Whether laravel/mcp is installed.
     */
    public static function mcpInstalled(): bool
    {
        return self::$mcpInstalled ?? class_exists(self::MCP_SERVER_CLASS);
    }

    /**
     * Whether the server may run in this application.
     */
    public static function enabled(?Application $app = null): bool
    {
        return self::reason($app) === null;
    }

    /**
     * Why the server may not run in this application; null when it may.
     */
    public static function reason(?Application $app = null): ?string
    {
        $app ??= app();

        if (self::isProduction($app)) {
            return 'The larsadmin MCP server never runs in production (APP_ENV='.$app->environment().').';
        }

        $enabled = self::flag($app['config']->get('larsadmin.mcp.enabled'));

        if ($enabled === false) {
            return 'The larsadmin MCP server is turned off (larsadmin.mcp.enabled / LARSADMIN_MCP=false).';
        }

        if ($enabled === null && ! $app->environment('local')) {
            return 'The larsadmin MCP server runs only when APP_ENV=local; set LARSADMIN_MCP=true to run it in the '
                .'['.$app->environment().'] environment (never in production).';
        }

        return null;
    }

    /**
     * Production environment names: the server never runs there, whatever the flag says.
     */
    public const array PRODUCTION_ENVIRONMENTS = ['production', 'prod', 'live'];

    /**
     * Whether the environment is production: isProduction() or a common alias, case-insensitively.
     */
    public static function isProduction(Application $app): bool
    {
        return $app->isProduction()
            || in_array(strtolower(trim((string) $app->environment())), self::PRODUCTION_ENVIRONMENTS, true);
    }

    /**
     * The flag as true, false or null (auto). Booleans as strings ("true", "0", "off" ...) are accepted, anything
     * unrecognised counts as off: an unclear flag must not open the server.
     */
    public static function flag(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) && strtolower(trim($value)) === 'null') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * The installed package version (e.g. "1.6.0"), falling back to the extension API version.
     */
    public static function packageVersion(): string
    {
        try {
            if (InstalledVersions::isInstalled('maxcxam/larsadmin')) {
                $version = InstalledVersions::getPrettyVersion('maxcxam/larsadmin');

                if (is_string($version) && $version !== '') {
                    return $version;
                }
            }
        } catch (Throwable) {
            // No Composer runtime metadata: use the API version.
        }

        return Larsadmin::API_VERSION;
    }
}
