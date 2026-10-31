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
 * list-extensions (design §6.4): the registered extensions (directory, built files, version, translation
 * groups) and tools (component, icon); Tool::props() is never called.
 *
 * @internal
 */
#[IsReadOnly]
#[IsIdempotent]
final class ListExtensions extends IntrospectionTool
{
    protected string $description = 'List the registered Larsadmin extensions (package name, directory, entry script, '
        .'stylesheet, version hash, translation groups) and tools (uriKey, class, file, name, component, icon).';

    protected function introspect(Request $request): Response|ResponseFactory
    {
        return $this->respond(ApplicationMetadata::extensions(), ['tools', 'extensions']);
    }
}
