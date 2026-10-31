<?php

namespace Maxcxam\Larsadmin\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Maxcxam\Larsadmin\Mcp\Introspection\ApplicationMetadata;
use Maxcxam\Larsadmin\Mcp\Introspection\IntrospectionTool;

/**
 * application-info (design §6.3): versions, the panel configuration (allow-listed keys only), features,
 * registration counts and where the application's Larsadmin code lives.
 *
 * @internal
 */
#[IsReadOnly]
#[IsIdempotent]
final class ApplicationInfo extends IntrospectionTool
{
    protected string $description = 'Larsadmin, Laravel and PHP versions, the panel configuration (path, guard, locales, '
        .'timezone; never secrets), enabled features, how many entities, dashboards, tools and extensions are '
        .'registered, and where the application provider and entities directory are.';

    protected function introspect(Request $request): Response|ResponseFactory
    {
        return $this->respond(ApplicationMetadata::info());
    }
}
