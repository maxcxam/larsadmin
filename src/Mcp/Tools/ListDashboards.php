<?php

namespace Maxcxam\Larsadmin\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Maxcxam\Larsadmin\Mcp\Introspection\ApplicationMetadata;
use Maxcxam\Larsadmin\Mcp\Introspection\IntrospectionTool;
use Maxcxam\Larsadmin\Mcp\Introspection\Metadata;

/**
 * list-dashboards (design §6.4): the registered dashboards in menu order with their cards (never calculated).
 *
 * @internal
 */
#[IsReadOnly]
#[IsIdempotent]
final class ListDashboards extends IntrospectionTool
{
    protected string $description = 'List the registered Larsadmin dashboards in menu order: uriKey, class, file, '
        .'name, navigation and home flags, and their cards (type, width, ranges, cache lifetime). Metrics are never '
        .'calculated.';

    protected function introspect(Request $request): Response|ResponseFactory
    {
        return $this->respond(['dashboards' => ApplicationMetadata::dashboards(Metadata::request())], ['dashboards']);
    }
}
