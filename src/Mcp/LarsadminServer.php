<?php

namespace Maxcxam\Larsadmin\Mcp;

use Laravel\Mcp\Server;

/**
 * The local (stdio) MCP server "larsadmin" for AI coding assistants (ADR-037). It extends laravel/mcp, so it is
 * referenced only after class_exists() checks and never autoloaded without that package.
 *
 * @internal
 */
final class LarsadminServer extends Server
{
    /**
     * The tools of the server, in the order clients list them. A tool class that does not exist (yet) is skipped,
     * so each tool is enabled by adding its class under src/Mcp/Tools/ with exactly this name.
     *
     * @var list<class-string>
     */
    public const array TOOLS = [
        Tools\SearchDocs::class,
        Tools\ReadDocs::class,
        Tools\ApplicationInfo::class,
        Tools\ListEntities::class,
        Tools\DescribeEntity::class,
        Tools\ListDashboards::class,
        Tools\ListExtensions::class,
        Tools\Generate::class,
    ];

    protected string $name = 'Larsadmin';

    protected string $version = '1.6';

    protected string $instructions = 'Larsadmin admin panel for Laravel: search the bundled guides, inspect the '
        .'registered entities, fields, actions, filters, metrics, dashboards, tools and extensions (metadata only, '
        .'never record data), and generate Larsadmin classes with the package generators. Read-only unless '
        .'`generate` is called with `write: true`.';

    /**
     * Resolve the version and the tools when the server starts; with the gate closed it offers no tools at all.
     */
    protected function boot(): void
    {
        $this->version = McpGate::packageVersion();
        $this->tools = self::toolClasses();
    }

    /**
     * The tool classes the server offers now: the existing classes of TOOLS, none while the gate is closed.
     *
     * @return list<class-string>
     */
    public static function toolClasses(): array
    {
        if (! McpGate::enabled()) {
            return [];
        }

        return array_values(array_filter(self::TOOLS, fn (string $tool) => class_exists($tool)));
    }
}
